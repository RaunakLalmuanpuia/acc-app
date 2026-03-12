<?php

namespace App\Http\Controllers\Api;

use App\Ai\ChatOrchestrator;
use App\Ai\Services\AttachmentBuilderService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * ============================================================================
 * AiChatApiController
 * ============================================================================
 *
 * Pure JSON API adapter for the AI Accounting Chat feature.
 *
 * This controller is the REST/JSON twin of the Inertia-based AiChatController.
 * It reuses the SAME ChatOrchestrator and AttachmentBuilderService — the only
 * difference is the response format: JsonResponse instead of RedirectResponse.
 *
 * ----------------------------------------------------------------------------
 * AUTHENTICATION  (Laravel Sanctum — installed via `php artisan install:api`)
 * ----------------------------------------------------------------------------
 *  Every request must carry a Bearer token in the Authorization header.
 *
 *  How to issue a token (add this to your AuthController or a dedicated route):
 *
 *    POST /api/login
 *    Body: { "email": "user@example.com", "password": "secret" }
 *    → { "token": "1|PlainTextToken..." }
 *
 *    $token = $user->createToken('api-token')->plainTextToken;
 *
 *  Then on EVERY request add:
 *    Authorization: Bearer 1|PlainTextToken...
 *    Accept: application/json
 *
 *  Revoke a token:
 *    $request->user()->currentAccessToken()->delete();
 *
 * ----------------------------------------------------------------------------
 * ENDPOINTS
 * ----------------------------------------------------------------------------
 *  POST /api/accounting/chat           → send()    Send a chat message
 *  POST /api/accounting/chat/confirm   → confirm() Confirm a HITL action
 *
 * ----------------------------------------------------------------------------
 * HITL (Human-in-the-Loop) FLOW
 * ----------------------------------------------------------------------------
 *  Some messages trigger destructive actions (bulk deletes, overwrites).
 *  The AI pauses and asks for explicit confirmation before executing them.
 *
 *  Step 1 — Client sends message:
 *    POST /api/accounting/chat
 *    { "message": "delete all draft invoices" }
 *    ← { hitl_pending: true, pending_id: "<uuid>" }
 *
 *  Step 2a — User confirms:
 *    POST /api/accounting/chat/confirm
 *    { "pending_id": "<uuid>", "conversation_id": "<uuid>" }
 *    ← { hitl_pending: false, reply: "Done. Deleted 5 invoices." }
 *
 *  Step 2b — User cancels:
 *    Discard the pending_id. No API call required.
 *
 * ============================================================================
 */
class AiChatApiController extends Controller
{
    public function __construct(
        private readonly ChatOrchestrator         $orchestrator,
        private readonly AttachmentBuilderService $attachmentBuilder,
    ) {}

    // =========================================================================
    // send()  —  POST /api/accounting/chat
    // =========================================================================
    /**
     * Send a chat message to the AI accounting assistant.
     *
     * -------------------------------------------------------------------------
     * REQUEST HEADERS
     * -------------------------------------------------------------------------
     *  Authorization : Bearer <sanctum-token>          [REQUIRED]
     *  Accept        : application/json                [REQUIRED]
     *  Content-Type  : multipart/form-data             [when uploading files]
     *                  application/json                [when no attachments]
     *
     * -------------------------------------------------------------------------
     * REQUEST BODY
     * -------------------------------------------------------------------------
     *  Field            | Type    | Required | Notes
     *  -----------------|---------|----------|--------------------------------
     *  message          | string  | YES      | Max 4000 chars
     *  conversation_id  | string  | NO       | UUID — omit to start a new thread
     *  attachments[]    | file[]  | NO       | Max 5 files, 20 MB each
     *                   |         |          | Allowed: pdf, csv, xlsx, xls,
     *                   |         |          |          docx, doc, txt,
     *                   |         |          |          png, jpg, jpeg, webp
     *
     * -------------------------------------------------------------------------
     * RESPONSES
     * -------------------------------------------------------------------------
     *  200 — Normal reply:
     *  {
     *    "status":          "ok",
     *    "reply":           "Here is invoice #INV-2024-001...",
     *    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
     *    "hitl_pending":    false,
     *    "pending_id":      null
     *  }
     *
     *  200 — HITL triggered (destructive action detected, confirmation needed):
     *  {
     *    "status":          "ok",
     *    "reply":           "⚠️ You are about to delete 3 invoices. Proceed?",
     *    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
     *    "hitl_pending":    true,
     *    "pending_id":      "7c9e6679-7425-40de-944b-e07fc1f90ae7"
     *  }
     *  → Save pending_id → call POST /api/accounting/chat/confirm to proceed.
     *
     *  422 — Validation failed:
     *  {
     *    "message": "The message field is required.",
     *    "errors":  { "message": ["The message field is required."] }
     *  }
     *
     *  500 — Orchestrator error:
     *  {
     *    "status":  "error",
     *    "message": "The assistant encountered an error. Please try again."
     *  }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *  Method  : POST
     *  URL     : {{base_url}}/api/accounting/chat
     *  Auth tab: Type = Bearer Token → paste Sanctum token
     *  Headers : Accept → application/json
     *
     *  Body (text-only message) — select "raw" → JSON:
     *  {
     *    "message": "Show me all unpaid invoices",
     *    "conversation_id": null
     *  }
     *
     *  Body (with file attachment) — select "form-data":
     *    Key: message          | Type: Text | Value: Summarize this invoice
     *    Key: conversation_id  | Type: Text | Value: (paste UUID or leave blank)
     *    Key: attachments[]    | Type: File | Value: (select file)
     *    NOTE: The key MUST be written as "attachments[]" with square brackets.
     *
     *  Auto-save response vars — paste in the Tests tab:
     *    const r = pm.response.json();
     *    pm.collectionVariables.set("conversation_id", r.conversation_id);
     *    if (r.hitl_pending) {
     *      pm.collectionVariables.set("pending_id", r.pending_id);
     *    }
     * -------------------------------------------------------------------------
     */
    public function send(Request $request): JsonResponse
    {
        set_time_limit(120);

        $request->validate([
            'message'         => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'attachments'     => ['nullable', 'array', 'max:5'],
            'attachments.*'   => [
                'file',
                'max:20480',
                'mimes:pdf,csv,xlsx,xls,docx,doc,txt,png,jpg,jpeg,webp',
            ],
        ]);

        $user           = $request->user();
        $message        = $request->input('message');
        $conversationId = $request->input('conversation_id');
        $attachments    = $this->attachmentBuilder->fromRequest($request);

        try {
            $result = $this->orchestrator->handle(
                user:           $user,
                message:        $message,
                conversationId: $conversationId,
                attachments:    $attachments,
            );

            return response()->json([
                'status'          => 'ok',
                'reply'           => $result['reply'],
                'conversation_id' => $result['conversation_id'],
                'hitl_pending'    => $result['hitl_pending'],
                'pending_id'      => $result['pending_id'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChatApiController] send() error', [
                'user_id' => $user->id,
                'message' => $message,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'The assistant encountered an error. Please try again in a moment.',
            ], 500);
        }
    }

