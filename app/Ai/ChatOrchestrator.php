<?php

namespace App\Ai;

use App\Ai\Services\AgentDispatcherService;
use App\Ai\Services\HitlService;
use App\Ai\Services\IntentRouterService;
use App\Ai\Services\ObservabilityService;
use App\Ai\Services\ResponseMergerService;
use App\Ai\Services\ScopeGuardService;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ChatOrchestrator  (v2 — full IBM MAS alignment)
 *
 * The coordination layer for the multi-agent accounting chat system.
 * This class performs NO AI reasoning — it is a pure PHP coordinator.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * UPDATED FLOW (v2)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. Receive (user, message, conversationId, attachments) from the controller.
 *  2. IntentRouterService  → resolve domain intent(s) via RouterAgent.
 *  3. DB fallback          → reuse last intent from conversation history if empty.
 *  4. Unknown gate         → return static reply at zero AI cost if still empty.
 *  5. HITL checkpoint      → intercept destructive operations BEFORE dispatch.
 *     a. Store action in cache, return warning + pending_id to frontend.
 *     b. Frontend shows "Confirm / Cancel". User confirms → confirm() endpoint.
 *  6. AgentDispatcherService → dispatch to specialist(s) sequentially with
 *     AgentContextBlackboard for inter-agent communication (Gap 1).
 *  7. ObservabilityService → record turn-level metrics (Gap 3).
 *  8. ResponseMergerService  → merge replies into one coherent string.
 *  9. Return {reply, conversation_id, hitl_pending} to the controller.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * RETURN CONTRACT WITH FRONTEND (expanded in v2)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Normal response:
 *    { reply: string, conversation_id: string|null, hitl_pending: false }
 *
 *  HITL checkpoint triggered:
 *    { reply: string (warning), conversation_id: string|null,
 *      hitl_pending: true, pending_id: string (UUID) }
 *
 *  Frontend must:
 *    - When hitl_pending = true: show the warning + "Confirm" / "Cancel" buttons.
 *    - On "Confirm": POST /ai/chat/confirm with { pending_id }.
 *    - On "Cancel": discard and let the user amend their message.
 *    - The base conversation_id is unchanged in both flows.
 */
class ChatOrchestrator
{
    public function __construct(
        private readonly IntentRouterService    $router,
        private readonly AgentDispatcherService $dispatcher,
        private readonly ResponseMergerService  $merger,
        private readonly HitlService            $hitl,
        private readonly ObservabilityService   $observability,
        private readonly ScopeGuardService      $scopeGuard,
    ) {}

