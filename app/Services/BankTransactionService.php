<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\NarrationHead;
use App\Models\NarrationSubHead;
use App\Models\User;

/**
 * BankTransactionService
 *
 * Centralises all database logic for bank transaction operations.
 * Tools are thin wrappers that delegate here — keeping the AI layer
 * free of Eloquent queries and making the logic independently testable.
 *
 * Authorization scope: user's first active company.
 * All methods resolve company_id internally via resolveCompanyId()
 * so tools never need to think about the User → Company chain.
 */
class BankTransactionService
{
    public function __construct(private readonly User $user) {}

    // ── Read ───────────────────────────────────────────────────────────────

    /**
     * Return filtered bank transactions scoped to the user's company.
     *
     * @return array{found: bool, count?: int, transactions?: array, message?: string}
     */
    public function getTransactions(
        ?string $fromDate      = null,
        ?string $toDate        = null,
        ?string $type          = null,
        ?string $reviewStatus  = null,
        ?bool   $isReconciled  = null,
        ?int    $bankAccountId = null,
        int     $limit         = 20,
    ): array {
        $companyId = $this->resolveCompanyId();

        $query = BankTransaction::query()
            ->whereHas('bankAccount', fn ($q) => $q->where('company_id', $companyId))
            ->with(['narrationHead', 'narrationSubHead', 'bankAccount'])
            ->orderByDesc('transaction_date')
            ->limit(min($limit, 50));

        if ($fromDate)              $query->whereDate('transaction_date', '>=', $fromDate);
        if ($toDate)                $query->whereDate('transaction_date', '<=', $toDate);
        if ($type)                  $query->where('type', $type);
        if ($reviewStatus)          $query->where('review_status', $reviewStatus);
        if ($isReconciled !== null) $query->where('is_reconciled', $isReconciled);
        if ($bankAccountId)         $query->where('bank_account_id', $bankAccountId);

        $transactions = $query->get();

        if ($transactions->isEmpty()) {
            return ['found' => false, 'transactions' => [], 'message' => 'No transactions matched the filters.'];
        }

        return [
            'found'        => true,
            'count'        => $transactions->count(),
            'transactions' => $transactions->map(fn ($t) => $this->formatTransaction($t))->toArray(),
        ];
    }

    /**
     * Find a single transaction by ID, scoped to the user's company.
     * Returns null if not found or out of scope.
     */
    public function findTransaction(int $id): ?BankTransaction
    {
        $companyId = $this->resolveCompanyId();

        return BankTransaction::query()
            ->whereHas('bankAccount', fn ($q) => $q->where('company_id', $companyId))
            ->find($id);
    }

    // ── Write ──────────────────────────────────────────────────────────────

    /**
     * Assign a narration category to a transaction.
     *
     * narration_head_id is always required.
     * narration_sub_head_id is optional — when omitted the transaction is
     * categorised at head level only (sub_head_id stored as null).
     *
     * When a sub-head is provided, the model's narrate() helper is used
     * directly (it derives head_id from the sub-head relationship).
     * When only a head is provided, we update the fields manually so that
     * narration_head_id is set and narration_sub_head_id is explicitly null.
     *
     * @return array{success: bool, error?: string, ...}
     */
    public function narrateTransaction(
        int     $transactionId,
        int     $headId,
        ?int    $subHeadId = null,
        string  $source    = 'manual',
        ?string $note      = null,
        ?string $partyName = null,
    ): array {
        $transaction = $this->findTransaction($transactionId);

        if (!$transaction) {
            return ['success' => false, 'error' => 'Transaction not found or does not belong to this user.'];
        }

        $head = NarrationHead::find($headId);

        if (!$head) {
            return ['success' => false, 'error' => 'Narration head not found.'];
        }

        if ($subHeadId !== null) {
            // Sub-head provided — validate it belongs to the given head
            $subHead = NarrationSubHead::where('narration_head_id', $headId)->find($subHeadId);

            if (!$subHead) {
                return ['success' => false, 'error' => 'Narration sub-head not found or does not belong to the specified head.'];
            }

            // Delegate to the model's narrate() helper (sets both IDs + review_status)
            $transaction->narrate(subHead: $subHead, source: $source, note: $note, partyName: $partyName);

            $narrationSubHeadName = $subHead->name;
        } else {
            // Head only — update fields directly, leave sub_head_id as null
            $transaction->update([
                'narration_head_id'     => $headId,
                'narration_sub_head_id' => null,
                'narration_source'      => $source,
                'narration_note'        => $note,
                'party_name'            => $partyName,
                'review_status'         => 'reviewed',
            ]);

            $narrationSubHeadName = null;
        }

        return [
            'success'            => true,
            'transaction_id'     => $transaction->id,
            'narration_head'     => $head->name,
            'narration_sub_head' => $narrationSubHeadName,
            'party_name'         => $transaction->fresh()->party_name,
            'note'               => $transaction->fresh()->narration_note,
            'review_status'      => $transaction->fresh()->review_status,
        ];
    }

