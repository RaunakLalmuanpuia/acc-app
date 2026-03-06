<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class LookupClientTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Search for clients by name, email, or numeric ID. Returns up to 10 matching active clients with their address, GST, state, and payment terms — everything needed to create an invoice.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Client name fragment, email, or numeric client ID to search for.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $service = new InvoiceAgentService($this->companyId);
        $clients = $service->findClients($request['query']);

        if (empty($clients)) {
            return json_encode(['error' => "No clients found matching '{$request['query']}'. Try a shorter name or check the spelling."]);
        }

        return json_encode(['clients' => $clients, 'count' => count($clients)]);
    }
}
