<?php

namespace App\Ai\Services;

use App\Ai\Agents\BusinessProfileAgent;
use App\Ai\Agents\ClientAgent;
use App\Ai\Agents\InventoryAgent;
use App\Ai\Agents\InvoiceAgent;
use App\Ai\Agents\NarrationAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;

/**
 * AgentDispatcherService  (v2 — full IBM MAS alignment)
 *
 * Resolves an intent to its specialist agent, enriches the message with
 * inter-agent context (blackboard), dispatches the prompt, records
 * observability metrics, and writes conversation metadata atomically.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v1
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * BUG 1 FIXED — configureConversation now scopes conversation IDs per-intent
 *   for multi-intent turns. Previously $multiIntent and $intent were received
 *   but never used — all agents shared the same history (cross-contamination).
 *
 * BUG 2 FIXED — catch block now returns array{reply, conversation_id} instead
 *   of a bare string. The old errorResponse() return type caused a fatal
 *   TypeError in dispatchAll() when any agent failed.
 *
 * BUG 3 FIXED — meta update uses PHP-side JSON mutation instead of DB::raw
 *   string interpolation. The old code was an SQL injection vector.
 *
 * BUG 4 FIXED — dispatchAll() strips the ":intent" scope suffix when capturing
 *   the base conversation ID from a new session's first response. The old code
 *   stored the scoped ID as the base, causing the second agent to continue
 *   "{scopedId}:{intent2}" instead of "{baseId}:{intent2}".
 *
 * GAP 1 — AgentContextBlackboard injected into dispatchAll(). After each
 *   agent, its reply is written to the blackboard. Before each subsequent
 *   agent, buildMessage() prepends the prior context preamble. Agents treat
 *   prior work as established fact and skip redundant tool calls.
 *
 * GAP 3 — ObservabilityService injected via constructor. Every agent call
 *   records latency, token usage, estimated cost, and success/failure state.
 */
class AgentDispatcherService
{
    /**
     * Maps a domain intent string to its specialist agent FQCN.
     * Update this map whenever a new specialist is added.
     */
    private const AGENT_MAP = [
        'invoice'   => InvoiceAgent::class,
        'client'    => ClientAgent::class,
        'inventory' => InventoryAgent::class,
        'narration' => NarrationAgent::class,
        'business'  => BusinessProfileAgent::class,
    ];

    /**
     * Maps intent to the model name used by its specialist.
     * Used for accurate per-agent cost estimation in ObservabilityService.
     * Keep in sync with each agent's #[Model(...)] attribute.
     */
    private const AGENT_MODELS = [
        'invoice'   => 'gpt-4o',
        'client'    => 'gpt-4o',
        'inventory' => 'gpt-4o',
        'narration' => 'gpt-4o',
        'business'  => 'gpt-4o',
    ];

    public function __construct(
        private readonly ObservabilityService $observability,
    ) {}

    /**
     * Dispatch multiple intents sequentially, sharing an AgentContextBlackboard
     * so each specialist can read the prior agent's output before prompting.
     *
     * Sequential dispatch is intentional: multi-intent turns often have data
     * dependencies (ClientAgent creates the client → InvoiceAgent uses that ID).
     * The blackboard eliminates redundant lookups across this dependency chain.
     *
     * @param  string[]    $intents
     * @param  User        $user
     * @param  string      $message
     * @param  string|null $conversationId
     * @param  array       $attachments
     * @param  bool        $hitlConfirmed   True when this dispatch originates from a
     *                                      HITL confirm() call — agents must skip their
     *                                      own internal re-confirmation prompts.
     * @return array<string, array{reply: string, conversation_id: ?string}>
     */
    public function dispatchAll(
        array   $intents,
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $attachments    = [],
        bool    $hitlConfirmed  = false,
    ): array {
        $multiIntent        = count($intents) > 1;
        $results            = [];
        $baseConversationId = $conversationId;
        $blackboard         = new AgentContextBlackboard();

        foreach ($intents as $index => $intent) {

            $result = $this->dispatch(
                intent:         $intent,
                user:           $user,
                message:        $message,
                conversationId: $baseConversationId,
                multiIntent:    $multiIntent,
                attachments:    $attachments,
                blackboard:     $blackboard,
                hitlConfirmed:  $hitlConfirmed,
            );

            $results[$intent] = $result;

            // GAP 1: write this agent's reply to the blackboard so the next
            // specialist can reference it without a redundant tool call.
            $blackboard->record($intent, $result['reply']);

            // BUG 4 FIX: strip the ":intent" scope suffix before storing
            // the base conversation ID. In the old code, when conversationId
            // was null (new session), the first response held a scoped ID
            // (e.g. "uuid-here:invoice"). Storing that as the base caused the
            // second agent to receive "uuid-here:invoice:client" — wrong.
            if ($index === 0 && $baseConversationId === null) {
                $rawId = $result['conversation_id'] ?? null;

                $baseConversationId = ($multiIntent && $rawId !== null)
                    ? explode(':', $rawId)[0]
                    : $rawId;
            }
        }

        return $results;
    }

