<?php

namespace App\Http\Controllers\Api\Banking;

use App\Actions\Banking\ReviewNarrationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Banking\NarrationReviewRequest;
use App\Models\BankTransaction;
use Illuminate\Http\JsonResponse;

/**
 * ============================================================================
 * NarrationReviewApiController
 * ============================================================================
 *
 * Handles the three review actions a user can take on a bank transaction:
 *   approve  — accept the AI-suggested narration as-is
 *   correct  — override with user-specified head/sub-head and optional details
 *   reject   — mark the transaction as rejected / not applicable
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
 *  POST /api/banking/transactions/{transaction}/review/{action}
 *  where {action} is one of: approve | correct | reject
 *
 * ============================================================================
 */
class NarrationReviewApiController extends Controller
{
    public function __construct(private ReviewNarrationAction $action) {}

    // =========================================================================
    // handle()  —  POST /api/banking/transactions/{transaction}/review/{action}
    // =========================================================================
    /**
     * Process a review action on a single bank transaction.
     *
     * -------------------------------------------------------------------------
     * PATH PARAMETERS
     * -------------------------------------------------------------------------
     *  {transaction}  integer   REQUIRED   The BankTransaction ID.
     *                                      Get from GET /api/banking/transactions/pending
     *  {action}       string    REQUIRED   One of: approve | correct | reject
     *
     * -------------------------------------------------------------------------
     * REQUEST HEADERS
     * -------------------------------------------------------------------------
     *  Authorization : Bearer <sanctum-token>   [REQUIRED]
     *  Accept        : application/json          [REQUIRED]
     *  Content-Type  : application/json          [REQUIRED]
     *
     * -------------------------------------------------------------------------
     * REQUEST BODY — varies by action
     * -------------------------------------------------------------------------
     *
     *  ── ACTION: "approve" ────────────────────────────────────────────────────
     *  Accepts the AI suggestion without changes. No body required.
     *  {}
     *
     *  ── ACTION: "correct" ────────────────────────────────────────────────────
     *  Override with your own narration details.
     *
     *  Field                | Type    | Required | Notes
     *  ---------------------|---------|----------|------------------------------
     *  narration_head_id    | integer | YES      | Must exist in narration_heads
     *  narration_sub_head_id| integer | NO       | Must exist in narration_sub_heads
     *  party_name           | string  | NO       | Vendor / person name. Max 255
     *  narration_note       | string  | NO       | Additional details. Max 500
     *  save_as_rule         | boolean | NO       | Auto-categorize future similar
     *                       |         |          | narrations. Default: false
     *  invoice_id           | integer | NO       | Link to an invoice by its DB id
     *  invoice_number       | string  | NO       | Link by invoice number (server
     *                       |         |          | resolves to id). Max 100 chars.
     *                       |         |          | Use this OR invoice_id, not both.
     *  unreconcile          | boolean | NO       | Set true to remove an existing
     *                       |         |          | invoice link. Default: false
     *
     *  Example body for "correct":
     *  {
     *    "narration_head_id":     3,
     *    "narration_sub_head_id": 12,
     *    "party_name":            "Acme Supplies",
     *    "narration_note":        "Office stationery - June",
     *    "save_as_rule":          true,
     *    "invoice_id":            7,
     *    "unreconcile":           false
     *  }
     *
     *  ── ACTION: "reject" ─────────────────────────────────────────────────────
     *  Marks the transaction as rejected. No body required.
     *  {}
     *
     * -------------------------------------------------------------------------
     * RESPONSES
     * -------------------------------------------------------------------------
     *  200 — Success:
     *  {
     *    "status":  "ok",
     *    "message": "Transaction approved successfully."   // or corrected / rejected
     *  }
     *
     *  422 — Validation failed (correct action):
     *  {
     *    "message": "The narration head id field is required.",
     *    "errors": {
     *      "narration_head_id": ["The narration head id field is required."]
     *    }
     *  }
     *
     *  404 — Transaction not found:
     *  { "message": "No query results for model [BankTransaction]." }
     *
     *  422 — Invalid action in URL:
     *  { "message": "Invalid action." }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *  Method  : POST
     *  URL     : {{base_url}}/api/banking/transactions/{{transaction_id}}/review/correct
     *  Auth    : Bearer Token
     *  Headers : Accept → application/json
     *            Content-Type → application/json
     *
     *  Body (raw → JSON) for "correct":
     *  {
     *    "narration_head_id":     3,
     *    "narration_sub_head_id": 12,
     *    "party_name":            "Acme Supplies",
     *    "narration_note":        "Office stationery",
     *    "save_as_rule":          false,
     *    "invoice_id":            null,
     *    "unreconcile":           false
     *  }
     *
     *  Body (raw → JSON) for "approve" or "reject":
     *  {}
     *
     *  Three quick requests to set up in Postman:
     *    1. POST .../review/approve  → body: {}
     *    2. POST .../review/correct  → body: full narration object
     *    3. POST .../review/reject   → body: {}
     * -------------------------------------------------------------------------
     *
     * @param  NarrationReviewRequest $request
     * @param  BankTransaction        $transaction
     * @param  string                 $action
     * @return JsonResponse
     */
    public function handle(
        NarrationReviewRequest $request,
        BankTransaction        $transaction,
        string                 $action
    ): JsonResponse {
        match ($action) {
            'approve' => $this->action->approve($transaction),

            'correct' => $this->action->correct(
                transaction:        $transaction,
                narrationHeadId:    (int) $request->narration_head_id,
                narrationSubHeadId: (int) $request->narration_sub_head_id,
                narrationNote:      $request->narration_note,
                partyName:          $request->party_name,
                saveAsRule:         (bool) $request->input('save_as_rule', false),
                invoiceId:          $request->invoice_id    ? (int) $request->invoice_id : null,
                invoiceNumber:      $request->invoice_number,
                unreconcile:        (bool) $request->input('unreconcile', false),
            ),

            'reject' => $this->action->reject($transaction),

            default  => abort(422, 'Invalid action.'),
        };

        return response()->json([
            'status'  => 'ok',
            'message' => "Transaction {$action}d successfully.",
        ]);
    }
}
