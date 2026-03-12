<?php

namespace App\Http\Controllers\Api\Banking;

use App\Actions\Banking\IngestSmsTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\SmsIngestRequest;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;

/**
 * ============================================================================
 * SmsIngestApiController
 * ============================================================================
 *
 * Parses a raw bank SMS alert and creates a BankTransaction record.
 * The AI parser extracts amount, type (credit/debit), narration, and date
 * from the SMS text automatically.
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
 *  POST /api/banking/transactions/sms
 *
 * ============================================================================
 */
class SmsIngestApiController extends Controller
{
    public function __construct(private IngestSmsTransactionAction $action) {}

    // =========================================================================
    // __invoke()  —  POST /api/banking/transactions/sms
    // =========================================================================
    /**
     * Parse a raw bank SMS and create a transaction record.
     *
     * -------------------------------------------------------------------------
     * REQUEST HEADERS
     * -------------------------------------------------------------------------
     *  Authorization : Bearer <sanctum-token>   [REQUIRED]
     *  Accept        : application/json          [REQUIRED]
     *  Content-Type  : application/json          [REQUIRED]
     *
     * -------------------------------------------------------------------------
     * REQUEST BODY
     * -------------------------------------------------------------------------
     *  Field           | Type    | Required | Notes
     *  ----------------|---------|----------|----------------------------------
     *  bank_account_id | integer | YES      | Must exist in bank_accounts table.
     *                  |         |          | Get from GET /api/banking/transactions/pending
     *  raw_sms         | string  | YES      | The full raw SMS text. Min 10,
     *                  |         |          | max 1000 characters.
     *
     *  Example:
     *  {
     *    "bank_account_id": 1,
     *    "raw_sms": "INR 15,000.00 credited to A/c XX1234 on 15-Jun-24 by NEFT from ACME CORP Ref No 1234567890. Avl Bal: INR 1,25,000.00"
     *  }
     *
     * -------------------------------------------------------------------------
     * RESPONSES
     * -------------------------------------------------------------------------
     *  200 — Parsed and ingested successfully:
     *  {
     *    "status":  "ok",
     *    "message": "SMS ingested successfully."
     *  }
     *
     *  422 — Validation failed:
     *  {
     *    "message": "The raw sms field is required.",
     *    "errors":  { "raw_sms": ["The raw sms field is required."] }
     *  }
     *
     *  404 — Bank account not found:
     *  { "message": "No query results for model [BankAccount]." }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *  Method  : POST
     *  URL     : {{base_url}}/api/banking/transactions/sms
     *  Auth    : Bearer Token
     *  Headers : Accept → application/json
     *            Content-Type → application/json
     *
     *  Body (raw → JSON):
     *  {
     *    "bank_account_id": {{bank_account_id}},
     *    "raw_sms": "INR 15,000.00 credited to A/c XX1234 on 15-Jun-24 by NEFT from ACME CORP Ref No 1234567890. Avl Bal: INR 1,25,000.00"
     *  }
     *
     *  Tip: After success, call GET /api/banking/transactions/pending
     *  to see the newly ingested transaction appear in the pending list.
     * -------------------------------------------------------------------------
     *
     * @param  SmsIngestRequest $request
     * @return JsonResponse
     */
    public function __invoke(SmsIngestRequest $request): JsonResponse
    {
        $account = BankAccount::findOrFail($request->bank_account_id);

        $this->action->execute($request->raw_sms, $account);

        return response()->json([
            'status'  => 'ok',
            'message' => 'SMS ingested successfully.',
        ]);
    }
}
