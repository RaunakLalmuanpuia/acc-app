<?php

namespace App\Http\Controllers\Api\Banking;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\NarrationHead;
use App\Services\Banking\InvoiceMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * BankTransactionApiController
 * ============================================================================
 *
 * Returns pending and recently-reviewed bank transactions for the
 * authenticated user's company, together with narration heads and bank
 * account list — everything the frontend Narration page needs in one call.
 *
 * ----------------------------------------------------------------------------
 * AUTHENTICATION
 * ----------------------------------------------------------------------------
 *  Authorization : Bearer <sanctum-token>
 *  Accept        : application/json
 *
 * ----------------------------------------------------------------------------
 * ENDPOINT
 * ----------------------------------------------------------------------------
 *  GET /api/banking/transactions/pending
 *
 * ============================================================================
 */
class BankTransactionApiController extends Controller
{
    public function __construct(private InvoiceMatchingService $matcher) {}

    // =========================================================================
    // pending()  —  GET /api/banking/transactions/pending
    // =========================================================================
    /**
     * Return paginated pending + recently-reviewed transactions.
     *
     * -------------------------------------------------------------------------
     * REQUEST HEADERS
     * -------------------------------------------------------------------------
     *  Authorization : Bearer <sanctum-token>   [REQUIRED]
     *  Accept        : application/json          [REQUIRED]
     *
     * -------------------------------------------------------------------------
     * QUERY PARAMETERS
     * -------------------------------------------------------------------------
     *  page     integer   OPTIONAL   Pagination page number. Default: 1
     *
     * -------------------------------------------------------------------------
     * RESPONSE 200 — Company exists and has a bank account:
     * -------------------------------------------------------------------------
     *  {
     *    "status":       "ok",
     *    "has_company":  true,
     *    "bank_accounts": [
     *      { "id": 1, "name": "HDFC Current", "account_number": "XXXX1234", ... }
     *    ],
     *    "heads": [
     *      {
     *        "id": 1, "name": "Revenue", "type": "credit",
     *        "active_sub_heads": [
     *          { "id": 10, "name": "Sales", "requires_party": false }
     *        ]
     *      }
     *    ],
     *    "transactions": {
     *      "data": [
     *        {
     *          "id":                    42,
     *          "raw_narration":         "NEFT/CR/INV001/CLIENT",
     *          "amount":                "15000.00",
     *          "type":                  "credit",
     *          "review_status":         "pending",
     *          "transaction_date":      "2024-06-15",
     *          "is_reconciled":         false,
     *          "is_uncertain":          true,
     *          "narration_head_id":     1,
     *          "narration_sub_head_id": null,
     *          "party_name":            null,
     *          "narration_note":        null,
     *          "reasoning":             "Matched by amount and narration pattern.",
     *          "reconciled_invoice_id": null,
     *          "reconciled_invoice":    null,
     *          "invoice_suggestions": [
     *            {
     *              "id":             7,
     *              "invoice_number": "INV-2024-001",
     *              "client_name":    "Acme Corp",
     *              "amount_due":     15000,
     *              "total_amount":   15000,
     *              "invoice_date":   "2024-06-01",
     *              "status":         "unpaid",
     *              "match_score":    85,
     *              "match_reasons":  ["Amount matches", "Client name in narration"]
     *            }
     *          ]
     *        }
     *      ],
     *      "current_page":   1,
     *      "last_page":      3,
     *      "per_page":       25,
     *      "total":          72,
     *      "next_page_url":  "https://domain.com/api/banking/transactions/pending?page=2",
     *      "prev_page_url":  null
     *    }
     *  }
     *
     * -------------------------------------------------------------------------
     * RESPONSE 200 — No company found:
     * -------------------------------------------------------------------------
     *  {
     *    "status":       "ok",
     *    "has_company":  false,
     *    "bank_accounts": [],
     *    "heads":         [],
     *    "transactions":  null
     *  }
     *
     * -------------------------------------------------------------------------
     * RESPONSE 200 — Company found but no bank accounts added yet:
     * -------------------------------------------------------------------------
     *  {
     *    "status":       "ok",
     *    "has_company":  true,
     *    "bank_accounts": [],
     *    "heads":         [],
     *    "transactions":  null
     *  }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *  Method  : GET
     *  URL     : {{base_url}}/api/banking/transactions/pending
     *  Auth    : Bearer Token
     *  Headers : Accept → application/json
     *
     *  Optional query param for pagination:
     *    {{base_url}}/api/banking/transactions/pending?page=2
     *
     *  Tests tab — auto-save for downstream requests:
     *    const r = pm.response.json();
     *    if (r.bank_accounts?.length) {
     *      pm.collectionVariables.set("bank_account_id", r.bank_accounts[0].id);
     *    }
     *    if (r.transactions?.data?.length) {
     *      pm.collectionVariables.set("transaction_id", r.transactions.data[0].id);
     *    }
     * -------------------------------------------------------------------------
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function pending(Request $request): JsonResponse
    {
        $user    = $request->user();
        $company = $user->companies()->orderBy('id')->first();

        if (!$company) {
            return response()->json([
                'status'       => 'ok',
                'has_company'  => false,
                'bank_accounts'=> [],
                'heads'        => [],
                'transactions' => null,
            ]);
        }

        $bankAccounts  = BankAccount::where('company_id', $company->id)->get();
        $bankAccountId = $company->bankAccounts()->orderBy('id')->value('id');

        if (!$bankAccountId) {
            return response()->json([
                'status'       => 'ok',
                'has_company'  => true,
                'bank_accounts'=> $bankAccounts,
                'heads'        => [],
                'transactions' => null,
            ]);
        }

        $transactions = BankTransaction::with(['narrationHead', 'narrationSubHead', 'reconciledInvoice'])
            ->where('bank_account_id', $bankAccountId)
            ->where('is_duplicate', false)
            ->whereIn('review_status', ['pending', 'reviewed'])
            ->orderByDesc('transaction_date')
            ->paginate(25);

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

        return response()->json([
            'status'       => 'ok',
            'has_company'  => true,
            'bank_accounts'=> $bankAccounts,
            'heads'        => $heads,
            'transactions' => $transactions,
        ]);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

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
