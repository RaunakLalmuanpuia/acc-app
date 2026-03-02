<?php

namespace App\Ai\Tools\Invoice;

use App\Models\Invoice;
use App\Models\InvoiceLineItem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Creates a draft invoice (status = 'draft').
 * The agent should present a formatted summary to the user and ask them
 * to confirm before calling ConfirmInvoice to finalise.
 */
class CreateInvoiceDraft implements Tool
{
    public function __construct(protected User $user) {}

    public function description(): Stringable|string
    {
        return <<<'TEXT'
        Create a DRAFT invoice for a client.
        The invoice is saved with status "draft" — no invoice number is assigned yet.
        After calling this tool, show the user a clear summary and ask them to confirm
        before calling confirm_invoice to finalise and assign an invoice number.
        TEXT;
    }

    public function handle(Request $request): Stringable|string
    {
        $company = $this->user->companies()->first();

        if (! $company) {
            return json_encode(['success' => false, 'message' => 'No company profile found.']);
        }

        // Resolve client
        $client = $company->clients()->find($request['client_id']);
        if (! $client) {
            return json_encode(['success' => false, 'message' => 'Client not found. Use get_clients to find the correct client ID.']);
        }

        // FIX: Validate and parse line_items_json early, with a proper json_last_error()
        //      check so the LLM gets a clear error message instead of the confusing
        //      "At least one line item is required" fallback when JSON is malformed.
        $rawJson   = $request['line_items_json'] ?? '';
        $lineItems = [];

        if (! empty($rawJson)) {
            $lineItems = json_decode($rawJson, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return json_encode([
                    'success' => false,
                    'message' => 'line_items_json is not valid JSON: ' . json_last_error_msg()
                        . '. Ensure the value is a properly encoded JSON array string.',
                ]);
            }
        }

        if (empty($lineItems) || ! is_array($lineItems)) {
            return json_encode(['success' => false, 'message' => 'At least one line item is required.']);
        }

        // Validate each line item has the minimum required fields before hitting the DB
        foreach ($lineItems as $index => $item) {
            $missing = array_filter(['description', 'quantity', 'rate', 'gst_rate'], fn($f) => ! isset($item[$f]));
            if (! empty($missing)) {
                return json_encode([
                    'success' => false,
                    'message' => "Line item at index {$index} is missing required fields: " . implode(', ', $missing) . '.',
                ]);
            }
        }

        // Determine supply type from state codes
        $companyStateCode = $company->state_code;
        $clientStateCode  = $client->state_code;
        $supplyType       = ($companyStateCode === $clientStateCode) ? 'intra_state' : 'inter_state';

        try {
            DB::beginTransaction();

            $invoice = Invoice::create([
                'invoice_number'      => 'DRAFT-' . strtoupper(uniqid()),
                'company_id'          => $company->id,
                'client_id'           => $client->id,

                // Snapshot seller info
                'company_name'        => $company->company_name,
                'company_gst_number'  => $company->gst_number,
                'company_state'       => $company->state,
                'company_state_code'  => $company->state_code,

                // Snapshot buyer info
                'client_name'         => $client->name,
                'client_email'        => $client->email,
                'client_address'      => implode(', ', array_filter([
                    $client->address, $client->city, $client->state, $client->pincode,
                ])),
                'client_gst_number'   => $client->gst_number,
                'client_state'        => $client->state,
                'client_state_code'   => $client->state_code,

                // Dates
                'invoice_date'        => $request['invoice_date'] ?? now()->toDateString(),
                'due_date'            => $request['due_date'] ?? null,

                // Meta
                'currency'            => $client->currency ?? 'INR',
                'invoice_type'        => $request['invoice_type'] ?? 'tax_invoice',
                'supply_type'         => $supplyType,
                'status'              => 'draft',
                'payment_terms'       => $request['payment_terms'] ?? $client->payment_terms,

                // Bank details snapshot
                'bank_account_name'   => $company->bank_account_name,
                'bank_account_number' => $company->bank_account_number,
                'bank_ifsc_code'      => $company->bank_ifsc_code,

                'notes'               => $request['notes'] ?? null,
                'terms_and_conditions'=> $request['terms_and_conditions'] ?? null,

                // Totals — populated after line items
                'subtotal'            => 0,
                'discount_amount'     => 0,
                'taxable_amount'      => 0,
                'cgst_amount'         => 0,
                'sgst_amount'         => 0,
                'igst_amount'         => 0,
                'gst_amount'          => 0,
                'total_amount'        => 0,
                'amount_paid'         => 0,
                'amount_due'          => 0,
            ]);

            foreach ($lineItems as $sortOrder => $itemData) {
                $inventoryItem = null;
                if (! empty($itemData['inventory_item_id'])) {
                    $inventoryItem = $company->inventoryItems()->find($itemData['inventory_item_id']);
                }

                $lineItem = new InvoiceLineItem([
                    'invoice_id'        => $invoice->id,
                    'inventory_item_id' => $inventoryItem?->id,
                    'description'       => $itemData['description'] ?? $inventoryItem?->name ?? '',
                    'hsn_code'          => $itemData['hsn_code'] ?? $inventoryItem?->hsn_code ?? null,
                    'unit'              => $itemData['unit'] ?? $inventoryItem?->unit ?? 'Nos',
                    'quantity'          => $itemData['quantity'],
                    'rate'              => $itemData['rate'] ?? $inventoryItem?->rate ?? 0,
                    'discount_percent'  => $itemData['discount_percent'] ?? 0,
                    'gst_rate'          => $itemData['gst_rate'] ?? $inventoryItem?->gst_rate ?? 18,
                    'sort_order'        => $sortOrder + 1,
                ]);

                $lineItem->calculateAmounts($supplyType);
                $lineItem->save();
            }

            $invoice->recalculateTotals();
            $invoice->refresh()->load('lineItems');

            DB::commit();

            $lineItemsSummary = $invoice->lineItems->map(fn($li) => [
                'description'    => $li->description,
                'quantity'       => (float) $li->quantity,
                'unit'           => $li->unit,
                'rate'           => (float) $li->rate,
                'discount'       => (float) $li->discount_percent . '%',
                'taxable_amount' => (float) $li->amount,
                'gst_rate'       => (float) $li->gst_rate . '%',
                'tax_amount'     => (float) $li->total_tax_amount,
                'total'          => (float) $li->total_amount,
            ])->toArray();

            return json_encode([
                'success'        => true,
                'message'        => 'Draft invoice created. Please show the summary to the user and ask them to confirm.',
                'draft_ref'      => $invoice->invoice_number,
                'draft_id'       => $invoice->id,
                'supply_type'    => $supplyType,
                'client'         => $client->name,
                'invoice_date'   => $invoice->invoice_date->toDateString(),
                'due_date'       => $invoice->due_date?->toDateString(),
                'payment_terms'  => $invoice->payment_terms,
                'line_items'     => $lineItemsSummary,
                'totals'         => [
                    'subtotal'    => (float) $invoice->subtotal,
                    'cgst'        => (float) $invoice->cgst_amount,
                    'sgst'        => (float) $invoice->sgst_amount,
                    'igst'        => (float) $invoice->igst_amount,
                    'gst_total'   => (float) $invoice->gst_amount,
                    'grand_total' => (float) $invoice->total_amount,
                ],
                'next_step' => 'Call confirm_invoice with draft_ref if the user approves.',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return json_encode(['success' => false, 'message' => 'Failed to create draft: ' . $e->getMessage()]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            // FIX: client_id and line_items_json marked as required
            'client_id' => $schema->integer()
                ->required()
                ->description('ID of the client to bill. Use get_clients to look up the ID if you only have a name.'),

            'invoice_date' => $schema->string()
                ->description('Invoice date (YYYY-MM-DD). Defaults to today if omitted.'),

            'due_date' => $schema->string()
                ->description('Payment due date (YYYY-MM-DD).'),

            'payment_terms' => $schema->string()
                ->description('e.g. Net 30, Immediate. Defaults to the client\'s stored payment terms.'),

            'invoice_type' => $schema->string()
                ->enum(['tax_invoice', 'proforma', 'credit_note', 'debit_note'])
                ->description('Defaults to tax_invoice.'),

            'notes' => $schema->string()
                ->description('Optional note shown on the invoice.'),

            'terms_and_conditions' => $schema->string()
                ->description('Optional terms and conditions text shown on the invoice.'),

            // JSON string workaround — framework does not support nested array-of-objects in schema.
            // FIX: Improved description to make JSON formatting requirements unambiguous to the LLM,
            //      reducing malformed-JSON errors. Marked as required.
            'line_items_json' => $schema->string()
                ->required()
                ->description(
                    'A JSON-encoded array of line item objects. '
                    . 'IMPORTANT: This must be a valid JSON string — use double quotes for all keys and string values, no trailing commas. '
                    . 'Required fields per item: "description" (string), "quantity" (number), "rate" (number), "gst_rate" (number). '
                    . 'Optional fields: "unit" (string, default "Nos"), "hsn_code" (string), "discount_percent" (number, default 0), "inventory_item_id" (integer or null). '
                    . 'Example: [{"description":"Web Design","quantity":1,"rate":5000,"gst_rate":18,"unit":"Nos","hsn_code":"998314","discount_percent":0,"inventory_item_id":null}]'
                ),
        ];
    }
}
