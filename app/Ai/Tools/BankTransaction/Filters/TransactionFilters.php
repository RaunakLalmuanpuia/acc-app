<?php

// app/Ai/Tools/BankTransaction/Filters/TransactionFilters.php

namespace App\Ai\Tools\BankTransaction\Filters;

final class TransactionFilters
{
    private function __construct(
        public readonly ?string $fromDate     = null,
        public readonly ?string $toDate       = null,
        public readonly ?string $type         = null,
        public readonly ?string $reviewStatus = null,
        public readonly ?bool   $isReconciled = null,
        public readonly int     $limit        = 20,
    ) {}

    /**
     * Build from raw LLM tool input.
     * Apply all sanitization rules in one place.
     */
    public static function fromLlmInput(array $input): self
    {
        $fromDate        = self::parseDate($input['from_date'] ?? null);
        $toDate          = self::parseDate($input['to_date'] ?? null);
        $rawIsReconciled = $input['is_reconciled'] ?? null;

        $type         = self::parseEnum($input['type'] ?? null, ['credit', 'debit']);
        $reviewStatus = self::parseEnum($input['review_status'] ?? null, ['pending', 'reviewed', 'flagged']);
        $isReconciled = self::parseReconciled($rawIsReconciled);

        // is_reconciled=false is always a hallucination signal — the model defaults
        // it without any user instruction. The schema explicitly says only pass TRUE.
        // When false appears, strip type and reviewStatus too — they were invented
        // alongside it from conversation context, not from the user's message.
        if ($rawIsReconciled === false) {
            $type         = null;
            $reviewStatus = null;
            $isReconciled = null;
            \Log::info('TransactionFilters: stripped hallucinated filters (is_reconciled=false signal)');
        }

        return new self(
            fromDate:     $fromDate,
            toDate:       $toDate,
            type:         $type,
            reviewStatus: $reviewStatus,
            isReconciled: $isReconciled,
            limit:        self::parseLimit($input['limit'] ?? null),
        );
    }

    private static function parseDate(?string $value): ?string
    {
        if (empty($value)) return null;

        // Validate it's actually a Y-m-d date, not garbage
        $d = \DateTime::createFromFormat('Y-m-d', $value);
        return ($d && $d->format('Y-m-d') === $value) ? $value : null;
    }

    private static function parseEnum(?string $value, array $allowed): ?string
    {
        return in_array($value, $allowed, true) ? $value : null;
    }

    private static function parseReconciled(mixed $value): ?bool
    {
        // Only honour explicit true — the model defaults false which
        // hides all reconciled transactions from a general listing request.
        return $value === true ? true : null;
    }

    private static function parseLimit(mixed $value): int
    {
        return max(1, min(50, (int) ($value ?? 20)));
    }
}