    /**
     * Update the review_status (and optionally the note) on a transaction.
     *
     * @return array{success: bool, error?: string, ...}
     */
    public function updateReviewStatus(
        int     $transactionId,
        string  $reviewStatus,
        ?string $note = null,
    ): array {
        $allowed = ['pending', 'reviewed', 'flagged'];

        if (!in_array($reviewStatus, $allowed, true)) {
            return ['success' => false, 'error' => 'Invalid review_status. Must be one of: ' . implode(', ', $allowed)];
        }

        $transaction = $this->findTransaction($transactionId);

        if (!$transaction) {
            return ['success' => false, 'error' => 'Transaction not found or does not belong to this user.'];
        }

        $updates = ['review_status' => $reviewStatus];
        if ($note !== null) {
            $updates['narration_note'] = $note;
        }

        $transaction->update($updates);

        return [
            'success'        => true,
            'transaction_id' => $transaction->id,
            'review_status'  => $transaction->review_status,
            'note'           => $transaction->narration_note,
        ];
    }

    /**
     * Reconcile a bank transaction against a confirmed invoice.
     *
     * @return array{success: bool, error?: string, ...}
     */
    public function reconcileTransaction(int $transactionId, int $invoiceId): array
    {
        $companyId   = $this->resolveCompanyId();
        $transaction = $this->findTransaction($transactionId);

        if (!$transaction) {
            return ['success' => false, 'error' => 'Transaction not found or does not belong to this user.'];
        }

        if ($transaction->is_reconciled) {
            return [
                'success'               => false,
                'error'                 => 'This transaction is already reconciled.',
                'reconciled_invoice_id' => $transaction->reconciled_invoice_id,
            ];
        }

        $invoice = Invoice::where('company_id', $companyId)->find($invoiceId);

        if (!$invoice) {
            return ['success' => false, 'error' => 'Invoice not found or does not belong to this user.'];
        }

        $transaction->update([
            'is_reconciled'         => true,
            'reconciled_invoice_id' => $invoiceId,
            'reconciled_at'         => now(),
            'review_status'         => 'reviewed',
        ]);

        return [
            'success'               => true,
            'transaction_id'        => $transaction->id,
            'reconciled_invoice_id' => $transaction->reconciled_invoice_id,
            'reconciled_at'         => $transaction->fresh()->reconciled_at->toDateString(),
            'amount'                => number_format($transaction->amount, 2),
            'type'                  => $transaction->type,
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────

    /**
     * Resolve the user's first active company ID.
     * All authorization scoping uses company_id — not user_id directly —
     * because the model chain is User → Company → BankAccount → BankTransaction.
     */
    private function resolveCompanyId(): int
    {
        return Company::where('user_id', $this->user->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->value('id')
            ?? throw new \RuntimeException('No active company found for user ' . $this->user->id);
    }

    /**
     * Format a BankTransaction for API/tool output.
     */
    private function formatTransaction(BankTransaction $t): array
    {
        return [
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
            'bank_account'       => $t->bankAccount?->account_name,
        ];
    }
}
