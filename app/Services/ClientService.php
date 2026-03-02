<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Company;
use App\Models\User;

class ClientService
{
    public function __construct(protected User $user) {}

    // ── Company resolution ─────────────────────────────────────────────────

    public function getCompany(): ?Company
    {
        return $this->user->companies()->first();
    }

    // ── Client resolution ──────────────────────────────────────────────────

    /**
     * Resolve a single client by ID or name fragment.
     *
     * @return Client|array  A Client on success, or an error payload array on failure.
     */
    public function resolveClient(Company $company, ?int $clientId, ?string $name): Client|array
    {
        if ($clientId) {
            $client = $company->clients()->find($clientId);

            return $client ?? ['success' => false, 'message' => 'No client found with that ID.'];
        }

        if ($name) {
            $matches = $company->clients()
                ->where('name', 'like', '%' . $name . '%')
                ->get();

            if ($matches->isEmpty()) {
                return ['success' => false, 'message' => "No client found matching \"{$name}\"."];
            }

            if ($matches->count() > 1) {
                $names = $matches->pluck('name')->implode('", "');
                return [
                    'success' => false,
                    'message' => "Multiple clients match \"{$name}\": \"{$names}\". Please be more specific or provide a client ID.",
                ];
            }

            return $matches->first();
        }

        return ['success' => false, 'message' => 'Provide either client_id or name.'];
    }

    // ── CRUD operations ────────────────────────────────────────────────────

    public function list(
        Company $company,
        ?string $search = null,
        ?bool $isActive = null,
        int $page = 1,
        int $perPage = 20,
    ): array {
        $query = $company->clients();

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($isActive !== null) {
            $query->where('is_active', $isActive);
        }

        $perPage = min($perPage, 50);
        $total   = $query->count();
        $clients = $query
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get(['id', 'name', 'email', 'phone', 'city', 'state', 'gst_number', 'currency', 'is_active']);

        return [
            'success'  => true,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'clients'  => $clients->toArray(),
        ];
    }

    public function detail(Client $client): array
    {
        $recentInvoices = $client->invoices()
            ->latest()
            ->limit(5)
            ->get(['id', 'invoice_number', 'invoice_date', 'due_date', 'amount_due', 'status'])
            ->toArray();

        return [
            'success' => true,
            'client'  => [
                'id'                => $client->id,
                'name'              => $client->name,
                'email'             => $client->email,
                'phone'             => $client->phone,
                'gst_number'        => $client->gst_number,
                'pan_number'        => $client->pan_number,
                'gst_type'          => $client->gst_type,
                'address'           => $client->address,
                'city'              => $client->city,
                'state'             => $client->state,
                'state_code'        => $client->state_code,
                'pincode'           => $client->pincode,
                'country'           => $client->country,
                'currency'          => $client->currency,
                'payment_terms'     => $client->payment_terms,
                'credit_limit'      => $client->credit_limit,
                'total_outstanding' => $client->total_outstanding,
                'notes'             => $client->notes,
                'is_active'         => $client->is_active,
                'recent_invoices'   => $recentInvoices,
            ],
        ];
    }

    public function create(Company $company, array $data): array
    {
        $duplicate = $company->clients()->where('name', $data['name'])->exists();

        if ($duplicate) {
            return [
                'success' => false,
                'message' => "A client named \"{$data['name']}\" already exists.",
            ];
        }

        $client = $company->clients()->create([
            'name'          => $data['name'],
            'state'         => $data['state'],
            'state_code'    => $data['state_code'],
            'email'         => $data['email']         ?? null,
            'phone'         => $data['phone']         ?? null,
            'gst_number'    => $data['gst_number']    ?? null,
            'pan_number'    => $data['pan_number']    ?? null,
            'gst_type'      => $data['gst_type']      ?? 'regular',
            'address'       => $data['address']       ?? null,
            'city'          => $data['city']          ?? null,
            'pincode'       => $data['pincode']       ?? null,
            'country'       => $data['country']       ?? 'India',
            'currency'      => $data['currency']      ?? 'INR',
            'payment_terms' => $data['payment_terms'] ?? null,
            'credit_limit'  => $data['credit_limit']  ?? null,
            'notes'         => $data['notes']         ?? null,
            'is_active'     => true,
        ]);

        return [
            'success'     => true,
            'message'     => "Client \"{$client->name}\" created successfully.",
            'client_id'   => $client->id,
            'client_name' => $client->name,
        ];
    }

    public function update(Client $client, array $data): array
    {
        $updates = collect([
            'name', 'email', 'phone',
            'gst_number', 'pan_number', 'gst_type',
            'address', 'city', 'state', 'state_code', 'pincode', 'country',
            'currency', 'payment_terms', 'credit_limit',
            'notes', 'is_active',
        ])
            ->filter(fn ($field) => array_key_exists($field, $data))
            ->mapWithKeys(fn ($field) => [$field => $data[$field]])
            ->all();

        if (empty($updates)) {
            return ['success' => false, 'message' => 'No fields provided to update.'];
        }

        $client->update($updates);

        return [
            'success'        => true,
            'message'        => "Client \"{$client->name}\" updated successfully.",
            'updated_fields' => array_keys($updates),
        ];
    }

    public function delete(Client $client, bool $force = false): array
    {
        $outstanding = $client->invoices()
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->count();

        if ($outstanding > 0 && ! $force) {
            return [
                'success'               => false,
                'requires_confirmation' => true,
                'outstanding_count'     => $outstanding,
                'message'               => "Client \"{$client->name}\" has {$outstanding} unpaid invoice(s). "
                    . 'Set force=true to delete anyway.',
            ];
        }

        $name = $client->name;
        $client->delete();

        return [
            'success' => true,
            'message' => "Client \"{$name}\" has been deleted successfully.",
        ];
    }
}
