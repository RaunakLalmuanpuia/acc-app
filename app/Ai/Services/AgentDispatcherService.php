<?php

namespace App\Ai\Services;

use App\Ai\Services\AgentContextBlackboard;
use App\Ai\AgentRegistry;
use App\Ai\Agents\BaseAgent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Illuminate\Support\Str;

/**
 * AgentDispatcherService  (v3 — AgentRegistry-driven + outcome signals)
 *
 * Resolves an intent to its specialist agent, enriches the message with
 * inter-agent context (blackboard), dispatches the prompt, records
 * observability metrics, and writes conversation metadata atomically.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v2
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * AGENT_MAP and AGENT_MODELS removed — both are now sourced from AgentRegistry.
 *   Adding a new agent requires only one line in AgentRegistry::AGENTS.
 *   No changes needed here.
 *
 * OUTCOME SIGNAL added to recordAgentCall():
 *   After prompt() returns, dispatcher checks whether the agent called any write
 *   tools by comparing toolsUsed against BaseAgent::writeTools() for the intent.
 *   This populates ObservabilityService's new $outcomeSignal parameter:
 *     'completed'  → agent called at least one write tool, no trailing question
 *     'clarifying' → agent returned a question without calling any write tool
 *     'partial'    → agent called a write tool but also asked a question
 *   Falls back to null if SDK does not expose toolsUsed.
 *
 * All bug fixes from v2 (BUG 1–4) are preserved unchanged.
 * All GAP 1 (blackboard) and GAP 3 (observability) work is preserved.
 */
class AgentDispatcherService
{
    public function __construct(
        private readonly ObservabilityService $observability,
    ) {}

    /**
     * Dispatch multiple intents sequentially, sharing an AgentContextBlackboard.
     *
     * @param  string[]    $intents
     * @param  User        $user
     * @param  string      $message
     * @param  string|null $conversationId
     * @param  array       $attachments
     * @param  bool        $hitlConfirmed
     * @return array<string, array{reply: string, conversation_id: ?string}>
     */
    public function dispatchAll(
        array   $intents,
        User    $user,
        string  $message,
        ?string $conversationId,
        string  $turnId,
        array   $attachments    = [],
        bool    $hitlConfirmed  = false,
    ): array {
        $multiIntent = count($intents) > 1;
        $results     = [];
        $blackboard  = new AgentContextBlackboard();

        // ── FIX: pre-generate base ID for new multi-intent sessions ──────────
        // When conversationId is null AND we have multiple intents, generate the
        // base UUID upfront. This ensures the first agent is scoped as
        // "{base}:{intent}" from the very first turn — not at the bare base ID.
        // Without this, Turn 1 client lands at "base", but Turn 2 client tries
        // to continue "base:client" — a different, empty conversation.
        if ($multiIntent && $conversationId === null) {
            $conversationId = Str::uuid()->toString();
        }

        $baseConversationId = $conversationId;

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
                turnId:         $turnId,
            );

            $results[$intent] = $result;
            $blackboard->record($intent, $result['reply']);

