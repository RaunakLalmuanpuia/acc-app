<?php

namespace App\Ai\Tools\BankTransaction;

use App\Ai\Tools\BankTransaction\Filters\TransactionFilters;
use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetBankTransactions implements Tool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    public function description(): Stringable|string
    {
        return 'Retrieve bank transactions. Call with NO parameters to show all recent transactions. '
            . 'Only pass a filter if the user explicitly stated it in their message. '
            . 'Do NOT infer filters from prior conversation context.';
    }


    public function handle(Request $request): Stringable|string
    {
        Log::info('GetBankTransactions called', $request->toArray());
        // Treat empty strings, false, and zero as "not provided"
        $filters = TransactionFilters::fromLlmInput($request->toArray());

        Log::info('GetBankTransactions', [
            'raw'      => $request->toArray(),
            'resolved' => (array) $filters,
        ]);

        $result = $this->service->getTransactions($filters);

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_date'     => $schema->string()->description(
                'Start date (Y-m-d). Only set when user explicitly mentions a start date or period. '
                . 'When set, type/review_status/is_reconciled are ignored — all types are returned.'
            ),
            'to_date'       => $schema->string()->description(
                'End date (Y-m-d). Only set when user explicitly mentions an end date or period.'
            ),
            'type' => $schema->string()->enum(['credit', 'debit'])->description(
                'Only set when the current user message explicitly contains "credit", "debit", '
                . '"payments in", or "payments out". Do NOT carry this from a previous turn.'
            ),
            'review_status' => $schema->string()->enum(['pending', 'reviewed', 'flagged'])->description(
                'Only set when user explicitly says "pending", "reviewed", or "flagged".'
            ),
            'is_reconciled' => $schema->boolean()->description(
                'Only pass TRUE when user explicitly asks for reconciled transactions.'
            ),
            'limit'         => $schema->integer()->min(1)->max(50)->description(
                'Number of results (default 20). Only set if user specifies a count.'
            ),
        ];
    }
}
