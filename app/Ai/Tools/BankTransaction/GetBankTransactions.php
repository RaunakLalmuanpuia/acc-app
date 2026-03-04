<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
        return 'Retrieve bank transactions for the user. Supports optional filters: '
            . 'from_date (Y-m-d), to_date (Y-m-d), type (credit|debit), '
            . 'review_status (pending|reviewed|flagged), is_reconciled (bool), '
            . 'bank_account_id (int), and limit (max 50, default 20). '
            . 'Returns transactions ordered by transaction_date descending.';
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->service->getTransactions(
            fromDate:      $request['from_date'] ?? null,
            toDate:        $request['to_date'] ?? null,
            type:          $request['type'] ?? null,
            reviewStatus:  $request['review_status'] ?? null,
            isReconciled:  isset($request['is_reconciled']) ? (bool) $request['is_reconciled'] : null,
            bankAccountId: isset($request['bank_account_id']) ? (int) $request['bank_account_id'] : null,
            limit:         (int) ($request['limit'] ?? 20),
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'from_date'       => $schema->string()->description('Start date filter in Y-m-d format'),
            'to_date'         => $schema->string()->description('End date filter in Y-m-d format'),
            'type'            => $schema->string()->enum(['credit', 'debit'])->description('Transaction type filter'),
            'review_status'   => $schema->string()->enum(['pending', 'reviewed', 'flagged'])->description('Review status filter'),
            'is_reconciled'   => $schema->boolean()->description('Filter by reconciliation status'),
            'bank_account_id' => $schema->integer()->description('Filter by a specific bank account ID'),
            'limit'           => $schema->integer()->min(1)->max(50)->description('Number of results to return (default 20, max 50)'),
        ];
    }
}
