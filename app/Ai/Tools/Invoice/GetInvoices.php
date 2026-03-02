<?php

namespace App\Ai\Tools\Invoice;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetInvoices implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'List invoices for the user\'s company with optional filters for status, client, date range, and overdue. Returns paginated results. To get a download link for a specific invoice\'s PDF, call generate_invoice_pdf with the invoice_number.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $query = Invoice::forCompany($company->id)->with('client:id,name');

        if (! empty($request['status'])) {
            $query->status($request['status']);
        }

        if (! empty($request['client_name'])) {
            $query->where('client_name', 'like', '%' . $request['client_name'] . '%');
        }

        if (! empty($request['client_id'])) {
            $query->forClient($request['client_id']);
        }

        if (! empty($request['from_date'])) {
            $query->where('invoice_date', '>=', $request['from_date']);
        }

        if (! empty($request['to_date'])) {
            $query->where('invoice_date', '<=', $request['to_date']);
        }

        if (! empty($request['overdue_only'])) {
            $query->overdue();
        }

        $perPage = min((int) ($request['per_page'] ?? 15), 50);
        $page    = max((int) ($request['page']     ?? 1), 1);

        $total    = $query->count();
        $invoices = $query->orderByDesc('invoice_date')
            ->skip(($page - 1) * $perPage)
            ->take($perPage)
            ->get([
                'id', 'invoice_number', 'client_id', 'client_name',
                'invoice_date', 'due_date', 'status', 'supply_type',
                'total_amount', 'amount_paid', 'amount_due', 'currency', 'pdf_path',
            ])
            ->map(fn($inv) => [
                'id'             => $inv->id,
                'invoice_number' => $inv->invoice_number ?? '(Draft)',
                'client'         => $inv->client_name,
                'invoice_date'   => $inv->invoice_date->toDateString(),
                'due_date'       => $inv->due_date?->toDateString(),
                'status'         => $inv->status,
                'is_overdue'     => $inv->isOverdue(),
                'total'          => (float) $inv->total_amount,
                'paid'           => (float) $inv->amount_paid,
                'due'            => (float) $inv->amount_due,
                'currency'       => $inv->currency,

                // FIX 1: Removed raw pdf_path — it's an internal storage path that the LLM
                //         has no use for and should not be exposed.
                //
                // FIX 2: Removed bulk eager URL generation. Previously this generated a signed
                //         URL for every invoice on every list call (50 crypto operations per page).
                //         The LLM already knows to call generate_invoice_pdf when a link is needed.
                //         We only surface a boolean so the agent knows a PDF exists.
                'has_pdf' => ! empty($inv->pdf_path),

                // FIX 3: URL strategy was wrong — GetInvoices used URL::temporarySignedRoute()
                //         with a plain `path` param, but InvoiceDownloadController expected
                //         an encrypted `token`. Both are now standardised on encrypted token
                //         via InvoicePdfService. Call generate_invoice_pdf to get a fresh URL.
            ])
            ->toArray();

        return json_encode([
            'success'  => true,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'invoices' => $invoices,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['draft', 'sent', 'paid', 'partial', 'cancelled', 'overdue'])
                ->description('Filter by invoice status.'),

            'client_name' => $schema->string()
                ->description('Partial client name search.'),

            'client_id' => $schema->integer()
                ->description('Filter by specific client ID.'),

            'from_date' => $schema->string()
                ->description('Start of date range (YYYY-MM-DD).'),

            'to_date' => $schema->string()
                ->description('End of date range (YYYY-MM-DD).'),

            'overdue_only' => $schema->boolean()
                ->description('Return only overdue invoices.'),

            'page'     => $schema->integer()->min(1),
            'per_page' => $schema->integer()->min(1)->max(50),
        ];
    }
}
