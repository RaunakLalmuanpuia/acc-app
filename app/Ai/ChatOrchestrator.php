<?php

namespace App\Ai;

use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\IntentRouterService;
use App\Ai\Services\ResponseMergerService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
/**
 * ChatOrchestrator
 *
 * The coordination layer for the multi-agent accounting chat system.
 *
 * This class is NOT an AI agent — it performs no AI reasoning itself.
 * It is a plain PHP coordinator that wires the three services together.
 * All dependencies are resolved automatically by Laravel's container
 * via constructor type-hints — no service provider required.
 *
 * Flow:
 *   1. Receive (user, message, conversationId, attachments) from the controller.
 *   2. IntentRouterService  → resolve valid domain intent(s) via RouterAgent.
 *   3. If no valid intents  → return static unknown response (zero AI cost).
 *   4. AgentDispatcherService → dispatch to specialist agent(s) sequentially.
 *   5. ResponseMergerService  → merge replies into one coherent string.
 *   6. Return (reply, conversationId) to the controller.
 *
 * Conversation ID contract with the frontend:
 *   - Frontend always sends ONE base conversationId (or null for new sessions).
 *   - Scoping for multi-intent sessions is handled internally by AgentDispatcherService.
 *   - Frontend always receives the same base conversationId back.
 *   - The frontend requires zero changes from the monolith design.
 */
class ChatOrchestrator
{
    public function __construct(
        private readonly IntentRouterService    $router,
        private readonly AgentDispatcherService $dispatcher,
        private readonly ResponseMergerService  $merger,
    ) {}

    /**
     * Handle a single chat turn end-to-end.
     *
     * @param  User        $user              Authenticated user.
     * @param  string      $message           The user's raw message.
     * @param  string|null $conversationId    Existing session ID, or null for new.
     * @param  array       $attachments       Optional AI SDK attachment objects.
     * @return array{reply: string, conversation_id: string|null}
     */
    public function handle(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $attachments = [],
    ): array {
        Log::info('[ChatOrchestrator] Handling message', [
            'user_id'         => $user->id,
            'conversation_id' => $conversationId,
            'message_preview' => mb_substr($message, 0, 80),
        ]);

        // ── Step 1: Route ──────────────────────────────────────────────────────
        $intents = $this->router->resolve($message);

        Log::info('[ChatOrchestrator] Resolved intents', ['intents' => $intents]);

        // ───────────────────────────────────────────────────────────
        // 2️⃣ Reuse Previous Intents if Router Returned Empty
        // ───────────────────────────────────────────────────────────

        if (empty($intents) && $conversationId) {

            $lastIntent = $this->getLastIntent($conversationId);

            if ($lastIntent) {

                Log::info('[ChatOrchestrator] Reusing previous intent from DB', [
                    'conversation_id' => $conversationId,
                    'intent'          => $lastIntent,
                ]);

                $intents = [$lastIntent];
            }
        }

        // ── Step 2: Handle unknown / no valid domain intents ───────────────────
        // No AI agents are invoked — zero gpt-4o cost for greetings / off-topic.
        if (empty($intents)) {
            return [
                'reply'           => $this->merger->unknownResponse(),
                'conversation_id' => $conversationId,
            ];
        }

        // ── Step 3: Dispatch to specialist agent(s) sequentially ───────────────
        $responses = $this->dispatcher->dispatchAll(
            intents:        $intents,
            user:           $user,
            message:        $message,
            conversationId: $conversationId,
            attachments:    $attachments,
        );

        // First response holds base conversation ID
        $first = reset($responses);

        $newConversationId = $conversationId
            ?? ($first['conversation_id'] ?? null);

        // Extract only reply strings for merger
        $replyStrings = array_map(fn ($r) => $r['reply'], $responses);

        $reply = $this->merger->merge($replyStrings);



        return [
            'reply' => $reply,
            'conversation_id' => $newConversationId,
        ];
    }

    private function getLastIntent(string $conversationId): ?string
    {
        return DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->whereRaw("JSON_EXTRACT(meta, '$.intent') IS NOT NULL")
            ->orderByDesc('created_at')
            ->value(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(meta, '$.intent'))"));
    }
}
