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
        return 'Search and list invoices for this company. Call with NO parameters to list all invoices. '
            . 'Optionally filter by: query (invoice number or client name), status, date range, or amount range. '
            . 'When the user asks to "show all invoices" or "list my invoices" — call this immediately with no arguments.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Invoice number fragment or client name to search for.'),

            'status' => $schema->string()
                ->description(
                    'ONLY include this field if the user\'s message explicitly contains a status word like "draft", "sent", "paid", "cancelled", or "void". ' .
                    'If the user said "show all invoices", "list invoices", or anything without a status word — DO NOT include this field at all. ' .
                    'Defaulting to "draft" is WRONG. Omitting this field returns all statuses.'
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
        \Log::debug('[SearchInvoicesTool] raw request', [
            'query'        => $request['query']        ?? 'NOT SET',
            'status'       => $request['status']       ?? 'NOT SET',
            'date_from'    => $request['date_from']    ?? 'NOT SET',
            'date_to'      => $request['date_to']      ?? 'NOT SET',
            'amount_min'   => $request['amount_min']   ?? 'NOT SET',
            'amount_max'   => $request['amount_max']   ?? 'NOT SET',
            'limit'        => $request['limit']        ?? 'NOT SET',
        ]);
        try {
            $service  = new InvoiceAgentService($this->companyId);
            $query  = strlen($request['query'] ?? '') > 0 ? trim($request['query']) : null;

            $status = $request['status'] ?? null;

            // If no search query was provided, the user wants ALL invoices — ignore any status the agent assumed
            if ($query === null) {
                $status = null;
            }
            // Treat 0 as null for amounts — 0 is never a meaningful amount filter
            $amountMin = isset($request['amount_min']) && (float) $request['amount_min'] > 0
                ? (float) $request['amount_min']
                : null;

            $amountMax = isset($request['amount_max']) && (float) $request['amount_max'] > 0
                ? (float) $request['amount_max']
                : null;

            $invoices = $service->searchInvoices(
                query:       $request['query']         ?? null,
                status:      $status        ?? null,
                dateFrom:    $request['date_from']     ?? null,
                dateTo:      $request['date_to']       ?? null,
                dueDateFrom: $request['due_date_from'] ?? null,
                dueDateTo:   $request['due_date_to']   ?? null,
                amountMin:   $amountMin,
                amountMax:   $amountMax,
                limit:       isset($request['limit']) ? min((int) $request['limit'], 50) : 15,
            );
            if (empty($invoices)) {
                // Tell the agent exactly what was searched so it can report back honestly
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
