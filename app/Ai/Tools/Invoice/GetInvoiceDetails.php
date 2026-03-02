<?php

namespace App\Ai\Tools\Invoice;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetInvoiceDetails implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return 'Get full details of a single invoice including all line items, tax breakdown, and payment status. To get a downloadable PDF link, call generate_invoice_pdf with the invoice_number. Accepts either a numeric invoice ID or an invoice number string like INV-20240101-00042.';
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        $ref     = $request['invoice_ref'];
        $invoice = Invoice::forCompany($company->id)
            ->resolveByRef($ref)
            ->with('lineItems')
            ->first();

        if (! $invoice) {
            return json_encode(['success' => false, 'message' => "Invoice '{$ref}' not found."]);
        }

        $lineItems = $invoice->lineItems->map(fn($li) => [
            'description'    => $li->description,
            'hsn_code'       => $li->hsn_code,
            'unit'           => $li->unit,
            'quantity'       => (float) $li->quantity,
            'rate'           => (float) $li->rate,
            'discount'       => (float) $li->discount_percent . '%',
            'taxable_amount' => (float) $li->amount,
            'gst_rate'       => (float) $li->gst_rate . '%',
            'cgst_amount'    => (float) $li->cgst_amount,
            'sgst_amount'    => (float) $li->sgst_amount,
            'igst_amount'    => (float) $li->igst_amount,
            'total_amount'   => (float) $li->total_amount,
        ])->toArray();

        return json_encode([
            'success' => true,
            'invoice' => [
                'id'             => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'status'         => $invoice->status,
                'is_overdue'     => $invoice->isOverdue(),
                'supply_type'    => $invoice->supply_type,
                'invoice_date'   => $invoice->invoice_date->toDateString(),
                'due_date'       => $invoice->due_date?->toDateString(),
                'payment_terms'  => $invoice->payment_terms,
                'seller'         => [
                    'name'       => $invoice->company_name,
                    'gst_number' => $invoice->company_gst_number,
                    'state'      => $invoice->company_state,
                    'state_code' => $invoice->company_state_code,
                ],
                'buyer'          => [
                    'name'       => $invoice->client_name,
                    'email'      => $invoice->client_email,
                    'address'    => $invoice->client_address,
                    'gst_number' => $invoice->client_gst_number,
                    'state'      => $invoice->client_state,
                    'state_code' => $invoice->client_state_code,
                ],
                'line_items'     => $lineItems,
                'totals'         => [
                    'subtotal'    => (float) $invoice->subtotal,
                    'discount'    => (float) $invoice->discount_amount,
                    'cgst'        => (float) $invoice->cgst_amount,
                    'sgst'        => (float) $invoice->sgst_amount,
                    'igst'        => (float) $invoice->igst_amount,
                    'gst_total'   => (float) $invoice->gst_amount,
                    'grand_total' => (float) $invoice->total_amount,
                    'amount_paid' => (float) $invoice->amount_paid,
                    'amount_due'  => (float) $invoice->amount_due,
                ],
                'bank_details'   => [
                    'account_name'   => $invoice->bank_account_name,
                    'account_number' => $invoice->bank_account_number,
                    'ifsc'           => $invoice->bank_ifsc_code,
                ],
                'notes'                => $invoice->notes,
                'terms_and_conditions' => $invoice->terms_and_conditions,
                // FIX: Removed the raw pdf_path field. Call generate_invoice_pdf to get a fresh URL.
                'has_pdf' => ! empty($invoice->pdf_path),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_ref' => $schema->string()
                ->required()
                ->description(
                    'Invoice number (e.g. INV-20240101-00042) or numeric invoice ID. '
                    . 'Always use the invoice number when the user provides one.'
                ),
        ];
    }
}
