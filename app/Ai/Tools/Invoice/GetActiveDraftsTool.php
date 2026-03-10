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
        return 'Returns open draft invoices for this company. '
            . 'Call with no parameters to get all drafts. '
            . 'Pass invoice_number to resolve a specific draft to its invoice_id — '
            . 'use this when the user has named an invoice number and you need its id to continue. '
            . 'Pass client_name to filter drafts for a specific client.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_number' => $schema->string()
                ->description(
                    'Exact invoice number to look up (e.g. "INV-20260310-17453"). '
                    . 'Use this when the user has named a specific draft and you need its invoice_id.'
                ),

            'client_name' => $schema->string()
                ->description(
                    'Filter drafts by client name fragment. '
                    . 'Use this to narrow results when checking for drafts for a specific client.'
                ),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $service = new InvoiceAgentService($this->companyId);

            $invoiceNumber = isset($request['invoice_number']) && trim($request['invoice_number']) !== ''
                ? trim($request['invoice_number'])
                : null;

            $clientName = isset($request['client_name']) && trim($request['client_name']) !== ''
                ? trim($request['client_name'])
                : null;

            $drafts = $service->getActiveDrafts(
                invoiceNumber: $invoiceNumber,
                clientName:    $clientName,
            );

            if (empty($drafts)) {
                $context = $invoiceNumber
                    ? "No draft found with invoice number {$invoiceNumber}."
                    : 'No open drafts found. Safe to create a new invoice.';

                return json_encode(['drafts' => [], 'message' => $context]);
            }

            // If searching by invoice_number, surface the id prominently
            if ($invoiceNumber && count($drafts) === 1) {
                return json_encode([
                    'drafts'     => $drafts,
                    'invoice_id' => $drafts[0]['id'],
                    'message'    => "Found draft {$invoiceNumber} — invoice_id={$drafts[0]['id']}. Use this id to add line items.",
                ]);
            }

            return json_encode([
                'drafts'  => $drafts,
                'count'   => count($drafts),
                'message' => 'Found open drafts. Use the invoice_id from the relevant draft to continue.',
            ]);

        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
