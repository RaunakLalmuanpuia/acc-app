<?php

namespace App\Http\Controllers;

use App\Actions\Banking\ProcessStatementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\StatementUploadRequest;
use App\Models\BankAccount;
use Illuminate\Http\RedirectResponse;

class StatementUploadController extends Controller
{
    public function __construct(private ProcessStatementAction $action) {}

    /**
     * POST /banking/transactions/statement
     */
    public function __invoke(StatementUploadRequest $request): RedirectResponse
    {
        // Increase execution time to 5 minutes
        set_time_limit(300);
        ini_set('max_execution_time', 300);

//        dd($request);
        $account = BankAccount::findOrFail($request->bank_account_id);

        // TODO: authorize($account->company_id === auth()->user()->company_id)

        $result = $this->action->execute($request->file('statement'), $account);
        $message = $this->buildMessage($result);

        // If absolutely everything failed, redirect back with an error
        if ($result['total'] > 0 && $result['imported'] === 0 && $result['duplicates'] === 0) {
            return back()->withErrors(['statement' => $message]);
        }

        // Otherwise, redirect back with a success message containing the summary
        return back()->with('success', $message);
    }

    private function buildMessage(array $result): string
    {
        return sprintf(
            'Statement processed: %d imported, %d duplicates skipped, %d failed out of %d total transactions.',
            $result['imported'],
            $result['duplicates'],
            $result['failed'],
            $result['total']
        );
    }
}