            if ($index === 0 && $baseConversationId === null && !($result['_error'] ?? false)) {
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
     * @param  string|null                 $conversationId
     * @param  bool                        $multiIntent
     * @param  array                       $attachments
     * @param  AgentContextBlackboard|null $blackboard
     * @param  bool                        $hitlConfirmed
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
        ?string $turnId = null
    ): array {
        $start = microtime(true);
        $model = AgentRegistry::AGENT_MODELS[$intent] ?? 'gpt-4o';

        try {
            $agent = $this->resolveAgent($intent, $user);

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

            // ── Outcome signal (IBM AgentOps evaluation layer) ─────────────
            $outcomeSignal = $this->resolveOutcomeSignal($intent, $response);

            // ── Token usage from DB (see v2 comment for why DB not $response) ─
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

            $scopedConversationId = $response->conversationId;
            $resolvedBaseId = explode(':', $scopedConversationId)[0];


            $this->observability->recordAgentCall(
                intent:         $intent,
                userId:         (string) $user->id,
                conversationId: $resolvedBaseId,
                model:          $model,
                latencyMs:      $latencyMs,
                inputTokens:    $inputTokens,
                outputTokens:   $outputTokens,
                success:        true,
                outcomeSignal:  $outcomeSignal,
            );

            $this->writeMetaToMessage(
                conversationId: $response->conversationId,
                intent:         $intent,
                multiIntent:    $multiIntent,
                turnId:         $turnId,
            );

            return [
                'reply'           => (string) $response,
                'conversation_id' => $response->conversationId,
            ];

        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            $this->observability->recordAgentCall(
                intent:         $intent,
                userId:         (string) $user->id,
                conversationId: $conversationId,
                model:          $model,
                latencyMs:      $latencyMs,
                success:        false,
                errorMessage:   $e->getMessage(),
                outcomeSignal:  'error',
            );

            Log::error("[AgentDispatcherService] {$intent} agent failed", [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return [
                'reply'           => $this->errorResponse($intent),
                'conversation_id' => $conversationId,
                '_error'          => true,   // ← add this flag
            ];
        }
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Instantiate the specialist agent for the given intent.
     * Reads from AgentRegistry — no local AGENT_MAP needed.
     *
     * @throws \InvalidArgumentException If the intent has no registered agent.
     */
    private function resolveAgent(string $intent, User $user): Agent
    {
        $agents = AgentRegistry::AGENTS;

        if (!isset($agents[$intent])) {
            throw new \InvalidArgumentException(
                "No agent registered for intent: {$intent}"
            );
        }

        $class = $agents[$intent];

        return new $class($user);
    }

    /**
     * Configure the agent's conversation context.
     * BUG 1 FIX: scopes conversation ID per-intent for multi-intent turns.
     */
    private function configureConversation(
        Agent   $agent,
        User    $user,
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
    ): Agent {
        // No existing conversation — start fresh
        if ($conversationId === null) {
            return $agent->forUser($user);
        }

        // Multi-intent: always scope per-intent so agents don't share history
        if ($multiIntent) {
            return $agent->continue("{$conversationId}:{$intent}", as: $user);
        }

        // Single-intent: check if a scoped conversation exists from a prior
        // multi-intent turn. This handles the DB fallback case where the user
        // sends a follow-up like "make it ₹5000 instead" after a multi-intent turn.
        $scopedId = "{$conversationId}:{$intent}";
        $scopedExists = DB::table('agent_conversation_messages')
            ->where('conversation_id', $scopedId)
            ->exists();

        return $agent->continue(
            $scopedExists ? $scopedId : $conversationId,
            as: $user
        );
    }

    /**
     * Resolve the IBM AgentOps outcome signal for a completed agent call.
     *
     * Uses BaseAgent::writeTools() to determine whether the agent performed
     * a write operation, then checks the response text for trailing questions.
     *
     * Falls back to null if the agent class doesn't extend BaseAgent or if
     * the SDK response doesn't expose toolsUsed (safe — null is handled by
     * ObservabilityService as "signal not available").
     *
     * @return string|null  'completed' | 'clarifying' | 'partial' | null
     */
    private function resolveOutcomeSignal(string $intent, mixed $response): ?string
    {
        $agentClass = AgentRegistry::AGENTS[$intent] ?? null;

        // Only BaseAgent subclasses declare writeTools()
        if ($agentClass === null || !is_subclass_of($agentClass, BaseAgent::class)) {
            return 'completed';
        }

        $writeTools = $agentClass::writeTools();

        // If this agent has no write tools, it is read-only — outcome = 'completed'
        // (reading successfully is a completion for a read-only agent)
        if (empty($writeTools)) {
            return 'completed';
        }

        // Check whether the SDK response exposes toolsUsed
        $toolsUsed = $response->toolsUsed ?? null;

        if ($toolsUsed === null) {
            // SDK doesn't expose it yet — return null rather than guess
            return null;
        }

        $calledWriteTool = !empty(array_intersect($toolsUsed, $writeTools));
        $replyText       = (string) $response;
        $endsWithQuestion = str_ends_with(rtrim($replyText), '?');

        return match (true) {
            $calledWriteTool && !$endsWithQuestion => 'completed',
            $calledWriteTool && $endsWithQuestion  => 'partial',
            default                                 => 'clarifying',
        };
    }

    /**
     * Build the final prompt string for the specialist.
     * Injects HITL pre-authorisation, blackboard context, and intent scoping.
     */
    private function buildMessage(
        string                  $intent,
        string                  $message,
        ?AgentContextBlackboard $blackboard,
        bool                    $multiIntent,
        bool                    $hitlConfirmed  = false,
    ): string {
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

        $preamble = ($blackboard !== null && !$blackboard->isEmpty())
            ? $blackboard->buildContextPreamble($intent)
            : '';

        if (!$multiIntent) {
            return $hitlBlock . $preamble . $message;
        }

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
     * BUG 3 FIX: PHP-side JSON mutation — no DB::raw interpolation.
     */
    private function writeMetaToMessage(
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
        ?string $turnId = null,   // ← add parameter
    ): void {
        if ($conversationId === null) return;

        DB::transaction(function () use ($conversationId, $intent, $multiIntent, $turnId): void {
            $messageRow = DB::table('agent_conversation_messages')
                ->where('conversation_id', $conversationId)
                ->where('role', 'assistant')
                ->orderByDesc('created_at')
                ->lockForUpdate()
                ->first();

            if ($messageRow === null) return;

            $meta                 = json_decode($messageRow->meta ?? '{}', true) ?: [];
            $meta['intent']       = $intent;
            $meta['multi_intent'] = $multiIntent;

            if ($turnId !== null) {
                $meta['turn_id'] = $turnId;  // ← store it
            }

            DB::table('agent_conversation_messages')
                ->where('id', $messageRow->id)
                ->update(['meta' => json_encode($meta)]);
        });
    }

    /**
     * Build a domain-specific error message for the user.
     * Never exposes internal details or stack traces.
     * Reads labels from AgentRegistry keys — no hardcoded list.
     */
    private function errorResponse(string $intent): string
    {
        $label = ucfirst($intent);

        return "I encountered an issue with {$label} operations. Please try again in a moment. "
            . "If the problem persists, please contact support.";
    }
}
