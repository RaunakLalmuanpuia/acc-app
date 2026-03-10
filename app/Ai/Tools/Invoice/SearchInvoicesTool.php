<?php

namespace App\Ai\Tools\Invoice;

use App\Services\InvoiceAgentService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SearchInvoicesTool implements Tool
{
    public function __construct(private readonly int $companyId) {}

    public function description(): string
    {
        return 'Search and list invoices for this company. '
            . 'Call with NO parameters to list all invoices — do not pass status, query, or any other field unless the user explicitly specified it. '
            . 'When the user says "show all invoices", "list my invoices", or similar — call with zero arguments.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Invoice number fragment or client name to search for. Omit if the user did not mention a specific client or invoice number.'),

            'status' => $schema->string()
                ->description(
                    'ONLY pass this field if the user\'s message contains one of these exact words: "draft", "sent", "paid", "cancelled", "void", or "unpaid". ' .
                    'Examples that must NOT include status: "show all invoices", "list my invoices", "show me invoices". ' .
                    'Examples that MUST include status: "show draft invoices" → draft, "show unpaid invoices" → sent, "show paid invoices" → paid. ' .
                    'When in doubt — OMIT this field. Passing status="draft" for a general list request is a critical error that hides data.'
                )
                ->enum(['draft', 'sent', 'paid', 'cancelled', 'void']),

            'date_from' => $schema->string()
                ->description('Invoice date range start (YYYY-MM-DD). Omit entirely if not specified — do NOT pass empty string.'),

            'date_to' => $schema->string()
                ->description('Invoice date range end (YYYY-MM-DD). Omit entirely if not specified — do NOT pass empty string.'),

            'due_date_from' => $schema->string()
                ->description('Due date range start (YYYY-MM-DD). Use to find overdue invoices.'),

            'due_date_to' => $schema->string()
                ->description('Due date range end (YYYY-MM-DD). Use to find overdue invoices.'),

            'amount_min' => $schema->number()
                ->description('Minimum total invoice amount. Omit entirely if not specified — do NOT pass 0.'),

            'amount_max' => $schema->number()
                ->description('Maximum total invoice amount. Omit entirely if not specified — do NOT pass 0.'),

            'limit' => $schema->integer()
                ->description('Maximum results to return. Defaults to 15.'),
        ];
    }

    public function handle(Request $request): string
    {
        \Log::info('[SearchInvoicesTool] raw request', [
            'status' => $request['status'] ?? 'NOT SET',
            'query'  => $request['query']  ?? 'NOT SET',
        ]);

        try {
            $service = new InvoiceAgentService($this->companyId);

            $query  = strlen($request['query'] ?? '') > 0 ? trim($request['query']) : null;
            $status = $request['status'] ?? null;

            // Hard guard: if no query and status defaulted to draft,
            // the agent ignored instructions — clear it to return all invoices.
            if ($query === null && $status === 'draft') {
                $status = null;
            }

            $amountMin = isset($request['amount_min']) && (float) $request['amount_min'] > 0
                ? (float) $request['amount_min']
                : null;

            $amountMax = isset($request['amount_max']) && (float) $request['amount_max'] > 0
                ? (float) $request['amount_max']
                : null;

            $invoices = $service->searchInvoices(
                query:       $query,
                status:      $status,
                dateFrom:    $request['date_from']     ?? null,
                dateTo:      $request['date_to']       ?? null,
                dueDateFrom: $request['due_date_from'] ?? null,
                dueDateTo:   $request['due_date_to']   ?? null,
                amountMin:   $amountMin,
                amountMax:   $amountMax,
                limit:       isset($request['limit']) ? min((int) $request['limit'], 50) : 15,
            );

            if (empty($invoices)) {
                return json_encode([
                    'invoices' => [],
                    'count'    => 0,
                    'message'  => 'No invoices found for this company.',
                ]);
            }

            return json_encode([
                'invoices' => $invoices,
                'count'    => count($invoices),
            ]);

        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
