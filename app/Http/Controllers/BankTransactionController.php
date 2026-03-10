<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\NarrationHead;
use App\Services\Banking\InvoiceMatchingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BankTransactionController extends Controller
{
    public function __construct(private InvoiceMatchingService $matcher) {}

    public function pending(Request $request): Response
    {
        $user    = auth()->user();
        $company = $user->companies()->orderBy('id')->first();

        if (!$company) {
            return Inertia::render('Banking/PendingReviews', [
                'transactions' => null,
                'heads'        => [],
                'bankAccounts' => [],
                'hasCompany'   => false,
            ]);
        }

        $bankAccounts  = BankAccount::where('company_id', $company->id)->get();
        $transactions  = null;
        $heads         = [];
        $bankAccountId = $company->bankAccounts()->orderBy('id')->value('id');

        if ($bankAccountId) {
            $transactions = BankTransaction::with(['narrationHead', 'narrationSubHead', 'reconciledInvoice'])
                ->where('bank_account_id', $bankAccountId)
                ->where('is_duplicate', false)
                ->whereIn('review_status', ['pending', 'reviewed'])
                ->orderByDesc('transaction_date')
                ->paginate(25);

            // Attach invoice suggestions to each transaction.
            // Only run matching for unreconciled transactions to avoid wasted queries.
            $transactions->getCollection()->transform(function (BankTransaction $tx) {
                if (!$tx->is_reconciled) {
                    $tx->setAttribute('invoice_suggestions', $this->formatSuggestions(
                        $this->matcher->findCandidates($tx)
                    ));
                } else {
                    $tx->setAttribute('invoice_suggestions', []);
                }

                return $tx;
            });

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

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Flatten the scoring collection into a plain array safe to serialise as JSON.
     * We pass max 3 suggestions to keep the payload small.
     */
    private function formatSuggestions(\Illuminate\Support\Collection $scored): array
    {
        return $scored->take(3)->map(fn(array $r) => [
            'id'             => $r['invoice']->id,
            'invoice_number' => $r['invoice']->invoice_number,
            'client_name'    => $r['invoice']->client_name,
            'amount_due'     => (float) $r['invoice']->amount_due,
            'total_amount'   => (float) $r['invoice']->total_amount,
            'invoice_date'   => $r['invoice']->invoice_date->toDateString(),
            'status'         => $r['invoice']->status,
            'match_score'    => $r['match_score'],
            'match_reasons'  => $r['match_reasons'],
        ])->values()->all();
    }
}
