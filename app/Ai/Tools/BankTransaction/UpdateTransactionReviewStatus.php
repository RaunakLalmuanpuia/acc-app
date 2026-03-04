<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\BankTransaction;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * UpdateTransactionReviewStatus — set the review_status on a transaction.
 *
 * Use 'flagged' for suspicious or unresolvable transactions that need
 * human attention. Always include a note when flagging.
 */
class UpdateTransactionReviewStatus implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'Set the review_status of a bank transaction to pending, reviewed, or flagged. '
            . 'Use flagged for suspicious or unresolvable transactions that need human attention. '
            . 'Optionally update the narration_note at the same time.';
    }

    public function handle(Request $request): Stringable|string
    {
        $allowed = ['pending', 'reviewed', 'flagged'];

        if (!in_array($request['review_status'], $allowed, true)) {
            return json_encode([
                'success' => false,
                'error'   => 'Invalid review_status. Must be one of: ' . implode(', ', $allowed),
            ]);
        }

        $transaction = BankTransaction::query()
            ->whereHas('bankAccount', fn ($q) => $q->where('user_id', $this->user->id))
            ->find((int) $request['transaction_id']);

        if (!$transaction) {
            return json_encode([
                'success' => false,
                'error'   => 'Transaction not found or does not belong to this user.',
            ]);
        }

        $updates = ['review_status' => $request['review_status']];

        if ($request['note'] !== null) {
            $updates['narration_note'] = $request['note'];
        }

        $transaction->update($updates);

        return json_encode([
            'success'        => true,
            'transaction_id' => $transaction->id,
            'review_status'  => $transaction->review_status,
            'note'           => $transaction->narration_note,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required()->description('The ID of the bank transaction to update'),
            'review_status'  => $schema->string()->enum(['pending', 'reviewed', 'flagged'])->required()->description('The new review status to set'),
            'note'           => $schema->string()->description('Optional note — especially important when flagging a transaction to explain why'),
        ];
    }
}
