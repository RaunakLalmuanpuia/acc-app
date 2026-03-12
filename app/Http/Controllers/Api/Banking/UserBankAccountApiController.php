<?php

namespace App\Http\Controllers\Api\Banking;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ============================================================================
 * UserBankAccountApiController
 * ============================================================================
 *
 * Returns the authenticated user's FIRST company and its FIRST bank account.
 *
 * This endpoint is mainly used by API clients (Postman / frontend / agents)
 * to quickly obtain a valid `bank_account_id` required for banking endpoints.
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
 *  GET /api/banking/user/first-bank-account
 *
 * ============================================================================
 */
class UserBankAccountApiController extends Controller
{
    // =========================================================================
    // firstBankAccount()  —  GET /api/banking/user/first-bank-account
    // =========================================================================
    /**
     * Returns the first company and first bank account belonging to
     * the authenticated user.
     *
     * -------------------------------------------------------------------------
     * REQUEST HEADERS
     * -------------------------------------------------------------------------
     *  Authorization : Bearer <sanctum-token>   [REQUIRED]
     *  Accept        : application/json          [REQUIRED]
     *
     * -------------------------------------------------------------------------
     * RESPONSE
     * -------------------------------------------------------------------------
     *
     *  200 — Success
     *
     *  {
     *    "status": "ok",
     *    "company_id": 1,
     *    "bank_account_id": 3
     *  }
     *
     * -------------------------------------------------------------------------
     *  404 — User has no company
     *
     *  {
     *    "status": "error",
     *    "message": "User has no company."
     *  }
     *
     * -------------------------------------------------------------------------
     *  404 — Company has no bank account
     *
     *  {
     *    "status": "error",
     *    "message": "Company has no bank account."
     *  }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *
     *  Method  : GET
     *  URL     : {{base_url}}/api/banking/user/first-bank-account
     *  Auth    : Bearer Token
     *  Headers : Accept → application/json
     *
     * -------------------------------------------------------------------------
     * POSTMAN TEST SCRIPT (Auto-save variables)
     * -------------------------------------------------------------------------
     *
     *  Add this in the **Tests** tab to automatically store the values
     *  for later API requests.
     *
     *  let response = pm.response.json();
     *
     *  pm.environment.set("company_id", response.company_id);
     *  pm.environment.set("bank_account_id", response.bank_account_id);
     *
     *  Now you can use:
     *
     *    {{bank_account_id}}
     *
     *  in future requests such as:
     *
     *  POST /api/banking/transactions/import-sms
     *
     * -------------------------------------------------------------------------
     *
     * @param  Request $request
     * @return JsonResponse
     */
    public function firstBankAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        $company = $user->companies()->first();

        if (!$company) {
            return response()->json([
                'status' => 'error',
                'message' => 'User has no company.',
            ], 404);
        }

        $bankAccount = $company->bankAccounts()->first();

        if (!$bankAccount) {
            return response()->json([
                'status' => 'error',
                'message' => 'Company has no bank account.',
            ], 404);
        }

        return response()->json([
            'status' => 'ok',
            'company_id' => $company->id,
            'bank_account_id' => $bankAccount->id,
        ]);
    }
}
