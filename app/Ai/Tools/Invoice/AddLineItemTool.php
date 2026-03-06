<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class AddLineItemTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Add a line item to a draft invoice. Provide inventory_item_id (from LookupInventoryItem) to auto-fill description, HSN code, unit, and GST rate — or supply them manually. GST is split into CGST+SGST (intra-state) or IGST (inter-state) automatically. Returns the updated invoice with all totals recalculated.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('The invoice_id returned when the draft was created.')
                ->required(),

            'quantity' => $schema->number()
                ->description('Quantity of the item.')
                ->required(),

            'rate' => $schema->number()
                ->description('Unit price (before discount) of the item.')
                ->required(),

            'inventory_item_id' => $schema->integer()
                ->description('Optional: ID from LookupInventoryItem. Auto-fills description, HSN, unit, and GST rate.'),

            'description' => $schema->string()
                ->description('Line item description. Required if inventory_item_id is not provided.'),

            'hsn_code' => $schema->string()
                ->description('HSN/SAC code for GST. Auto-filled if inventory_item_id is provided.'),

            'unit' => $schema->string()
                ->description('Unit of measure, e.g. "Nos", "Kg", "Hr". Auto-filled if inventory_item_id is provided.'),

            'discount_percent' => $schema->number()
                ->description('Discount percentage (0–100). Defaults to 0.'),

            'gst_rate' => $schema->number()
                ->description('GST rate percentage, e.g. 18 for 18% GST. Auto-filled if inventory_item_id is provided.'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);

            $invoice = $service->addLineItem(
                invoiceId:       $request['invoice_id'],
                quantity:        (float) $request['quantity'],
                rate:            (float) $request['rate'],
                inventoryItemId: isset($request['inventory_item_id']) ? (int) $request['inventory_item_id'] : null,
                description:     $request['description']     ?? null,
                hsnCode:         $request['hsn_code']        ?? null,
                unit:            $request['unit']            ?? null,
                discountPercent: (float) ($request['discount_percent'] ?? 0),
                gstRate:         isset($request['gst_rate']) ? (float) $request['gst_rate'] : null,
            );

            return json_encode([
                'success'     => true,
                'invoice'     => $invoice,
                'message'     => "Line item added. Invoice total is now {$invoice['currency']} {$invoice['total_amount']}.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
