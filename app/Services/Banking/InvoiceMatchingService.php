<?php

namespace App\Services\Banking;

use App\Models\BankTransaction;
use App\Models\Invoice;
use Illuminate\Support\Collection;

class InvoiceMatchingService
{
    private const AMOUNT_TOLERANCE    = 0.02;
    private const DATE_WINDOW_DAYS    = 30;

    /**
     * Minimum score to be considered a real candidate.
     * Date-only matches (max 15 pts) are excluded by this threshold.
     * A result needs at least a weak amount match (15) + date (5) = 20,
     * or a party name match (20) + date (5) = 25, etc.
     */
    private const MIN_SCORE           = 25;

    /**
     * At least one of these "anchor" signals must be present.
     * Pure date proximity alone is not enough to surface a suggestion.
     */
    private const REQUIRED_REASONS    = [
        'Exact amount match',
        'Near-exact amount (within 2%)',
        'Amount within 10% (possible TDS/deduction)',
        'Client name matches exactly',
        'Client name partially matches',
        'Invoice number found in bank reference',
    ];

    public function findCandidates(BankTransaction $transaction): Collection
    {
        $companyId = $transaction->bankAccount->company_id;

        $types = $transaction->isCredit()
            ? ['tax_invoice', 'proforma']
            : ['debit_note'];

        $candidates = Invoice::forCompany($companyId)
            ->whereIn('invoice_type', $types)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereBetween('invoice_date', [
                $transaction->transaction_date->copy()->subDays(self::DATE_WINDOW_DAYS),
                $transaction->transaction_date->copy()->addDays(self::DATE_WINDOW_DAYS),
            ])
            ->with('client')
            ->get();

        return $candidates
            ->map(fn(Invoice $invoice) => $this->score($transaction, $invoice))
            ->filter(fn(array $result) => $this->isViableMatch($result))
            ->sortByDesc('match_score')
            ->values();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A match is only viable if:
     *  1. Score meets the minimum threshold, AND
     *  2. At least one anchor signal (amount / name / reference) fired.
     *     Pure date-proximity hits are rejected regardless of score.
     */
    private function isViableMatch(array $result): bool
    {
        if ($result['match_score'] < self::MIN_SCORE) {
            return false;
        }

        $hasAnchor = !empty(array_intersect($result['match_reasons'], self::REQUIRED_REASONS));

        return $hasAnchor;
    }

    private function score(BankTransaction $transaction, Invoice $invoice): array
    {
        $score   = 0;
        $reasons = [];

        // ── 1. Amount match (highest signal) ─────────────────────────────────
        $txAmount   = (float) $transaction->amount;
        $amountDue  = (float) $invoice->amount_due;
        $amountDiff = abs($txAmount - $amountDue);
        $tolerance  = $amountDue * self::AMOUNT_TOLERANCE;

        if ($amountDiff === 0.0) {
            $score     += 50;
            $reasons[]  = 'Exact amount match';
        } elseif ($amountDiff <= $tolerance) {
            $score     += 35;
            $reasons[]  = 'Near-exact amount (within 2%)';
        } elseif ($amountDiff <= $amountDue * 0.10) {
            $score     += 15;
            $reasons[]  = 'Amount within 10% (possible TDS/deduction)';
        }

        // ── 2. Party name match ───────────────────────────────────────────────
        if ($transaction->party_name && $invoice->client_name) {
            $txParty       = strtolower(trim($transaction->party_name));
            $invoiceClient = strtolower(trim($invoice->client_name));

            if ($txParty === $invoiceClient) {
                $score     += 30;
                $reasons[]  = 'Client name matches exactly';
            } elseif (str_contains($invoiceClient, $txParty) || str_contains($txParty, $invoiceClient)) {
                $score     += 20;
                $reasons[]  = 'Client name partially matches';
            } else {
                similar_text($txParty, $invoiceClient, $pct);
                if ($pct >= 70) {
                    $score     += 10;
                    $reasons[]  = "Client name ~{$pct}% similar";
                }
            }
        }

        // ── 3. Date proximity ─────────────────────────────────────────────────
        $daysDiff = abs($transaction->transaction_date->diffInDays($invoice->invoice_date));

        if ($daysDiff <= 3) {
            $score     += 15;
            $reasons[]  = 'Invoice date within 3 days';
        } elseif ($daysDiff <= 7) {
            $score     += 10;
            $reasons[]  = 'Invoice date within a week';
        } elseif ($daysDiff <= 30) {
            $score     += 5;
            $reasons[]  = 'Invoice date within a month';
        }

        // ── 4. Invoice number in bank reference ───────────────────────────────
        if ($transaction->bank_reference && str_contains(
                strtolower($transaction->bank_reference),
                strtolower($invoice->invoice_number)
            )) {
            $score     += 25;
            $reasons[]  = 'Invoice number found in bank reference';
        }

        return [
            'invoice'       => $invoice,
            'match_score'   => $score,
            'match_reasons' => $reasons,
        ];
    }
}
