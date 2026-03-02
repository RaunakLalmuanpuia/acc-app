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
 * AiChatController
 *
 * Thin HTTP adapter for the accounting chat interface.
 *
 * This controller's only jobs are:
 *  1. Validate the incoming HTTP request.
 *  2. Build file attachments via AttachmentBuilderService.
 *  3. Delegate everything to ChatOrchestrator.
 *  4. Return the Inertia response.
 *
 * There is NO AI logic, NO agent calls, and NO tool usage here.
 * All dependencies are auto-resolved by Laravel's container — no
 * service provider or manual binding is required.
 */
class AiChatController extends Controller
{
    public function __construct(
        private readonly ChatOrchestrator        $orchestrator,
        private readonly AttachmentBuilderService $attachmentBuilder,
    ) {}

    /**
     * Render the Accounting Chat page.
     *
     * Passes a null conversationId so the frontend starts a fresh session.
     * To resume a previous session, look up the user's latest conversation
     * from `agent_conversations` and pass its ID here instead.
     */
    public function index(): Response
    {
        return Inertia::render('Accounting/Chat', [
            'conversationId' => null,
        ]);
    }

    /**
     * Handle a chat message sent via Inertia router.post().
     *
     * Form fields:
     *   message          string       (required)
     *   conversation_id  string|null  UUID of an existing conversation to continue
     *   attachments[]    file[]       (optional) — PDFs, images, spreadsheets
     *
     * Flashed to shared Inertia props (via HandleInertiaRequests):
     *   chatResponse.reply           string
     *   chatResponse.conversation_id string|null
     */
    public function send(Request $request): RedirectResponse
    {
        $request->validate([
            'message'         => ['required', 'string', 'max:4000'],
            'conversation_id' => ['nullable', 'string', 'uuid'],
            'attachments'     => ['nullable', 'array', 'max:5'],
            'attachments.*'   => [
                'file',
                'max:20480', // 20 MB per file
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
}
