<?php

namespace App\Http\Controllers\Api\Banking;

use App\Actions\Banking\IngestEmailTransactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\EmailIngestRequest;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;

/**
 * ============================================================================
 * EmailIngestApiController
 * ============================================================================
 *
 * Parses a bank notification email (subject + body) and creates a
 * BankTransaction record. Subject and body are combined internally before
 * being passed to the AI parser (same as the web version).
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
 *  POST /api/banking/transactions/email
 *
 * ============================================================================
 */
class EmailIngestApiController extends Controller
{
    public function __construct(private IngestEmailTransactionAction $action) {}

    // =========================================================================
    // __invoke()  —  POST /api/banking/transactions/email
    // =========================================================================
    /**
     * Parse a bank notification email and create a transaction record.
     *
     * The email_subject and email_body are merged into a single string
     * (Subject: ... \n\n <body>) before being sent to the parser — identical
     * to what EmailIngestRequest::buildRawEmail() does on the web side.
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
     *  bank_account_id | integer | YES      | Must exist in bank_accounts table
     *  email_subject   | string  | NO       | Subject line. Max 500 chars.
     *                  |         |          | Helps the parser but is optional.
     *  email_body      | string  | YES      | Full email body text. Min 10,
     *                  |         |          | max 10000 characters.
     *
     *  Example:
     *  {
     *    "bank_account_id": 1,
     *    "email_subject": "Credit Alert - HDFC Bank",
     *    "email_body": "Dear Customer, INR 25,000.00 has been credited to your account XX1234 on 15-Jun-2024. Transaction ID: TXN98765. Available Balance: INR 1,50,000.00. If not done by you, call 1800-XXX-XXXX."
     *  }
     *
     * -------------------------------------------------------------------------
     * RESPONSES
     * -------------------------------------------------------------------------
     *  200 — Parsed and ingested successfully:
     *  {
     *    "status":  "ok",
     *    "message": "Email transaction ingested successfully."
     *  }
     *
     *  422 — Validation failed:
     *  {
     *    "message": "Please paste the email content.",
     *    "errors":  { "email_body": ["Please paste the email content."] }
     *  }
     *
     *  404 — Bank account not found:
     *  { "message": "No query results for model [BankAccount]." }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *  Method  : POST
     *  URL     : {{base_url}}/api/banking/transactions/email
     *  Auth    : Bearer Token
     *  Headers : Accept → application/json
     *            Content-Type → application/json
     *
     *  Body (raw → JSON):
     *  {
     *    "bank_account_id": {{bank_account_id}},
     *    "email_subject": "Credit Alert - HDFC Bank",
     *    "email_body": "Dear Customer, INR 25,000.00 has been credited to your account XX1234 on 15-Jun-2024. Transaction ID: TXN98765. Available Balance: INR 1,50,000.00."
     *  }
     *
     *  Tip: email_subject is optional — if you only have the email body, just
     *  omit the subject key entirely and send body alone.
     * -------------------------------------------------------------------------
     *
     * @param  EmailIngestRequest $request
     * @return JsonResponse
     */
    public function __invoke(EmailIngestRequest $request): JsonResponse
    {
        $account = BankAccount::findOrFail($request->bank_account_id);

        // buildRawEmail() merges subject + body, same as the web controller
        $this->action->execute($request->buildRawEmail(), $account);

        return response()->json([
            'status'  => 'ok',
            'message' => 'Email transaction ingested successfully.',
        ]);
    }
}
