<?php

namespace App\Services\Banking;

use App\Models\BankTransaction;
use App\Models\Invoice;
use Illuminate\Support\Collection;

class InvoiceMatchingService
{
    /**
     * Amount tolerance for fuzzy matching (e.g. 0.02 = within 2%).
     * Handles cases where the bank amount slightly differs due to TDS, rounding, etc.
     */
    private const AMOUNT_TOLERANCE = 0.02;

    /**
     * Date window (days) to search on either side of the transaction date.
     */
    private const DATE_WINDOW_DAYS = 30;

    /**
     * Return a ranked list of candidate invoices for a given transaction.
     * Each result includes a `match_score` and `match_reasons` array.
     *
     * @return Collection<array>
     */
    public function findCandidates(BankTransaction $transaction): Collection
    {
        $companyId = $transaction->bankAccount->company_id;

        // Credits = client paying us → match against unpaid/partial invoices
        // Debits  = we paying someone → match against debit notes (if any)
        $types = $transaction->isCredit()
            ? ['tax_invoice', 'proforma']
            : ['debit_note'];

        $candidates = Invoice::forCompany($companyId)
            ->whereIn('invoice_type', $types)
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->whereBetween('invoice_date', [
                $transaction->transaction_date->subDays(self::DATE_WINDOW_DAYS),
                $transaction->transaction_date->addDays(self::DATE_WINDOW_DAYS),
            ])
            ->with('client')
            ->get();

        return $candidates
            ->map(fn(Invoice $invoice) => $this->score($transaction, $invoice))
            ->filter(fn(array $result) => $result['match_score'] > 0)
            ->sortByDesc('match_score')
            ->values();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function score(BankTransaction $transaction, Invoice $invoice): array
    {
        $score   = 0;
        $reasons = [];

        // ── 1. Amount match (highest signal) ─────────────────────────────────
        $amountDiff = abs($transaction->amount - $invoice->amount_due);
        $tolerance  = $invoice->amount_due * self::AMOUNT_TOLERANCE;

        if ($amountDiff === 0.0) {
            $score  += 50;
            $reasons[] = 'Exact amount match';
        } elseif ($amountDiff <= $tolerance) {
            $score  += 35;
            $reasons[] = 'Near-exact amount (within 2%)';
        } elseif ($amountDiff <= $invoice->amount_due * 0.10) {
            $score  += 15;
            $reasons[] = 'Amount within 10% (possible TDS/deduction)';
        }

        // ── 2. Party name match ───────────────────────────────────────────────
        if ($transaction->party_name && $invoice->client_name) {
            $txParty      = strtolower(trim($transaction->party_name));
            $invoiceClient = strtolower(trim($invoice->client_name));

            if ($txParty === $invoiceClient) {
                $score  += 30;
                $reasons[] = 'Client name matches exactly';
            } elseif (str_contains($invoiceClient, $txParty) || str_contains($txParty, $invoiceClient)) {
                $score  += 20;
                $reasons[] = 'Client name partially matches';
            } else {
                // Fuzzy: similar_text gives a % similarity
                similar_text($txParty, $invoiceClient, $pct);
                if ($pct >= 70) {
                    $score  += 10;
                    $reasons[] = "Client name ~{$pct}% similar";
                }
            }
        }

        // ── 3. Date proximity ─────────────────────────────────────────────────
        $daysDiff = abs($transaction->transaction_date->diffInDays($invoice->invoice_date));

        if ($daysDiff <= 3) {
            $score  += 15;
            $reasons[] = 'Invoice date within 3 days';
        } elseif ($daysDiff <= 7) {
            $score  += 10;
            $reasons[] = 'Invoice date within a week';
        } elseif ($daysDiff <= 30) {
            $score  += 5;
            $reasons[] = 'Invoice date within a month';
        }

        // ── 4. Bank reference matches invoice number ──────────────────────────
        if ($transaction->bank_reference && str_contains(
                strtolower($transaction->bank_reference),
                strtolower($invoice->invoice_number)
            )) {
            $score  += 25;
            $reasons[] = 'Invoice number found in bank reference';
        }

        return [
            'invoice'       => $invoice,
            'match_score'   => $score,
            'match_reasons' => $reasons,
        ];
    }
}
