<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\BankTransaction;
use App\Models\NarrationSubHead;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * NarrateTransaction — assigns a narration category to a bank transaction.
 *
 * Calls the model's narrate() helper, which sets narration_head_id,
 * narration_sub_head_id, narration_source, narration_note, party_name,
 * and review_status = 'reviewed' atomically.
 *
 * The agent must resolve the correct transaction ID via get_bank_transactions
 * and the sub_head_id via get_narration_heads BEFORE calling this tool.
 */
class NarrateTransaction implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'Assign a narration (accounting category) to a bank transaction by providing '
            . 'the transaction_id and narration_sub_head_id. Optionally include a note, '
            . 'party_name, and source (ai|manual|rule). Sets review_status to reviewed automatically.';
    }

    public function handle(Request $request): Stringable|string
    {
        $transaction = BankTransaction::query()
            ->whereHas('bankAccount', fn ($q) => $q->where('user_id', $this->user->id))
            ->find((int) $request['transaction_id']);

        if (!$transaction) {
            return json_encode([
                'success' => false,
                'error'   => 'Transaction not found or does not belong to this user.',
            ]);
        }

        $subHead = NarrationSubHead::find((int) $request['narration_sub_head_id']);

        if (!$subHead) {
            return json_encode([
                'success' => false,
                'error'   => 'Narration sub-head not found.',
            ]);
        }

        $transaction->narrate(
            subHead:   $subHead,
            source:    $request['source'] ?? 'ai',
            note:      $request['note'] ?? null,
            partyName: $request['party_name'] ?? null,
        );

        return json_encode([
            'success'            => true,
            'transaction_id'     => $transaction->id,
            'narration_head'     => $subHead->narrationHead?->name,
            'narration_sub_head' => $subHead->name,
            'party_name'         => $transaction->party_name,
            'note'               => $transaction->narration_note,
            'review_status'      => $transaction->review_status,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id'        => $schema->integer()->required()->description('The ID of the bank transaction to narrate'),
            'narration_sub_head_id' => $schema->integer()->required()->description('The ID of the narration sub-head to assign'),
            'note'                  => $schema->string()->description('Optional free-text note explaining the categorisation'),
            'party_name'            => $schema->string()->description('Optional party name (vendor, client, etc.) associated with this transaction'),
            'source'                => $schema->string()->enum(['ai', 'manual', 'rule'])->description('Who or what assigned the narration — defaults to ai'),
        ];
    }
}
