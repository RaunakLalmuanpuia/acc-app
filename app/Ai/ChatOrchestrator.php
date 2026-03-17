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
 * ChatOrchestrator  (v3 — invoice number injection + smart DB fallback)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v2
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 1 — getLastIntents(): timestamp-based completion detection
 *   If the {id}:invoice scoped conversation has a message newer than the last
 *   multi-intent turn, the setup phase is done — return ['invoice'] only.
 *   This prevents client/inventory agents from firing on follow-ups like
 *   "generate the pdf" or "review the invoice" after setup completes.
 *
 * FIX 2 — getLastIntents(): content-based completion fallback
 *   When timestamps are equal (same-turn creation), scan the reply content of
 *   each setup intent's scoped conversation. A setup intent is "done" when its
 *   last reply contains ✅ and no ⏳ and no trailing question.
 *
 * FIX 3 — activeInvoiceNumber injection
 *   When getLastIntents() returns ['invoice'], it also reads the stored
 *   invoice_number from meta and sets $this->activeInvoiceNumber.
 *   executeDispatch() passes this to the dispatcher, which injects it as a
 *   system hint into InvoiceAgent's prompt — giving it the invoice number even
 *   when its scoped conversation history is fragmented across turns.
 */
class ChatOrchestrator
{
    /**
     * Holds the active invoice number when detected during DB fallback.
     * Injected into InvoiceAgent's prompt to survive context fragmentation.
     */
    private ?string $activeInvoiceNumber = null;

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
     */
    public function handle(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $attachments = [],
    ): array {
        $turnStart = microtime(true);
        $turnId    = Str::uuid()->toString();
        $this->observability->setTurnId($turnId);
        $this->activeInvoiceNumber = null; // reset per turn

        // ── Step 0: Scope guard ───────────────────────────────────────────────
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

        // ── Step 1: Route ─────────────────────────────────────────────────────
        $intents = $this->router->resolve($message, $conversationId);

        Log::info('[ChatOrchestrator] Resolved intents', ['intents' => $intents]);

        // ── Step 2: DB fallback ───────────────────────────────────────────────
        if (empty($intents) && $conversationId !== null) {
            $lastIntents = $this->getLastIntents($conversationId);

            if (!empty($lastIntents)) {
                Log::info('[ChatOrchestrator] Reusing previous intents from DB', [
                    'conversation_id' => $conversationId,
                    'intents'         => $lastIntents,
                ]);
                $intents = $lastIntents;
            }
        }

        // ── Step 3: Unknown gate ──────────────────────────────────────────────
        if (empty($intents)) {
            return [
                'reply'           => $this->merger->unknownResponse(),
                'conversation_id' => $conversationId,
                'hitl_pending'    => false,
            ];
        }

        // ── Step 4: HITL checkpoint ───────────────────────────────────────────
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
            turnId:         $turnId,
        );
    }

    /**
     * Resume a HITL-gated action after explicit user confirmation.
     */
    public function confirm(
        User   $user,
        string $pendingId,
        array  $attachments = [],
    ): array {
        $turnStart = microtime(true);

        $action = $this->hitl->consumePendingAction($pendingId);

        if ($action === null) {
            Log::warning('[ChatOrchestrator] HITL action not found or expired', [
                'user_id'    => $user->id,
                'pending_id' => $pendingId,
            ]);
            return [
                'reply'           => "This confirmation has expired (15-minute limit). Please re-send your original request.",
                'conversation_id' => null,
                'hitl_pending'    => false,
            ];
        }

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

    private function executeDispatch(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $intents,
        array   $attachments,
        float   $turnStart,
        string  $turnId         = '',
        bool    $hitlConfirmed  = false,
    ): array {
        $responses = $this->dispatcher->dispatchAll(
            intents:             $intents,
            user:                $user,
            message:             $message,
            conversationId:      $conversationId,
            attachments:         $attachments,
            hitlConfirmed:       $hitlConfirmed,
            turnId:              $turnId,
            activeInvoiceNumber: $this->activeInvoiceNumber,
        );

        $first  = !empty($responses) ? reset($responses) : [];
        $rawId  = $conversationId ?? ($first['conversation_id'] ?? null);

        $newConversationId = $rawId !== null
            ? explode(':', $rawId)[0]
            : null;

        $replyStrings = array_map(fn($r) => $r['reply'], $responses);
        $reply        = $this->merger->merge($replyStrings);

        if (trim($reply) === '') {
            $reply = "I'm ready to continue — what would you like to do next?";
        }

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
     * Retrieve the last used intents from conversation message metadata.
     *
     * Priority order:
     *  1. If {id}:invoice has a message newer than the last multi-intent turn
     *     → invoice workflow is active, return ['invoice'] only.
     *  2. If multi-intent group exists, check content-based completion:
     *     all setup intents (client, inventory) show ✅ with no ⏳ → ['invoice'].
     *  3. Otherwise replay the full multi-intent group.
     *  4. Fall back to the most recent single-intent row.
     *
     * Side effect: sets $this->activeInvoiceNumber when returning ['invoice'],
     * so the dispatcher can inject it into InvoiceAgent's prompt.
     */
    private function getLastIntents(string $conversationId): array
    {
        $scope = function ($q) use ($conversationId) {
            $q->where('conversation_id', $conversationId)
                ->orWhere('conversation_id', 'like', $conversationId . ':%');
        };

        // ── FIX 1: timestamp comparison ───────────────────────────────────────
        // If {id}:invoice was updated more recently than the last multi-intent
        // message, setup is complete and the invoice workflow is active.
        $lastInvoiceMessage = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId . ':invoice')
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->first();

        $lastMultiMessage = DB::table('agent_conversation_messages')
            ->where($scope)
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.multi_intent') = true")
            ->orderByDesc('created_at')
            ->first();

        if ($lastInvoiceMessage !== null && $lastMultiMessage !== null) {
            if ($lastInvoiceMessage->created_at > $lastMultiMessage->created_at) {
                Log::info('[ChatOrchestrator] Invoice more recent than multi-intent group — invoice only', [
                    'conversation_id' => $conversationId,
                ]);
                $this->loadActiveInvoiceNumber($conversationId);
                return ['invoice'];
            }
        }

        // ── Multi-intent group logic ──────────────────────────────────────────
        if ($lastMultiMessage !== null) {
            $meta   = json_decode($lastMultiMessage->meta ?? '{}', true);
            $turnId = $meta['turn_id'] ?? null;

            $query = DB::table('agent_conversation_messages')
                ->where($scope)
                ->where('role', 'assistant')
                ->whereRaw("JSON_EXTRACT(meta, '$.multi_intent') = true");

            if ($turnId !== null) {
                $query->whereRaw("JSON_EXTRACT(meta, '$.turn_id') = ?", [$turnId]);
            } else {
                $ts = $lastMultiMessage->created_at;
                $query->whereBetween('created_at', [
                    date('Y-m-d H:i:s', strtotime($ts) - 2),
                    date('Y-m-d H:i:s', strtotime($ts) + 2),
                ]);
            }

            $rows = $query->select('meta')->get();

            $intents = $rows
                ->map(fn($row) => json_decode($row->meta ?? '{}', true)['intent'] ?? null)
                ->filter()->unique()->values()->toArray();

            if (!empty($intents)) {
                // ── FIX 2: content-based completion check ─────────────────────
                $setupIntents = array_diff($intents, ['invoice']);

                if (!empty($setupIntents) && in_array('invoice', $intents)) {
                    $allSetupDone = true;

                    foreach ($setupIntents as $setupIntent) {
                        $lastReply = DB::table('agent_conversation_messages')
                            ->where('conversation_id', $conversationId . ':' . $setupIntent)
                            ->where('role', 'assistant')
                            ->orderByDesc('created_at')
                            ->value('content');

                        $isDone = $lastReply !== null
                            && str_contains($lastReply, '✅')
                            && !str_contains($lastReply, '⏳')
                            && !preg_match('/\?\s*$/', trim($lastReply));

                        if (!$isDone) {
                            $allSetupDone = false;
                            break;
                        }
                    }

                    if ($allSetupDone) {
                        Log::info('[ChatOrchestrator] Setup complete (content check) — invoice only', [
                            'conversation_id' => $conversationId,
                            'setup_intents'   => $setupIntents,
                        ]);
                        $this->loadActiveInvoiceNumber($conversationId);
                        return ['invoice'];
                    }
                }

                Log::info('[ChatOrchestrator] Reusing previous multi-intent group from DB', [
                    'conversation_id' => $conversationId,
                    'turn_id'         => $turnId,
                    'intents'         => $intents,
                ]);
                return $intents;
            }
        }

        // ── Fall back to most recent single-intent row ────────────────────────
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

    /**
     * Load the most recently stored invoice_number from the scoped invoice
     * conversation's message meta into $this->activeInvoiceNumber.
     *
     * Called whenever getLastIntents() determines the invoice workflow is active.
     * The stored number is then injected into InvoiceAgent's prompt so it can
     * recover context even when its conversation history is fragmented.
     */
    private function loadActiveInvoiceNumber(string $conversationId): void
    {
        $metaJson = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId . ':invoice')
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.invoice_number') IS NOT NULL")
            ->orderByDesc('created_at')
            ->value('meta');

        if ($metaJson) {
            $decoded = json_decode($metaJson, true);
            $this->activeInvoiceNumber = $decoded['invoice_number'] ?? null;

            if ($this->activeInvoiceNumber) {
                Log::info('[ChatOrchestrator] Active invoice number loaded', [
                    'conversation_id' => $conversationId,
                    'invoice_number'  => $this->activeInvoiceNumber,
                ]);
            }
        }
    }
}