    /**
     * Handle a single chat turn end-to-end.
     *
     * @param  User        $user
     * @param  string      $message
     * @param  string|null $conversationId  Existing session ID, or null for new.
     * @param  array       $attachments     Optional AI SDK attachment objects.
     * @return array{
     *   reply: string,
     *   conversation_id: string|null,
     *   hitl_pending: bool,
     *   pending_id?: string
     * }
     */
    public function handle(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $attachments = [],
    ): array {
        $turnStart = microtime(true);
        $turnId = Str::uuid()->toString();
        $this->observability->setTurnId($turnId);
        // ── Step 0: Scope guard — zero AI cost, runs before everything ────────
        $guardResult = $this->scopeGuard->evaluate($message, (string) $user->id);

        if (!$guardResult->allowed) {
            return [
                'reply'           => $guardResult->response,
                'conversation_id' => $conversationId,
                'hitl_pending'    => false,
            ];
        }

        Log::info('[ChatOrchestrator] Handling message', [
            'user_id'         => $user->id,
            'conversation_id' => $conversationId,
            'message_preview' => mb_substr($message, 0, 80),
        ]);

        // ── Step 1: Route ──────────────────────────────────────────────────────
        $intents = $this->router->resolve($message, $conversationId);

        Log::info('[ChatOrchestrator] Resolved intents', ['intents' => $intents]);

        // ── Step 2: DB fallback — reuse last intent from conversation history ──
        // Triggered when the router returns [] AND a prior conversation exists.
        // Handles follow-up messages like "make it ₹5000 instead" where the
        // router cannot classify without domain context.
        if (empty($intents) && $conversationId !== null) {
            $lastIntents = $this->getLastIntents($conversationId); // renamed + returns array

            if (!empty($lastIntents)) {
                Log::info('[ChatOrchestrator] Reusing previous intents from DB', [
                    'conversation_id' => $conversationId,
                    'intents'         => $lastIntents,
                ]);

                $intents = $lastIntents;
            }
        }

        // ── Step 3: Unknown gate — zero AI cost for off-topic messages ─────────
        if (empty($intents)) {
            return [
                'reply'           => $this->merger->unknownResponse(),
                'conversation_id' => $conversationId,
                'hitl_pending'    => false,
            ];
        }

        // ── Step 4: HITL checkpoint — intercept destructive operations ─────────
        //
        // IBM governance pattern: a hard checkpoint before any irreversible action.
        // The specialist agent is NOT dispatched until the user explicitly confirms.
        //
        // Flow:
        //   a) requiresCheckpoint() detects destructive keywords for guarded intents.
        //   b) storePendingAction() persists the full turn context in cache (TTL 15m).
        //   c) A warning + pending_id is returned to the frontend instead of a reply.
        //   d) Frontend shows "Confirm / Cancel". Confirm → POST /ai/chat/confirm.
        //   e) confirm() below retrieves, validates, and re-dispatches the action.
        if ($this->hitl->requiresCheckpoint($message, $intents)) {
            $pendingId = $this->hitl->storePendingAction(
                userId:         (string) $user->id,
                message:        $message,
                intents:        $intents,
                conversationId: $conversationId,
            );

            Log::info('[ChatOrchestrator] HITL checkpoint triggered', [
                'user_id'    => $user->id,
                'intents'    => $intents,
                'pending_id' => $pendingId,
            ]);

            return [
                'reply'           => $this->hitl->buildCheckpointMessage($message, $intents),
                'conversation_id' => $conversationId,
                'hitl_pending'    => true,
                'pending_id'      => $pendingId,
            ];
        }

        // ── Step 5 & 6: Dispatch + merge ──────────────────────────────────────
        return $this->executeDispatch(
            user:           $user,
            message:        $message,
            conversationId: $conversationId,
            intents:        $intents,
            attachments:    $attachments,
            turnStart:      $turnStart,
            turnId: $turnId
        );
    }

