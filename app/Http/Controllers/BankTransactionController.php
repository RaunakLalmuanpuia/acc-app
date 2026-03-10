<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\NarrationHead;
use App\Models\NarrationSubHead;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankTransactionController extends Controller
{
    public function pending(Request $request): Response
    {
        $user = auth()->user();

        $company = $user->companies()->orderBy('id')->first();

        // No company yet — render page with empty state
        if (!$company) {
            return Inertia::render('Banking/PendingReviews', [
                'transactions' => null,
                'heads'        => [],
                'bankAccounts' => [],
                'hasCompany'   => false,
            ]);
        }


        $bankAccounts = BankAccount::where('company_id', $company->id)->get();

        // No bank account yet — still render, just no transactions
        $transactions = null;
        $heads = [];

        $bankAccountId = $company->bankAccounts()->orderBy('id')->value('id');

        if ($bankAccountId) {
            $transactions = BankTransaction::with(['narrationHead', 'narrationSubHead'])
                ->where('bank_account_id', $bankAccountId)
                ->where('is_duplicate', false)
                ->whereIn('review_status', ['pending', 'reviewed'])
                ->orderByDesc('transaction_date')
                ->paginate(25);

            $heads = NarrationHead::with('activeSubHeads')
                ->forCompany($company->id)
                ->active()
                ->orderBy('sort_order')
                ->get();
        }

        return Inertia::render('Banking/PendingReviews', [
            'transactions' => $transactions,
            'heads'        => $heads,
            'bankAccounts' => $bankAccounts,
            'hasCompany'   => $company !== null,
        ]);
    }
}
