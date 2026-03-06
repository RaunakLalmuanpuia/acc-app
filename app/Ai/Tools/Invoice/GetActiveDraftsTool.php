<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Lets the agent recover the active invoice_id when conversation context
 * is truncated by the orchestrator's intent re-resolution.
 *
 * The agent should call this at the START of any turn where it does not
 * already have an invoice_id in context.
 */
class GetActiveDraftsTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Returns all open draft invoices for this company. Call this whenever you have lost track of the invoice_id mid-conversation — do NOT create a new invoice before checking here first.';
    }

    public function schema(JsonSchema $schema): array
    {
        // No parameters needed
        return [];
    }

    public function handle(Request $request): string
    {
        $service = new InvoiceAgentService($this->companyId);
        $drafts  = $service->getActiveDrafts();

        if (empty($drafts)) {
            return json_encode(['drafts' => [], 'message' => 'No open drafts found. Safe to create a new invoice.']);
        }

        return json_encode([
            'drafts'  => $drafts,
            'count'   => count($drafts),
            'message' => 'Found open drafts. Use the invoice_id from the relevant draft to continue.',
        ]);
    }
}
