<?php

namespace App\DTOs\Banking;

use Carbon\Carbon;

readonly class ParsedTransactionDTO
{
    public function __construct(
        public string  $rawNarration,
        public string  $type,           // 'credit' | 'debit'
        public float   $amount,
        public string  $bankReference,
        public ?string $partyName,
        public Carbon  $transactionDate,
        public ?float  $balanceAfter,
        public ?string $bankName,
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            rawNarration:    $data['raw_narration'],
            type:            strtolower($data['type']),
            amount:          (float) $data['amount'],
            bankReference:   $data['bank_reference'] ?? '',
            partyName:       $data['party_name'] ?? null,
            transactionDate: Carbon::parse($data['transaction_date']),
            balanceAfter:    ($data['balance_after'] ?? 0) > 0 ? (float) $data['balance_after'] : null,
            bankName:        $data['bank_name'] ?? null,
        );
    }
}
