<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class NarrateTransaction implements Tool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    public function description(): Stringable|string
    {
        return 'Assign a narration (accounting category) to a bank transaction. '
            . 'narration_head_id is always required. narration_sub_head_id is optional — '
            . 'omit it to categorise at head level only. '
            . 'Optionally include a note, party_name, and source (manual|ai_suggested|rule_based|auto_matched). '
            . 'Sets review_status to reviewed automatically.';
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->service->narrateTransaction(
            transactionId: (int) $request['transaction_id'],
            headId:        (int) $request['narration_head_id'],
            subHeadId:     isset($request['narration_sub_head_id']) ? (int) $request['narration_sub_head_id'] : null,
            source:        $request['source'] ?? 'manual',
            note:          $request['note'] ?? null,
            partyName:     $request['party_name'] ?? null,
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id'        => $schema->integer()->required()->description('The ID of the bank transaction to narrate'),
            'narration_head_id'     => $schema->integer()->required()->description('The ID of the narration head — always required'),
            'narration_sub_head_id' => $schema->integer()->description('The ID of the narration sub-head — optional, omit to categorise at head level only'),
            'note'                  => $schema->string()->description('A concise description of what the transaction represents — always provide one, derived from raw_narration and party context'),
            'party_name'            => $schema->string()->description('Optional party name (vendor, client, etc.) associated with this transaction'),
            'source'                => $schema->string()->enum(['manual', 'ai_suggested', 'rule_based', 'auto_matched'])->description('Narration source — defaults to manual'),
        ];
    }
}
