<?php

namespace App\Services\Banking;

use App\Ai\Agents\Narration\EmailParserAgent;
use App\Ai\Agents\Narration\SmsParserAgent;
use App\DTOs\Banking\ParsedTransactionDTO;
use App\Models\BankAccount;
use App\Models\BankTransaction;

class NarrationPipelineService
{
    /**
     * Source trust hierarchy — higher index = more authoritative.
     * A statement is the bank's official record; SMS is the least reliable.
     */
    private const SOURCE_PRIORITY = ['sms' => 1, 'email' => 2, 'statement' => 3];

    public function __construct(
        private NarrationRuleEngine $ruleEngine,
        private NarrationAiService  $aiService,
    ) {}

    // ── Entry points ──────────────────────────────────────────────────────────

    public function processFromSms(string $rawSms, BankAccount $account): BankTransaction
    {
        $response = SmsParserAgent::make()->prompt("Parse this bank SMS:\n\n{$rawSms}");

        $dto = ParsedTransactionDTO::fromArray([
            'raw_narration'    => $rawSms,
            'type'             => $response['type'],
            'amount'           => $response['amount'],
            'bank_reference'   => $response['bank_reference'] ?? '',
            'party_name'       => $response['party_name'] ?? null,
            'transaction_date' => $response['transaction_date'],
            'balance_after'    => $response['balance_after'] ?? null,
            'bank_name'        => $response['bank_name'] ?? null,
        ]);

        return $this->process($dto, $account, 'sms');
    }

    public function processFromEmail(string $rawEmail, BankAccount $account): BankTransaction
    {
        $response = EmailParserAgent::make()->prompt("Parse this bank alert email:\n\n{$rawEmail}");

        $dto = ParsedTransactionDTO::fromArray([
            'raw_narration'    => $rawEmail,
            'type'             => $response['type'],
            'amount'           => $response['amount'],
            'bank_reference'   => $response['bank_reference'] ?? '',
            'party_name'       => $response['party_name'] ?? null,
            'transaction_date' => $response['transaction_date'],
            'balance_after'    => $response['balance_after'] ?? null,
            'bank_name'        => $response['bank_name'] ?? null,
        ]);

        return $this->process($dto, $account, 'email');
    }

    /**
     * Run the narration pipeline on an already-parsed DTO.
     * Called directly by the statement upload flow, and internally by the two methods above.
     */
    public function process(ParsedTransactionDTO $dto, BankAccount $account, string $importSource = 'statement'): BankTransaction
    {
        $companyId = $account->company_id;

        // ── Dedup check ───────────────────────────────────────────────────────
        $hash = BankTransaction::makeDedupHash(
            $dto->transactionDate->toDateString(),
            $dto->amount,
            $dto->type,
            $dto->bankReference
        );

        $existing = BankTransaction::where('bank_account_id', $account->id)
            ->where('dedup_hash', $hash)
            ->first();

        // ── Source priority upgrade ───────────────────────────────────────────
        // If the same transaction already exists but the incoming source is more
        // authoritative (statement > email > sms), upgrade the existing record
        // in-place rather than creating a new duplicate.
        if ($existing && $this->incomingOutranks($importSource, $existing->import_source)) {
            return $this->upgradeSource($existing, $dto, $importSource);
        }

        $isDuplicate = $existing !== null;

        // ── Tier 1: Rule engine ───────────────────────────────────────────────
        $suggestion = $this->ruleEngine->match(
            $dto->rawNarration,
            $dto->type,
            $dto->amount,
            $companyId
        );

        // ── Tier 2: AI fallback ───────────────────────────────────────────────
        if (!$suggestion) {
            $suggestion = $this->aiService->suggest(
                $dto->rawNarration,
                $dto->type,
                $dto->amount,
                $dto->transactionDate->toDateString(),
                $companyId
            );
        }

        // ── Persist ───────────────────────────────────────────────────────────
        $transaction = BankTransaction::create([
            'bank_account_id'       => $account->id,
            'transaction_date'      => $dto->transactionDate,
            'bank_reference'        => $dto->bankReference,
            'raw_narration'         => $dto->rawNarration,
            'type'                  => $dto->type,
            'amount'                => $dto->amount,
            'balance_after'         => $dto->balanceAfter,
            'narration_head_id'     => $suggestion->narrationHeadId,
            'narration_sub_head_id' => $suggestion->narrationSubHeadId,
            'narration_note'        => $suggestion->narrationNote,
            'party_name'            => $suggestion->partyName ?? $dto->partyName,
            'narration_source'      => $suggestion->source,
            'ai_confidence'         => $suggestion->confidence,
            'ai_suggestions'        => $suggestion->aiSuggestions,
            'ai_metadata'           => $suggestion->aiMetadata,
            'review_status'         => 'pending',
            'applied_rule_id'       => $suggestion->appliedRuleId,
            'dedup_hash'            => $hash,
            'is_duplicate'          => $isDuplicate,
            'import_source'         => $importSource,
        ]);

        if ($dto->balanceAfter) {
            $account->update(['current_balance' => $dto->balanceAfter]);
        }

        return $transaction;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Returns true when the incoming source should replace the stored one.
     * Equal sources never trigger an upgrade (avoids overwriting with the same data).
     */
    private function incomingOutranks(string $incoming, string $existing): bool
    {
        $incomingRank = self::SOURCE_PRIORITY[$incoming] ?? 0;
        $existingRank = self::SOURCE_PRIORITY[$existing] ?? 0;

        return $incomingRank > $existingRank;
    }

    /**
     * Upgrade an existing transaction to a higher-authority source in-place.
     *
     * Always updated:
     *   - raw_narration   → canonical text for the new source
     *   - import_source   → new (higher-ranked) source
     *   - party_name      → new value if the better source provides one
     *   - balance_after   → new value if the better source provides one
     *   - bank_reference  → new value if the better source provides one
     *   - sms_narration   → original SMS text saved the first time we upgrade away from SMS
     *
     * Never overwritten (user work is preserved):
     *   - review_status, narration_head_id, narration_sub_head_id, narration_note
     */
    private function upgradeSource(BankTransaction $existing, ParsedTransactionDTO $dto, string $newSource): BankTransaction
    {
        $payload = [
            'raw_narration'  => $dto->rawNarration,
            'import_source'  => $newSource,
            'party_name'     => $dto->partyName    ?? $existing->party_name,
            'balance_after'  => $dto->balanceAfter ?? $existing->balance_after,
            'bank_reference' => $dto->bankReference ?: $existing->bank_reference,
        ];

        // Preserve original SMS text the first time it gets superseded
        if ($existing->import_source === 'sms' && empty($existing->sms_narration)) {
            $payload['sms_narration'] = $existing->raw_narration;
        }

        $existing->update($payload);

        return $existing->fresh();
    }
}
