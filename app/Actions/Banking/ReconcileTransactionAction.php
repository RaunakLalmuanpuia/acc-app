<?php

namespace App\Actions\Banking;

use App\Models\BankTransaction;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReconcileTransactionAction
{
    /**
     * Link a bank transaction to an invoice and record the payment.
     *
     * @throws ValidationException
     */
    public function execute(BankTransaction $transaction, Invoice $invoice): BankTransaction
    {
        // Guard: already reconciled
        if ($transaction->is_reconciled) {
            throw ValidationException::withMessages([
                'invoice' => 'This transaction has already been reconciled.',
            ]);
        }

        // Guard: invoice must belong to the same company
        $companyId = $transaction->bankAccount->company_id;
        if ($invoice->company_id !== $companyId) {
            throw ValidationException::withMessages([
                'invoice' => 'Invoice does not belong to this company.',
            ]);
        }

        // Guard: invoice must still have an outstanding balance
        if ($invoice->amount_due <= 0) {
            throw ValidationException::withMessages([
                'invoice' => "Invoice {$invoice->invoice_number} is already fully paid.",
            ]);
        }

        return DB::transaction(function () use ($transaction, $invoice) {
            // 1. Mark the transaction as reconciled
            $transaction->update([
                'is_reconciled'        => true,
                'reconciled_invoice_id'=> $invoice->id,
                'reconciled_at'        => now()->toDateString(),
            ]);

            // 2. Record the payment on the invoice using the existing model method.
            //    The transaction amount is used (may be partial, e.g. after TDS).
            $invoice->recordPayment((float) $transaction->amount);

            return $transaction->fresh(['narrationHead', 'narrationSubHead', 'reconciledInvoice']);
        });
    }

    /**
     * Undo a reconciliation — unlinks the transaction and reverses the invoice payment.
     */
    public function unreconcile(BankTransaction $transaction): BankTransaction
    {
        if (!$transaction->is_reconciled || !$transaction->reconciled_invoice_id) {
            throw ValidationException::withMessages([
                'invoice' => 'This transaction is not currently reconciled.',
            ]);
        }

        return DB::transaction(function () use ($transaction) {
            $invoice = $transaction->reconciledInvoice;

            // Reverse the payment on the invoice
            if ($invoice) {
                $reversedPaid = max(0, $invoice->amount_paid - $transaction->amount);
                $invoice->update([
                    'amount_paid' => $reversedPaid,
                    'amount_due'  => $invoice->total_amount - $reversedPaid,
                    'status'      => $reversedPaid > 0 ? 'partial' : 'sent',
                ]);
            }

            // Clear reconciliation fields
            $transaction->update([
                'is_reconciled'         => false,
                'reconciled_invoice_id' => null,
                'reconciled_at'         => null,
            ]);

            return $transaction->fresh(['narrationHead', 'narrationSubHead']);
        });
    }
}
