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
 * ChatOrchestrator  (v5 — FIX 4 scope fix)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v4
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 4 SCOPE BUG (v4 regression):
 *
 *   v4 added $newerNonInvoice guards inside FIX 1 and the two FIX 2 branches
 *   with comments saying "fall through to single-intent fallback". But PHP has
 *   no labelled break — "falling through" only exited the nested if/else.
 *   Execution continued into the multi-intent group block's secondary/primary
 *   pruning section, which had no guard and returned ['invoice'] via the
 *   "Setup intents complete — primary only" path.
 *
 *   Concretely: the pruning block found [CLIENT_ID: and [INVENTORY_ITEM_ID:
 *   from the prior xyz invoice setup (no created_at filter), declared secondary
 *   intents complete, and returned ['invoice'] — overriding both FIX 4 guards.
 *
 * FIX:
 *   Replace scattered per-branch $newerNonInvoice checks with a single
 *   $skipToSingleIntent boolean computed ONCE before any branching.
 *   The entire multi-intent group block is wrapped in if (!$skipToSingleIntent)
 *   so when FIX 4 fires, ALL of that block — including the pruning section —
 *   is bypassed. Execution falls directly to the single-intent row fallback,
 *   which correctly returns the most recently active intent (client, narration,
 *   bank_transaction, etc.).
 *
 *   The $newerNonInvoice EXISTS query now runs at most once per turn.
 */
class ChatOrchestrator
{
    private ?string $activeInvoiceNumber = null;

    public function __construct(
        private readonly IntentRouterService    $router,
        private readonly AgentDispatcherService $dispatcher,
        private readonly ResponseMergerService  $merger,
        private readonly HitlService            $hitl,
        private readonly ObservabilityService   $observability,
        private readonly ScopeGuardService      $scopeGuard,
    ) {}

    public function handle(
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $attachments = [],
    ): array {
        $turnStart = microtime(true);
        $turnId    = Str::uuid()->toString();
        $this->observability->setTurnId($turnId);
        $this->activeInvoiceNumber = null;

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

        $intents = $this->router->resolve($message, $conversationId);

        Log::info('[ChatOrchestrator] Resolved intents', ['intents' => $intents]);

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

        if (empty($intents)) {
            return [
                'reply'           => $this->merger->unknownResponse(),
                'conversation_id' => $conversationId,
                'hitl_pending'    => false,
            ];
        }

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

    public function confirm(
        User   $user,
        string $pendingId,
        array  $attachments = [],
    ): array {
        $turnStart = microtime(true);
        $turnId    = Str::uuid()->toString();
        $this->observability->setTurnId($turnId);
        $this->activeInvoiceNumber = null;

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
            turnId:         $turnId,
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
     *  1. If a non-invoice scoped conversation is more recent than the invoice
     *     → skip all multi-intent logic, fall to single-intent fallback.
     *  2. If {id}:invoice is newer than the last multi-intent turn → ['invoice'].
     *  3. Multi-intent group: content-based completion check → ['invoice']
     *     if all setup intents are done and invoice already created.
     *  4. Multi-intent group: secondary/primary pruning.
     *  5. Fall back to the most recent single-intent row.
     */
    private function getLastIntents(string $conversationId): array
    {
        $scope = function ($q) use ($conversationId) {
            $q->where('conversation_id', $conversationId)
                ->orWhere('conversation_id', 'like', $conversationId . ':%');
        };

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

        // ── FIX 4: compute skip flag once ────────────────────────────────────
        //
        // If ANY non-invoice scoped conversation has an assistant message more
        // recent than the last invoice message, the user has moved on to a new
        // single-intent flow. Bypass the entire multi-intent block so the
        // single-intent fallback at the bottom selects the correct agent.
        //
        // This flag gates the multi-intent block as a whole — not individual
        // return statements inside it. That was the v4 bug: the pruning section
        // inside the block had no guard and still ran, returning ['invoice'].
        $skipToSingleIntent = false;

        if ($lastInvoiceMessage !== null) {
            $newerNonInvoice = DB::table('agent_conversation_messages')
                ->where('conversation_id', 'like', $conversationId . ':%')
                ->where('conversation_id', '!=', $conversationId . ':invoice')
                ->where('role', 'assistant')
                ->whereRaw("JSON_EXTRACT(meta, '$.intent') IS NOT NULL")
                ->where('created_at', '>', $lastInvoiceMessage->created_at)
                ->exists();

            if ($newerNonInvoice) {
                $skipToSingleIntent = true;
                Log::info('[ChatOrchestrator] Non-invoice context more recent than invoice — skipping to single-intent fallback', [
                    'conversation_id' => $conversationId,
                ]);
            }
        }

        // ── FIX 1: invoice timestamp wins (only when not skipping) ────────────
        if (!$skipToSingleIntent
            && $lastInvoiceMessage !== null
            && $lastMultiMessage !== null
            && $lastInvoiceMessage->created_at > $lastMultiMessage->created_at
        ) {
            Log::info('[ChatOrchestrator] Invoice more recent than multi-intent group — invoice only', [
                'conversation_id' => $conversationId,
            ]);
            $this->loadActiveInvoiceNumber($conversationId);
            return ['invoice'];
        }

        // ── Multi-intent group logic (skipped entirely when $skipToSingleIntent) ──
        if (!$skipToSingleIntent && $lastMultiMessage !== null) {
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

                    $invoiceAlreadyCreated = DB::table('agent_conversation_messages')
                        ->where('conversation_id', $conversationId . ':invoice')
                        ->where('role', 'assistant')
                        ->whereRaw("JSON_EXTRACT(meta, '$.invoice_number') IS NOT NULL")
                        ->where('created_at', '>=', $lastMultiMessage->created_at)
                        ->exists();

                    if ($invoiceAlreadyCreated) {
                        Log::info('[ChatOrchestrator] Invoice already created — setup complete', [
                            'conversation_id' => $conversationId,
                        ]);
                        $this->loadActiveInvoiceNumber($conversationId);
                        return ['invoice'];
                    }

                    foreach ($setupIntents as $setupIntent) {
                        $completionMarker = match ($setupIntent) {
                            'client'    => '[CLIENT_ID:',
                            'inventory' => '[INVENTORY_ITEM_ID:',
                            'narration' => '[NARRATION_HEAD_ID:',
                            default     => '✅',
                        };

                        $isDone = DB::table('agent_conversation_messages')
                            ->where('conversation_id', $conversationId . ':' . $setupIntent)
                            ->where('role', 'assistant')
                            ->where('content', 'LIKE', '%' . $completionMarker . '%')
                            ->where('created_at', '>=', $lastMultiMessage->created_at)
                            ->exists();

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

                // ── Secondary/primary pruning ─────────────────────────────────
                $primaryIntents   = ['bank_transaction', 'invoice'];
                $secondaryIntents = array_diff($intents, $primaryIntents);

                if (!empty($secondaryIntents)) {
                    $remainingSetup = [];

                    foreach ($secondaryIntents as $setupIntent) {
                        $completionMarker = match ($setupIntent) {
                            'client'    => '[CLIENT_ID:',
                            'inventory' => '[INVENTORY_ITEM_ID:',
                            'narration' => '[NARRATION_HEAD_ID:',
                            default     => '✅',
                        };

                        $isDone = DB::table('agent_conversation_messages')
                            ->where('conversation_id', $conversationId . ':' . $setupIntent)
                            ->where('role', 'assistant')
                            ->where('content', 'LIKE', '%' . $completionMarker . '%')
                            ->exists();

                        if (!$isDone) {
                            $remainingSetup[] = $setupIntent;
                        }
                    }

                    if (empty($remainingSetup)) {
                        $survivors = array_values(array_intersect($intents, $primaryIntents));

                        if (!empty($survivors)) {
                            Log::info('[ChatOrchestrator] Setup intents complete — primary only', [
                                'conversation_id' => $conversationId,
                                'dropped'         => array_values($secondaryIntents),
                                'remaining'       => $survivors,
                            ]);
                            if (in_array('invoice', $survivors)) {
                                $this->loadActiveInvoiceNumber($conversationId);
                            }
                            return $survivors;
                        }
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

    private function loadActiveInvoiceNumber(string $conversationId): void
    {
        // Primary: meta column (fast, exact)
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
                Log::info('[ChatOrchestrator] Active invoice number loaded from meta', [
                    'conversation_id' => $conversationId,
                    'invoice_number'  => $this->activeInvoiceNumber,
                ]);
                return;
            }
        }

        // Fallback: scan the most recent assistant message content
        // Scoped to the last multi-intent turn so we don't pick up older invoices
        $lastMultiAt = DB::table('agent_conversation_messages')
            ->where('conversation_id', 'like', $conversationId . ':%')
            ->where('role', 'assistant')
            ->whereRaw("JSON_EXTRACT(meta, '$.multi_intent') = true")
            ->orderByDesc('created_at')
            ->value('created_at');

        if ($lastMultiAt === null) return;

        $content = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId . ':invoice')
            ->where('role', 'assistant')
            ->where('created_at', '>=', $lastMultiAt)
            ->orderByDesc('created_at')
            ->value('content');

        if ($content && preg_match('/INV-\d{8}-\d+/', $content, $matches)) {
            $this->activeInvoiceNumber = $matches[0];

            Log::info('[ChatOrchestrator] Active invoice number loaded from content fallback', [
                'conversation_id' => $conversationId,
                'invoice_number'  => $this->activeInvoiceNumber,
            ]);
        }
    }
}
