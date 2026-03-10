<?php

namespace App\Http\Controllers;

use App\Actions\Banking\IngestEmailTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\EmailIngestRequest;
use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;

class EmailIngestController extends Controller
{
    public function __construct(private IngestEmailTransactionAction $action) {}

    /**
     * POST /banking/transactions/email
     */
    public function __invoke(EmailIngestRequest $request): RedirectResponse
    {
        $account = BankAccount::findOrFail($request->bank_account_id);

        $this->action->execute($request->buildRawEmail(), $account);

        return back()->with('success', 'Email transaction ingested successfully.');
    }
}
