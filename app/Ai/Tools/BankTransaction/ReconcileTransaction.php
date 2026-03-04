<?php

namespace App\Ai\Tools\BankTransaction;

use App\Models\User;
use App\Services\BankTransactionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReconcileTransaction implements Tool
{
    private BankTransactionService $service;

    public function __construct(User $user)
    {
        $this->service = new BankTransactionService($user);
    }

    public function description(): Stringable|string
    {
        return 'Link a bank transaction to a confirmed invoice for reconciliation. '
            . 'Sets is_reconciled = true and stores the invoice reference on the transaction. '
            . 'Always present the match to the user and get explicit confirmation before calling. '
            . 'Returns an error if the transaction is already reconciled.';
    }

    public function handle(Request $request): Stringable|string
    {
        $result = $this->service->reconcileTransaction(
            transactionId: (int) $request['transaction_id'],
            invoiceId:     (int) $request['invoice_id'],
        );

        return json_encode($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required()->description('The ID of the bank transaction to reconcile'),
            'invoice_id'     => $schema->integer()->required()->description('The ID of the confirmed invoice to link to this transaction'),
        ];
    }
}
