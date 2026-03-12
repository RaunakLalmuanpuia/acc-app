<?php

namespace App\Http\Controllers\Api\Banking;

use App\Actions\Banking\ProcessStatementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\StatementUploadRequest;
use App\Models\BankAccount;
use Illuminate\Http\JsonResponse;

/**
 * ============================================================================
 * StatementUploadApiController
 * ============================================================================
 *
 * Accepts a bank statement file (PDF, CSV, Excel, or Image), parses it, and
 * bulk-imports the transactions — skipping duplicates automatically.
 * Returns a summary of how many rows were imported, skipped, or failed.
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
 *  POST /api/banking/transactions/statement
 *
 * ============================================================================
 */
class StatementUploadApiController extends Controller
{
    public function __construct(private ProcessStatementAction $action) {}

    // =========================================================================
    // __invoke()  —  POST /api/banking/transactions/statement
    // =========================================================================
    /**
     * Upload and parse a bank statement file.
     *
     * Processing can take several minutes for large statements — the endpoint
     * sets a 5-minute execution limit internally. For very large files, consider
     * implementing this as a queued job in production.
     *
     * -------------------------------------------------------------------------
     * REQUEST HEADERS
     * -------------------------------------------------------------------------
     *  Authorization : Bearer <sanctum-token>   [REQUIRED]
     *  Accept        : application/json          [REQUIRED]
     *  Content-Type  : multipart/form-data       [REQUIRED — file upload]
     *
     * -------------------------------------------------------------------------
     * REQUEST BODY  (form-data)
     * -------------------------------------------------------------------------
     *  Field           | Type    | Required | Notes
     *  ----------------|---------|----------|----------------------------------
     *  bank_account_id | integer | YES      | Must exist in bank_accounts table.
     *                  |         |          | Send as Text field in form-data.
     *  statement       | file    | YES      | The statement file. Max 20 MB.
     *                  |         |          | Allowed: pdf, csv, xlsx, xls,
     *                  |         |          |          jpg, jpeg, png
     *
     * -------------------------------------------------------------------------
     * RESPONSES
     * -------------------------------------------------------------------------
     *  200 — Processing complete (even if some rows failed):
     *  {
     *    "status":  "ok",
     *    "message": "Statement processed: 45 imported, 3 duplicates skipped, 2 failed out of 50 total transactions.",
     *    "result": {
     *      "imported":   45,
     *      "duplicates": 3,
     *      "failed":     2,
     *      "total":      50
     *    }
     *  }
     *
     *  422 — Validation failed (wrong file type, too large, etc.):
     *  {
     *    "message": "Please upload a PDF, CSV, Excel (.xlsx/.xls), or Image (.jpg/.png) bank statement.",
     *    "errors": {
     *      "statement": ["Please upload a PDF, CSV, Excel (.xlsx/.xls), or Image (.jpg/.png) bank statement."]
     *    }
     *  }
     *
     *  422 — All rows failed to import (statement could not be parsed):
     *  {
     *    "status":  "error",
     *    "message": "Statement processed: 0 imported, 0 duplicates skipped, 10 failed out of 10 total transactions."
     *  }
     *
     *  404 — Bank account not found:
     *  { "message": "No query results for model [BankAccount]." }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *  Method  : POST
     *  URL     : {{base_url}}/api/banking/transactions/statement
     *  Auth    : Bearer Token
     *  Headers : Accept → application/json
     *            ← Do NOT manually set Content-Type; Postman sets it
     *               automatically to multipart/form-data when you use
     *               the form-data body tab.
     *
     *  Body (form-data):
     *    Key: bank_account_id  | Type: Text | Value: {{bank_account_id}}
     *    Key: statement        | Type: File | Value: (select your PDF/CSV/Excel file)
     *
     *  IMPORTANT — Postman file upload steps:
     *    1. Select "Body" tab → choose "form-data"
     *    2. Add key "bank_account_id" as Text
     *    3. Add key "statement" — hover the key field, change type dropdown to "File"
     *    4. Click "Select Files" and pick your statement
     *    5. Send — this may take 30–60 seconds for large statements
     *
     *  Tests tab — save result for debugging:
     *    const r = pm.response.json();
     *    console.log("Imported:", r.result?.imported);
     *    console.log("Duplicates:", r.result?.duplicates);
     * -------------------------------------------------------------------------
     *
     * @param  StatementUploadRequest $request
     * @return JsonResponse
     */
    public function __invoke(StatementUploadRequest $request): JsonResponse
    {
        set_time_limit(300);
        ini_set('max_execution_time', 300);

        $account = BankAccount::findOrFail($request->bank_account_id);

        $result  = $this->action->execute($request->file('statement'), $account);
        $message = $this->buildMessage($result);

        // Every row failed — treat as an error
        if ($result['total'] > 0 && $result['imported'] === 0 && $result['duplicates'] === 0) {
            return response()->json([
                'status'  => 'error',
                'message' => $message,
                'result'  => $result,
            ], 422);
        }

        return response()->json([
            'status'  => 'ok',
            'message' => $message,
            'result'  => $result,
        ]);
    }

    // ─── Private ──────────────────────────────────────────────────────────────

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
