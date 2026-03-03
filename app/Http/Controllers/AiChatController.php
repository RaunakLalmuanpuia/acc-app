<?php

namespace App\Http\Controllers;

use App\Ai\ChatOrchestrator;
use App\Ai\Services\AttachmentBuilderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * AiChatController  (v2 — HITL confirm endpoint added)
 *
 * Thin HTTP adapter for the accounting chat interface.
 *
 * This controller's only jobs are:
 *  1. Validate the incoming HTTP request.
 *  2. Build file attachments via AttachmentBuilderService.
 *  3. Delegate everything to ChatOrchestrator.
 *  4. Return the Inertia response with chatResponse flashed to shared props.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NEW IN v2 — confirm() endpoint
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * When the orchestrator returns hitl_pending = true, the frontend receives a
 * pending_id UUID. The user sees a warning + "Confirm / Cancel" buttons.
 *
 * "Confirm" → frontend POSTs to POST /ai/chat/confirm with:
 *   pending_id       string (UUID, required)
 *   attachments[]    file[] (optional — re-attach if original had files)
 *
 * "Cancel" → frontend discards the pending_id. No action needed on the backend.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * FRONTEND chatResponse SHAPE (v2)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Normal:
 *    { reply, conversation_id, hitl_pending: false }
 *
 *  HITL triggered (from send()):
 *    { reply (warning text), conversation_id, hitl_pending: true, pending_id }
 *
 *  HITL confirmed (from confirm()):
 *    { reply, conversation_id, hitl_pending: false }
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * ROUTES TO ADD (web.php)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Route::get('/ai/chat',            [AiChatController::class, 'index'])->name('ai.chat');
 *  Route::post('/ai/chat',           [AiChatController::class, 'send'])->name('ai.chat.send');
 *  Route::post('/ai/chat/confirm',   [AiChatController::class, 'confirm'])->name('ai.chat.confirm');
 */
class AiChatController extends Controller
{
    public function __construct(
        private readonly ChatOrchestrator         $orchestrator,
        private readonly AttachmentBuilderService $attachmentBuilder,
    ) {}

    /**
     * Render the Accounting Chat page.
     */
    public function index(): Response
    {
        return Inertia::render('Accounting/Chat', [
            'conversationId' => null,
        ]);
    }

    /**
     * Handle a chat message.
     *
     * Form fields:
     *   message          string       (required)
     *   conversation_id  string|null  UUID of an existing conversation
     *   attachments[]    file[]       (optional)
     *
     * Flashes to chatResponse shared prop:
     *   reply           string
     *   conversation_id string|null
     *   hitl_pending    bool
     *   pending_id      string|null  (only when hitl_pending = true)
     */
    public function send(Request $request): RedirectResponse
    {
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

            return back()->with('chatResponse', [
                'reply'           => $result['reply'],
                'conversation_id' => $result['conversation_id'],
                'hitl_pending'    => $result['hitl_pending'],
                'pending_id'      => $result['pending_id'] ?? null,
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChatController] Unhandled orchestrator error', [
                'user_id' => $user->id,
                'message' => $message,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return back()->withErrors([
                'ai' => 'The assistant encountered an error. Please try again in a moment.',
            ]);
        }
    }

    /**
     * Confirm a HITL-pending destructive action.
     *
     * Called by the frontend when the user clicks "Confirm" on the warning
     * message. The pending_id was returned by send() when hitl_pending = true.
     *
     * Form fields:
     *   pending_id     string  (required, UUID)
     *   attachments[]  file[]  (optional — re-attach if original request had files)
     */
    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'pending_id'    => ['required', 'string', 'uuid'],
            'attachments'   => ['nullable', 'array', 'max:5'],
            'attachments.*' => [
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

            return back()->with('chatResponse', [
                'reply'           => $result['reply'],
                'conversation_id' => $result['conversation_id'],
                'hitl_pending'    => false,
                'pending_id'      => null,
            ]);

        } catch (\Throwable $e) {
            Log::error('[AiChatController] HITL confirm error', [
                'user_id'    => $user->id,
                'pending_id' => $pendingId,
                'error'      => $e->getMessage(),
                'trace'      => $e->getTraceAsString(),
            ]);

            return back()->withErrors([
                'ai' => 'The confirmation could not be processed. Please try again.',
            ]);
        }
    }
}