    // =========================================================================
    // confirm()  —  POST /api/accounting/chat/confirm
    // =========================================================================
    /**
     * Confirm and execute a HITL-pending destructive action.
     *
     * Only call this after send() returned { hitl_pending: true }.
     * The pending_id is SINGLE-USE — it is consumed after this call.
     * Replaying an expired pending_id returns a 500.
     *
     * To CANCEL: discard the pending_id on the client. No server call needed.
     *
     * -------------------------------------------------------------------------
     * REQUEST HEADERS
     * -------------------------------------------------------------------------
     *  Authorization : Bearer <sanctum-token>           [REQUIRED]
     *  Accept        : application/json                 [REQUIRED]
     *  Content-Type  : multipart/form-data              [when re-attaching files]
     *                  application/json                 [when no files]
     *
     * -------------------------------------------------------------------------
     * REQUEST BODY
     * -------------------------------------------------------------------------
     *  Field            | Type    | Required | Notes
     *  -----------------|---------|----------|--------------------------------
     *  pending_id       | string  | YES      | UUID returned by send()
     *  conversation_id  | string  | NO       | UUID of the conversation thread
     *  attachments[]    | file[]  | NO       | Re-attach if the original send()
     *                   |         |          | request included files
     *
     * -------------------------------------------------------------------------
     * RESPONSES
     * -------------------------------------------------------------------------
     *  200 — Action executed:
     *  {
     *    "status":          "ok",
     *    "reply":           "Done. 3 draft invoices have been deleted.",
     *    "conversation_id": "550e8400-e29b-41d4-a716-446655440000",
     *    "hitl_pending":    false,
     *    "pending_id":      null
     *  }
     *
     *  422 — Validation failed:
     *  {
     *    "message": "The pending id field must be a valid UUID.",
     *    "errors":  { "pending_id": ["The pending id field must be a valid UUID."] }
     *  }
     *
     *  500 — Expired or already-used pending_id:
     *  {
     *    "status":  "error",
     *    "message": "The confirmation could not be processed. Please try again."
     *  }
     *
     * -------------------------------------------------------------------------
     * POSTMAN SETUP
     * -------------------------------------------------------------------------
     *  Method  : POST
     *  URL     : {{base_url}}/api/accounting/chat/confirm
     *  Auth tab: Type = Bearer Token → paste Sanctum token
     *  Headers : Accept → application/json
     *
     *  Body — select "raw" → JSON:
     *  {
     *    "pending_id":      "{{pending_id}}",
     *    "conversation_id": "{{conversation_id}}"
     *  }
     *
     *  {{pending_id}} and {{conversation_id}} are collection variables.
     *  They are auto-set by the Tests script in the "send" request above.
     * -------------------------------------------------------------------------
     */
    public function confirm(Request $request): JsonResponse
    {
        $request->validate([
            'pending_id'      => ['required', 'string', 'uuid'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'attachments'     => ['nullable', 'array', 'max:5'],
            'attachments.*'   => [
                'file',
                'max:20480',
                'mimes:pdf,csv,xlsx,xls,docx,doc,txt,png,jpg,jpeg,webp',
            ],
        ]);

        $user        = $request->user();
        $pendingId   = $request->input('pending_id');
        $attachments = $this->attachmentBuilder->fromRequest($request);

        try {
            $result = $this->orchestrator->confirm(
                user:        $user,
                pendingId:   $pendingId,
                attachments: $attachments,
            );

            return response()->json([
                'status'          => 'ok',
                'reply'           => $result['reply'],
                'conversation_id' => $result['conversation_id'],
                'hitl_pending'    => false,
                'pending_id'      => null,
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChatApiController] confirm() error', [
                'user_id'    => $user->id,
                'pending_id' => $pendingId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'The confirmation could not be processed. Please try again.',
            ], 500);
        }
    }
}
