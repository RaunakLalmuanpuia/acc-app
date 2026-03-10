<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateInvoiceTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Create a new DRAFT invoice for a client. Always call LookupClient first to get the correct client_id. Returns the new invoice_id — save it for all subsequent operations in this conversation. The invoice starts with no line items; add them using AddLineItem.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'client_id' => $schema->integer()
                ->description('Numeric ID of the client returned by LookupClient.')
                ->required(),

            'invoice_date' => $schema->string()
                ->description('Invoice date in YYYY-MM-DD format. Defaults to today if not specified.')
                ->required(),

            'due_date' => $schema->string()
                ->description('Payment due date in YYYY-MM-DD format. Optional.'),

            'payment_terms' => $schema->string()
                ->description('Payment terms text, e.g. "Net 30" or "Due on receipt". Inherits from client if omitted.'),

            'invoice_type' => $schema->string()
                ->description('One of: tax_invoice, proforma, credit_note, debit_note. Defaults to tax_invoice.')
                ->enum(['tax_invoice', 'proforma', 'credit_note', 'debit_note']),

            'currency' => $schema->string()
                ->description('ISO currency code. Defaults to INR.'),

            'notes' => $schema->string()
                ->description('Optional notes visible on the invoice.'),

            'terms_and_conditions' => $schema->string()
                ->description('Optional terms and conditions text.'),

            'force_new' => $schema->boolean()
                ->description(
                    'Set true when the user explicitly asked for a new or separate invoice ' .
                    'and a draft for this client already exists. Bypasses draft resumption ' .
                    'and always creates a fresh invoice. Default false = resume existing draft.'
                ),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);

            $invoice = $service->createDraftInvoice(
                clientId:           $request['client_id'],
                invoiceDate:        $request['invoice_date'],
                dueDate:            $request['due_date']             ?? null,
                paymentTerms:       $request['payment_terms']        ?? null,
                notes:              $request['notes']                ?? null,
                termsAndConditions: $request['terms_and_conditions'] ?? null,
                invoiceType:        $request['invoice_type']         ?? 'tax_invoice',
                currency:           $request['currency']             ?? 'INR',
                forceNew:           (bool) ($request['force_new']    ?? false),  // ← was missing
            );

            $resumed = $invoice['_resumed'] ?? false;

            return json_encode([
                'success'        => true,
                'invoice_id'     => $invoice['id'],
                'invoice_number' => $invoice['invoice_number'],
                'supply_type'    => $invoice['supply_type'],
                'status'         => $invoice['status'],
                'company_name'   => $invoice['company_name'] ?? null,
                '_resumed'       => $resumed,
                'message'        => $resumed
                    ? "Resumed existing draft {$invoice['invoice_number']} (invoice_id={$invoice['id']})."
                    : "Draft invoice {$invoice['invoice_number']} created. invoice_id={$invoice['id']}. Now add line items using AddLineItem.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
