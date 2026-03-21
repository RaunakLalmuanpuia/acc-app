<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class RemoveLineItemTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Remove a line item from a draft invoice by its line item ID. '
            . 'Call get_invoice first to get the line item IDs, then call this tool. '
            . 'Totals are recalculated automatically. '
            . 'Never add a zero-quantity line item to simulate removal — always use this tool.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_id' => $schema->integer()
                ->description('The invoice_id (not invoice_number).')
                ->required(),

            'line_item_id' => $schema->integer()
                ->description('The id of the line item to remove, from get_invoice results.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);
            $invoice = $service->removeLineItem(
                invoiceId:  (int) $request['invoice_id'],
                lineItemId: (int) $request['line_item_id'],
            );

            return json_encode([
                'success' => true,
                'invoice' => $invoice,
                'message' => "Line item removed. Invoice total is now {$invoice['currency']} {$invoice['total_amount']}.",
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
