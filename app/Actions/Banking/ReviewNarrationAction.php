<?php

namespace App\Actions\Banking;

use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\NarrationHead;
use App\Models\NarrationRule;
use App\Models\NarrationSubHead;
use Illuminate\Support\Facades\DB;

class ReviewNarrationAction
{
    public function __construct(private ReconcileTransactionAction $reconciler) {}

    public function approve(BankTransaction $transaction): BankTransaction
    {
        $transaction->update(['review_status' => 'reviewed']);
        return $transaction->fresh(['narrationHead', 'narrationSubHead']);
    }

    public function correct(
        BankTransaction $transaction,
        int             $narrationHeadId,
        int             $narrationSubHeadId,
        ?string         $narrationNote  = null,
        ?string         $partyName      = null,
        bool            $saveAsRule     = false,
        // ── Reconciliation (optional) ──────────────────────────
        ?int            $invoiceId      = null,
        ?string         $invoiceNumber  = null,
        bool            $unreconcile    = false,
    ): BankTransaction {
        return DB::transaction(function () use (
            $transaction, $narrationHeadId, $narrationSubHeadId,
            $narrationNote, $partyName, $saveAsRule,
            $invoiceId, $invoiceNumber, $unreconcile,
        ) {
            // ── 1. Update narration ───────────────────────────────────────
            $head    = NarrationHead::find($narrationHeadId);
            $subHead = NarrationSubHead::findOrFail($narrationSubHeadId);

            $transaction->update([
                'narration_head_id'     => $head->id,
                'narration_sub_head_id' => $subHead->id,
                'narration_note'        => $narrationNote,
                'party_name'            => $partyName ?? $transaction->party_name,
                'narration_source'      => 'manual',
                'review_status'         => 'reviewed',
            ]);

            // ── 2. Handle reconciliation change ───────────────────────────
            if ($unreconcile && $transaction->is_reconciled) {
                // User explicitly cleared the invoice link
                $this->reconciler->unreconcile($transaction);

            } elseif ($invoiceId || $invoiceNumber) {
                // User selected a (possibly new) invoice
                $companyId = $transaction->bankAccount->company_id;

                $invoice = $invoiceId
                    ? Invoice::findOrFail($invoiceId)
                    : Invoice::forCompany($companyId)
                        ->where('invoice_number', $invoiceNumber)
                        ->firstOrFail();

                // Only (re-)reconcile if not already linked to this same invoice
                if (!$transaction->is_reconciled || $transaction->reconciled_invoice_id !== $invoice->id) {
                    // If switching invoices, unreconcile the old one first
                    if ($transaction->is_reconciled) {
                        $this->reconciler->unreconcile($transaction);
                        $transaction->refresh();
                    }

                    $this->reconciler->execute($transaction, $invoice);
                }
            }

            // ── 3. Save learning rule ─────────────────────────────────────
            if ($saveAsRule && strlen($transaction->raw_narration) >= 4) {
                $this->createLearningRule($transaction, $subHead->narration_head_id, $subHead->id, $narrationNote);
            }

            return $transaction->fresh(['narrationHead', 'narrationSubHead', 'reconciledInvoice']);
        });
    }

    public function reject(BankTransaction $transaction): BankTransaction
    {
        $transaction->update([
            'review_status'         => 'flagged',
            'narration_head_id'     => null,
            'narration_sub_head_id' => null,
            'narration_note'        => null,
        ]);
        return $transaction->fresh();
    }

    private function createLearningRule(
        BankTransaction $transaction,
        int $headId,
        int $subHeadId,
        ?string $narrationNote
    ): NarrationRule {
        $matchValue = strtolower(trim(substr($transaction->raw_narration ?? '', 0, 30)));

        return NarrationRule::updateOrCreate(
            [
                'company_id'  => $transaction->bankAccount->company_id,
                'match_value' => $matchValue,
                'match_type'  => 'contains',
            ],
            [
                'transaction_type'      => $transaction->type,
                'narration_head_id'     => $headId,
                'narration_sub_head_id' => $subHeadId,
                'note_template'         => $narrationNote,
                'priority'              => 10,
                'is_active'             => true,
                'source'                => 'learned',
            ]
        );
    }
}