    /**
     * Dispatch a single intent to its specialist agent.
     *
     * @param  string                      $intent
     * @param  User                        $user
     * @param  string                      $message
     * @param  string|null                 $conversationId  Base (unscoped) ID
     * @param  bool                        $multiIntent
     * @param  array                       $attachments
     * @param  AgentContextBlackboard|null $blackboard      Shared context from prior agents
     * @param  bool                        $hitlConfirmed   Skip agent's own re-confirmation
     * @return array{reply: string, conversation_id: ?string}
     */
    public function dispatch(
        string                  $intent,
        User                    $user,
        string                  $message,
        ?string                 $conversationId,
        bool                    $multiIntent     = false,
        array                   $attachments     = [],
        ?AgentContextBlackboard $blackboard      = null,
        bool                    $hitlConfirmed   = false,
    ): array {
        $start = microtime(true);
        $model = self::AGENT_MODELS[$intent] ?? 'gpt-4o';

        try {
            $agent = $this->resolveAgent($intent, $user);

            // BUG 1 FIX: conversation ID is now properly scoped per-intent
            $agent = $this->configureConversation(
                agent:          $agent,
                user:           $user,
                conversationId: $conversationId,
                intent:         $intent,
                multiIntent:    $multiIntent,
            );

            Log::info('[AgentDispatcherService] Dispatching', [
                'intent'          => $intent,
                'user_id'         => $user->id,
                'conversation_id' => $conversationId,
                'multi_intent'    => $multiIntent,
                'blackboard_has'  => $blackboard?->all() ? array_keys($blackboard->all()) : [],
            ]);

            // GAP 1: build message with blackboard context preamble injected
            $prompt = $this->buildMessage(
                intent:        $intent,
                message:       $message,
                blackboard:    $blackboard,
                multiIntent:   $multiIntent,
                hitlConfirmed: $hitlConfirmed,
            );

            $response = $agent->prompt(
                prompt:      $prompt,
                attachments: $attachments,
            );

            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            // GAP 3: record per-agent observability metrics.
            //
            // WHY DB READ — NOT $response->usage:
            //   The Laravel AI SDK writes the `usage` JSON to the
            //   agent_conversation_messages row DURING or AFTER prompt() returns.
            //   By the time execution reaches this line, $response->usage is null
            //   on the response object — the data hasn't been set on it yet.
            //   The DB row, however, was committed synchronously by the SDK's
            //   persistence layer before prompt() returned control.
            //   Reading the most recent assistant row is safe: prompt() is
            //   synchronous and sequential, so there is no race condition.
            $scopedConversationId = $response->conversationId;
            $usageRow = DB::table('agent_conversation_messages')
                ->where('conversation_id', $scopedConversationId)
                ->where('role', 'assistant')
                ->where('agent', get_class($agent))
                ->orderByDesc('created_at')
                ->value('usage');

            $usageData    = ($usageRow && $usageRow !== '[]') ? json_decode($usageRow, true) : [];
            $inputTokens  = $usageData['prompt_tokens']     ?? null;
            $outputTokens = $usageData['completion_tokens'] ?? null;

            $this->observability->recordAgentCall(
                intent:         $intent,
                userId:         (string) $user->id,
                conversationId: $conversationId,
                model:          $model,
                latencyMs:      $latencyMs,
                inputTokens:    $inputTokens,
                outputTokens:   $outputTokens,
                success:        true,
            );

            // BUG 3 FIX: PHP-side JSON mutation — no DB::raw string interpolation
            $this->writeMetaToMessage(
                conversationId: $response->conversationId,
                intent:         $intent,
                multiIntent:    $multiIntent,
            );

            return [
                'reply'           => (string) $response,
                'conversation_id' => $response->conversationId,
            ];

        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            // GAP 3: record failure metrics
            $this->observability->recordAgentCall(
                intent:         $intent,
                userId:         (string) $user->id,
                conversationId: $conversationId,
                model:          $model,
                latencyMs:      $latencyMs,
                success:        false,
                errorMessage:   $e->getMessage(),
            );

            Log::error("[AgentDispatcherService] {$intent} agent failed", [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            // BUG 2 FIX: return array, not bare string.
            // The old errorResponse() returned a string; dispatchAll() expected
            // array{reply, conversation_id} and crashed with TypeError.
            return [
                'reply'           => $this->errorResponse($intent),
                'conversation_id' => $conversationId,
            ];
        }
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Instantiate the specialist agent for the given intent.
     *
     * @throws \InvalidArgumentException If the intent has no registered agent.
     */
    private function resolveAgent(string $intent, User $user): Agent
    {
        if (!isset(self::AGENT_MAP[$intent])) {
            throw new \InvalidArgumentException(
                "No agent registered for intent: {$intent}"
            );
        }

        $class = self::AGENT_MAP[$intent];

        return new $class($user);
    }

    /**
     * Configure the agent's conversation context.
     *
     * BUG 1 FIX — scoping strategy:
     *   - Single-intent : use conversationId directly (unchanged behaviour).
     *   - Multi-intent  : scope as "{conversationId}:{intent}" so each specialist
     *     maintains independent memory. The frontend never sees scoped IDs.
     *
     * Previously this method received $intent and $multiIntent but never used them,
     * causing all agents in a multi-intent turn to share the same conversation
     * history — a silent cross-contamination bug.
     */
    private function configureConversation(
        Agent   $agent,
        User    $user,
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
    ): Agent {
        if ($conversationId === null) {
            // New session — SDK assigns a fresh conversation ID
            return $agent->forUser($user);
        }

        $scopedId = $multiIntent
            ? "{$conversationId}:{$intent}"
            : $conversationId;

        return $agent->continue($scopedId, as: $user);
    }

    /**
     * Build the final prompt string to send to the specialist.
     *
     * Injects up to three layers (when applicable):
     *   1. HITL pre-authorization block — tells the agent this action was
     *      already confirmed by the human via the HITL checkpoint. The agent
     *      must NOT ask for confirmation again; it must execute immediately.
     *   2. Blackboard context preamble — prior agents' completed work so this
     *      specialist can avoid redundant tool calls (GAP 1).
     *   3. Intent-scoping block — ensures the specialist ignores other domains
     *      in a multi-intent message.
     */
    private function buildMessage(
        string                  $intent,
        string                  $message,
        ?AgentContextBlackboard $blackboard,
        bool                    $multiIntent,
        bool                    $hitlConfirmed  = false,
    ): string {
        // ── Layer 1: HITL pre-authorization ───────────────────────────────
        // Injected FIRST so it overrides any "confirm before acting" rule in
        // the agent's own instructions. Without this, agents with deletion
        // confirmation rules (ClientAgent, InvoiceAgent, etc.) will ask the
        // user to confirm again even though HITL already handled it.
        $hitlBlock = '';
        if ($hitlConfirmed) {
            $hitlBlock = <<<HITL
            ╔══════════════════════════════════════════════════════════════════╗
            ║  ✅ HITL PRE-AUTHORIZED — PROCEED WITHOUT RE-CONFIRMING          ║
            ╠══════════════════════════════════════════════════════════════════╣
            ║  This action was reviewed and explicitly confirmed by the human  ║
            ║  user via the Human-in-the-Loop checkpoint.                      ║
            ║                                                                  ║
            ║  RULE: Execute the operation WITHOUT asking the user to confirm. ║
            ║  You MAY call read-only tools (search, get details) to locate    ║
            ║  the correct record before acting — this is encouraged.          ║
            ║  Do NOT pause at any point to ask "are you sure?".               ║
            ║  Do NOT warn about irreversibility — the user already agreed.    ║
            ╚══════════════════════════════════════════════════════════════════╝

            HITL;
        }

        // ── Layer 2: Blackboard context ───────────────────────────────────
        $preamble = ($blackboard !== null && !$blackboard->isEmpty())
            ? $blackboard->buildContextPreamble($intent)
            : '';

        if (!$multiIntent) {
            return $hitlBlock . $preamble . $message;
        }

        // ── Layer 3: Intent-scoping (multi-intent only) ───────────────────
        return <<<PROMPT
        {$hitlBlock}{$preamble}The user message may contain requests for multiple domains.

        You are ONLY responsible for the "{$intent}" domain.

        Ignore all parts of the message unrelated to "{$intent}".
        Do not mention other domains in your response.

        If prior agent context is provided above, treat it as established fact:
        - Do NOT re-fetch data that is already confirmed in the context.
        - Do NOT re-create resources that were already created.
        - Reference prior context to avoid redundant tool calls.

        User message:
        {$message}
        PROMPT;
    }

    /**
     * Atomically update the meta column on the latest assistant message.
     *
     * BUG 3 FIX — PHP-side JSON mutation replaces the old DB::raw string
     * interpolation, which was an SQL injection vector via the $intent variable.
     */
    private function writeMetaToMessage(
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
    ): void {
        if ($conversationId === null) {
            return;
        }

        DB::transaction(function () use ($conversationId, $intent, $multiIntent): void {
            $messageRow = DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->where('role', 'assistant')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($messageRow === null) {
                return;
            }

            // Decode existing meta safely, merge, re-encode — no raw SQL
            $meta                = json_decode($messageRow->meta ?? '{}', true) ?: [];
            $meta['intent']      = $intent;
            $meta['multi_intent'] = $multiIntent;

            DB::table('agent_conversation_messages')
                ->where('id', $messageRow->id)
                ->update(['meta' => json_encode($meta)]);
        });
    }

    /**
     * Build a domain-specific error message for the user.
     * Never exposes internal details or stack traces.
     */
    private function errorResponse(string $intent): string
    {
        $labels = [
            'invoice'   => 'invoice operations',
            'client'    => 'client management',
            'inventory' => 'inventory management',
            'narration' => 'narration head management',
            'business'  => 'business profile operations',
        ];

        $label = $labels[$intent] ?? $intent;

        return "I encountered an issue with {$label}. Please try again in a moment. "
            . "If the problem persists, please contact support.";
    }
}
