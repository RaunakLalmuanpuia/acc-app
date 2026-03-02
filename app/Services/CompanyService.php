<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;

class CompanyService
{
    public function __construct(protected User $user) {}

    public function getCompany(): ?Company
    {
        return $this->user->companies()->first();
    }

    public function hasCompany(): bool
    {
        return $this->user->companies()->exists();
    }

    public function createCompany(array $data): Company
    {
        // Separate bank account fields from company fields
        $bankFields = ['bank_name', 'account_name', 'account_number', 'ifsc_code', 'branch', 'account_type', 'currency', 'opening_balance', 'opening_balance_date'];

        $bankData = [];
        foreach ($bankFields as $field) {
            if (isset($data[$field])) {
                $bankData[$field] = $data[$field];
                unset($data[$field]);
            }
        }

        $company = $this->user->companies()->create($data);

        // If bank account details were provided, create a BankAccount record
        if (!empty($bankData['account_number']) && !empty($bankData['bank_name'])) {
            $bankData['is_primary'] = true;
            $bankData['is_active']  = true;
            $bankData['account_name'] = $bankData['account_name'] ?? $data['company_name'];
            $company->bankAccounts()->create($bankData);
        }

        return $company;
    }

    public function updateCompany(Company $company, array $data): bool
    {
        return $company->update($data);
    }

    public function getUpdatableFields(): array
    {
        return [
            'company_name', 'gst_number', 'pan_number',
            'state', 'state_code', 'address', 'city', 'pincode', 'country',
            'email', 'phone', 'website',
            'bank_account_name', 'bank_account_number', 'bank_ifsc_code',
            'bank_name', 'bank_branch', 'invoice_footer_note', 'is_active',
        ];
    }

    public function extractUpdates(array $input): array
    {
        $updates = [];
        foreach ($this->getUpdatableFields() as $field) {
            if (array_key_exists($field, $input)) {
                $updates[$field] = $input[$field];
            }
        }
        return $updates;
    }

    public function formatCompany(Company $company): array
    {
        return [
            'company_name'        => $company->company_name,
            'gst_number'          => $company->gst_number,
            'pan_number'          => $company->pan_number,
            'state'               => $company->state,
            'state_code'          => $company->state_code,
            'address'             => $company->address,
            'city'                => $company->city,
            'pincode'             => $company->pincode,
            'country'             => $company->country,
            'email'               => $company->email,
            'phone'               => $company->phone,
            'website'             => $company->website,
            'bank_account_name'   => $company->bank_account_name,
            'bank_account_number' => $company->bank_account_number,
            'bank_ifsc_code'      => $company->bank_ifsc_code,
            'bank_name'           => $company->bank_name,
            'bank_branch'         => $company->bank_branch,
            'invoice_footer_note' => $company->invoice_footer_note,
            'is_active'           => $company->is_active,
            'created_at'          => $company->created_at?->toDateString(),
            'bank_accounts'       => $company->bankAccounts->map(fn($b) => [
                'id'              => $b->id,
                'account_name'    => $b->account_name,
                'bank_name'       => $b->bank_name,
                'account_number'  => $b->account_number,
                'ifsc_code'       => $b->ifsc_code,
                'branch'          => $b->branch,
                'account_type'    => $b->account_type,
                'opening_balance' => $b->opening_balance,
                'current_balance' => $b->current_balance,
                'is_primary'      => $b->is_primary,
            ])->toArray(),
        ];
    }
}
