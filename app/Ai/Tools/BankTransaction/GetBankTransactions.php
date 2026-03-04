<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * GetBankTransactions — read-only lookup tool.
 *
 * Returns a filtered list of bank transactions for the authenticated user.
 * Used by BankTransactionAgent as its primary discovery tool (ReWOO plan-first:
 * always look up before asking the user for details).
 */
class GetBankTransactions implements Tool
{
    public function __construct(private readonly User $user) {}

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
        $query = BankTransaction::query()
            ->whereHas('bankAccount', fn ($q) => $q->where('user_id', $this->user->id))
            ->with(['narrationHead', 'narrationSubHead', 'bankAccount'])
            ->orderByDesc('transaction_date')
            ->limit(min((int) ($request['limit'] ?? 20), 50));

        if ($request['from_date'])            $query->whereDate('transaction_date', '>=', $request['from_date']);
        if ($request['to_date'])              $query->whereDate('transaction_date', '<=', $request['to_date']);
        if ($request['type'])                 $query->where('type', $request['type']);
        if ($request['review_status'])        $query->where('review_status', $request['review_status']);
        if (isset($request['is_reconciled'])) $query->where('is_reconciled', (bool) $request['is_reconciled']);
        if ($request['bank_account_id'])      $query->where('bank_account_id', (int) $request['bank_account_id']);

        $transactions = $query->get();

        if ($transactions->isEmpty()) {
            return json_encode([
                'found'        => false,
                'transactions' => [],
                'message'      => 'No transactions matched the filters.',
            ]);
        }

        return json_encode([
            'found'        => true,
            'count'        => $transactions->count(),
            'transactions' => $transactions->map(fn ($t) => [
                'id'                 => $t->id,
                'transaction_date'   => $t->transaction_date->toDateString(),
                'bank_reference'     => $t->bank_reference,
                'raw_narration'      => $t->raw_narration,
                'type'               => $t->type,
                'amount'             => number_format($t->amount, 2),
                'balance_after'      => number_format($t->balance_after, 2),
                'narration_head'     => $t->narrationHead?->name,
                'narration_sub_head' => $t->narrationSubHead?->name,
                'narration_note'     => $t->narration_note,
                'party_name'         => $t->party_name,
                'party_reference'    => $t->party_reference,
                'narration_source'   => $t->narration_source,
                'review_status'      => $t->review_status,
                'is_reconciled'      => $t->is_reconciled,
                'is_duplicate'       => $t->is_duplicate,
                'bank_account'       => $t->bankAccount?->name,
            ])->toArray(),
        ]);
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
