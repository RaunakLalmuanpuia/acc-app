<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateTransactionReviewStatus implements Tool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    public function description(): Stringable|string
    {
        return 'Set the review_status of a bank transaction to pending, reviewed, or flagged. '
            . 'Use flagged for suspicious or unresolvable transactions that need human attention. '
            . 'Optionally update the narration_note at the same time.';
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->service->updateReviewStatus(
            transactionId: (int) $request['transaction_id'],
            reviewStatus:  $request['review_status'],
            note:          $request['note'] ?? null,
        );

        return json_encode($result);
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
