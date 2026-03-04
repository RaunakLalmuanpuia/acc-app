<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * ReconcileTransaction — link a bank credit to a confirmed invoice.
 *
 * Sets is_reconciled = true, stores the invoice reference, and marks
 * review_status = 'reviewed'. This is financially irreversible — the
 * HITL checkpoint upstream handles the confirmation gate; the agent
 * must still verify the match with the user before calling this tool
 * when HITL is not active.
 */
class ReconcileTransaction implements Tool
{
    public function __construct(private readonly User $user) {}

    public function description(): Stringable|string
    {
        return 'Link a bank transaction to a confirmed invoice for reconciliation. '
            . 'Sets is_reconciled = true and stores the invoice reference on the transaction. '
            . 'Always present the match to the user and get explicit confirmation before calling. '
            . 'Returns an error if the transaction is already reconciled.';
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

        if ($transaction->is_reconciled) {
            return json_encode([
                'success'               => false,
                'error'                 => 'This transaction is already reconciled.',
                'reconciled_invoice_id' => $transaction->reconciled_invoice_id,
            ]);
        }

        $invoice = Invoice::whereHas('company', fn ($q) => $q->where('user_id', $this->user->id))
            ->find((int) $request['invoice_id']);

        if (!$invoice) {
            return json_encode([
                'success' => false,
                'error'   => 'Invoice not found or does not belong to this user.',
            ]);
        }

        $transaction->update([
            'is_reconciled'         => true,
            'reconciled_invoice_id' => (int) $request['invoice_id'],
            'reconciled_at'         => now(),
            'review_status'         => 'reviewed',
        ]);

        return json_encode([
            'success'               => true,
            'transaction_id'        => $transaction->id,
            'reconciled_invoice_id' => $transaction->reconciled_invoice_id,
            'reconciled_at'         => $transaction->fresh()->reconciled_at->toDateString(),
            'amount'                => number_format($transaction->amount, 2),
            'type'                  => $transaction->type,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required()->description('The ID of the bank transaction to reconcile'),
            'invoice_id'     => $schema->integer()->required()->description('The ID of the confirmed invoice to link to this transaction'),
        ];
    }
}
