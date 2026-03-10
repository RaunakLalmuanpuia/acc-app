<?php

namespace App\Http\Controllers;

use App\Actions\Banking\IngestSmsTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\SmsIngestRequest;
use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;

class SmsIngestController extends Controller
{
    public function __construct(private IngestSmsTransactionAction $action) {}

    /**
     * POST /banking/transactions/sms
     */
    public function __invoke(SmsIngestRequest $request): RedirectResponse
    {
        $account     = BankAccount::findOrFail($request->bank_account_id);
        $transaction = $this->action->execute($request->raw_sms, $account);

        // Redirect back so Inertia can reload the page data and show a success flash
        return back()->with('success', 'SMS ingested successfully.');
    }
}
