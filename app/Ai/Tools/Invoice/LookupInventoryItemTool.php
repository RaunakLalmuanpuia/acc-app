<?php

namespace App\Ai\Tools\Invoice;
use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class LookupInventoryItemTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Search inventory items by name, SKU, or HSN code. Returns rate, GST rate, unit, and HSN code — use these values when adding line items to an invoice.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Item name fragment, SKU, HSN code, or numeric item ID to search for.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $service = new InvoiceAgentService($this->companyId);
        $items   = $service->findInventoryItems($request['query']);

        if (empty($items)) {
            return json_encode(['error' => "No inventory items found matching '{$request['query']}'. Try a different keyword."]);
        }

        return json_encode(['items' => $items, 'count' => count($items)]);
    }
}