    /**
     * Resume a HITL-gated action after the user explicitly confirms.
     *
     * Called by AiChatController::confirm() when the user clicks "Confirm".
     * Retrieves the pending action from cache, validates ownership,
     * and re-dispatches without re-triggering the HITL check.
     *
     * @param  User   $user
     * @param  string $pendingId  UUID returned in the HITL checkpoint response
     * @param  array  $attachments  Re-attached files (original attachments cannot be cached)
     * @return array{reply: string, conversation_id: ?string, hitl_pending: false}
     */
    public function confirm(
        User   $user,
        string $pendingId,
        array  $attachments = [],
    ): array {
        $turnStart = microtime(true);

        // consumePendingAction() retrieves and immediately deletes — one-time use
        $action = $this->hitl->consumePendingAction($pendingId);

        if ($action === null) {
            Log::warning('[ChatOrchestrator] HITL action not found or expired', [
                'user_id'    => $user->id,
                'pending_id' => $pendingId,
            ]);

            return [
                'reply'           => "This confirmation has expired (15-minute limit). "
                    . "Please re-send your original request.",
                'conversation_id' => null,
                'hitl_pending'    => false,
            ];
        }

        // Security: confirm the requesting user owns the pending action
        if ((string) $user->id !== (string) $action['user_id']) {
            Log::warning('[ChatOrchestrator] HITL ownership mismatch', [
                'requesting_user' => $user->id,
                'action_user'     => $action['user_id'],
                'pending_id'      => $pendingId,
            ]);

            return [
                'reply'           => "You are not authorized to confirm this action.",
                'conversation_id' => null,
                'hitl_pending'    => false,
            ];
        }

        Log::info('[ChatOrchestrator] HITL confirmed — re-dispatching', [
            'user_id'    => $user->id,
            'pending_id' => $pendingId,
            'intents'    => $action['intents'],
        ]);

        // Re-dispatch directly — HITL is NOT re-triggered for confirmed actions.
        // hitlConfirmed: true injects the pre-authorization block into the agent's
        // prompt so it skips its own internal "are you sure?" confirmation step.
        return $this->executeDispatch(
            user:           $user,
            message:        $action['message'],
            conversationId: $action['conversation_id'],
            intents:        $action['intents'],
            attachments:    $attachments,
            turnStart:      $turnStart,
            hitlConfirmed:  true,
        );
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Execute the actual agent dispatch + merge + observability recording.
     *
     * Extracted to avoid duplication between handle() and confirm() — both
     * end in the same dispatch → merge → observe sequence.
     *
     * @param  bool $hitlConfirmed  When true, injects the HITL pre-authorization
     *                              block into every agent's prompt so they skip
     *                              their own internal re-confirmation prompts.
     */
    private function executeDispatch(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $intents,
        array   $attachments,
        float   $turnStart,
        string  $turnId,
        bool    $hitlConfirmed  = false,
    ): array {
        $responses = $this->dispatcher->dispatchAll(
            intents:        $intents,
            user:           $user,
            message:        $message,
            conversationId: $conversationId,
            attachments:    $attachments,
            hitlConfirmed:  $hitlConfirmed,
            turnId: $turnId
        );

        $first  = !empty($responses) ? reset($responses) : [];
        $rawId  = $conversationId ?? ($first['conversation_id'] ?? null);

        // Always use the base ID — strip any :intent scope suffix that
        // multi-intent agents write to their scoped conversations.
        $newConversationId = $rawId !== null
            ? explode(':', $rawId)[0]
            : null;

        $replyStrings = array_map(fn ($r) => $r['reply'], $responses);
        $reply        = $this->merger->merge($replyStrings);
        // GAP 3: record turn-level observability summary
        $totalLatencyMs = (int) ((microtime(true) - $turnStart) * 1000);
        $this->observability->recordTurnSummary(
            userId:         (string) $user->id,
            conversationId: $newConversationId,
            intents:        $intents,
            totalLatencyMs: $totalLatencyMs,
        );

        return [
            'reply'           => $reply,
            'conversation_id' => $newConversationId,
            'hitl_pending'    => false,
        ];
    }

    /**
     * Retrieve the last used intent from conversation message metadata.
     * Used as the DB fallback when the RouterAgent returns no valid intents.
     */
    /**
     * Retrieve the last used intents from conversation message metadata.
     *
     * For multi-intent turns, returns ALL intents from that turn (identified
     * by grouping messages within the same second, or by checking multi_intent flag).
     * Falls back to the single last intent for single-intent turns.
     *
     * @return string[]
     */
    private function getLastIntents(string $conversationId): array
    {
        $scope = function ($q) use ($conversationId) {
            $q->where('conversation_id', $conversationId)
                ->orWhere('conversation_id', 'like', $conversationId . ':%');
        };

        // ── Try multi-intent first ─────────────────────────────────────────────
        $lastMulti = DB::table('agent_conversation_messages')
            ->where($scope)
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.multi_intent') = true")
            ->whereRaw("JSON_EXTRACT(meta, '$.intent') IS NOT NULL")
            ->orderByDesc('created_at')
            ->first();

        if ($lastMulti !== null) {
            $meta   = json_decode($lastMulti->meta ?? '{}', true);
            $turnId = $meta['turn_id'] ?? null;

            $query = DB::table('agent_conversation_messages')
                ->where($scope)
                ->where('role', 'assistant')
                ->whereRaw("JSON_EXTRACT(meta, '$.multi_intent') = true");

            if ($turnId !== null) {
                $query->whereRaw("JSON_EXTRACT(meta, '$.turn_id') = ?", [$turnId]);
            } else {
                $ts = $lastMulti->created_at;
                $query->whereBetween('created_at', [
                    date('Y-m-d H:i:s', strtotime($ts) - 2),
                    date('Y-m-d H:i:s', strtotime($ts) + 2),
                ]);
            }

            $intents = $query
                ->select('meta')
                ->get()
                ->map(fn ($row) => json_decode($row->meta ?? '{}', true)['intent'] ?? null)
                ->filter()
                ->unique()
                ->values()
                ->toArray();

            if (!empty($intents)) {
                Log::info('[ChatOrchestrator] Reusing previous multi-intent group from DB', [
                    'conversation_id' => $conversationId,
                    'turn_id'         => $turnId,
                    'intents'         => $intents,
                ]);
                return $intents;
            }
        }

        // ── Fall back to most recent single-intent row ─────────────────────────
        $lastSingle = DB::table('agent_conversation_messages')
            ->where($scope)
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.intent') IS NOT NULL")
            ->orderByDesc('created_at')
            ->first();

        if ($lastSingle === null) return [];

        $meta   = json_decode($lastSingle->meta ?? '{}', true);
        $intent = $meta['intent'] ?? null;

        return $intent ? [$intent] : [];
    }
}
