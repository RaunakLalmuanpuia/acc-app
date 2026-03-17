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
 * AgentDispatcherService  (v4 — invoice number injection)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v3
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 1 — Invoice number injection
 *   dispatchAll() and dispatch() accept an optional $activeInvoiceNumber.
 *   When provided for the invoice intent, buildMessage() injects it as a
 *   system hint block at the top of InvoiceAgent's prompt. This ensures the
 *   agent can always recover its working invoice even when the scoped
 *   conversation history is fragmented across multi-intent turns.
 *
 * FIX 2 — Invoice number extraction + meta storage
 *   After each invoice agent call, the reply is scanned for INV-YYYYMMDD-XXXXX.
 *   If found, the number is stored in the message's meta column via
 *   writeMetaToMessage(). ChatOrchestrator reads this on subsequent turns via
 *   loadActiveInvoiceNumber() and injects it back here.
 *
 * FIX 3 — outcomeSignal stored in meta
 *   writeMetaToMessage() now persists the outcome signal alongside intent/turn_id.
 *   Used by ChatOrchestrator's content-based completion check.
 *
 * All v3 fixes (scoped conversation, blackboard, observability) are preserved.
 */
class AgentDispatcherService
{
    public function __construct(
        private readonly ObservabilityService $observability,
    ) {}

    /**
     * Dispatch multiple intents sequentially, sharing an AgentContextBlackboard.
     */
    public function dispatchAll(
        array   $intents,
        User    $user,
        string  $message,
        ?string $conversationId,
        string  $turnId,
        array   $attachments         = [],
        bool    $hitlConfirmed        = false,
        ?string $activeInvoiceNumber  = null,
    ): array {
        $multiIntent = count($intents) > 1;
        $results     = [];
        $blackboard  = new AgentContextBlackboard();

        if ($multiIntent && $conversationId === null) {
            $conversationId = Str::uuid()->toString();
        }

        $baseConversationId = $conversationId;

        foreach ($intents as $index => $intent) {
            // Small pause between sequential agent calls to avoid rate limiting.
            // Skip on the first call — no need to delay before we start.
            if ($index > 0) {
                usleep(300_000); // 300ms
            }

            $result = $this->dispatch(
                intent:              $intent,
                user:                $user,
                message:             $message,
                conversationId:      $baseConversationId,
                multiIntent:         $multiIntent,
                attachments:         $attachments,
                blackboard:          $blackboard,
                hitlConfirmed:       $hitlConfirmed,
                turnId:              $turnId,
                activeInvoiceNumber: $activeInvoiceNumber,
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
     */
    public function dispatch(
        string                  $intent,
        User                    $user,
        string                  $message,
        ?string                 $conversationId,
        bool                    $multiIntent        = false,
        array                   $attachments        = [],
        ?AgentContextBlackboard $blackboard         = null,
        bool                    $hitlConfirmed      = false,
        ?string                 $turnId             = null,
        ?string                 $activeInvoiceNumber = null,
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
                intent:              $intent,
                message:             $message,
                blackboard:          $blackboard,
                multiIntent:         $multiIntent,
                hitlConfirmed:       $hitlConfirmed,
                activeInvoiceNumber: $activeInvoiceNumber,
            );

            $response  = null;
            $attempts  = 0;
            $maxTries  = 5;
            $baseDelay = 2; // seconds

            while ($attempts < $maxTries) {
                try {
                    $response = $agent->prompt(
                        prompt:      $prompt,
                        attachments: $attachments,
                    );
                    break; // success — exit retry loop
                } catch (\Throwable $e) {
                    $attempts++;
                    $isRateLimit = str_contains($e->getMessage(), 'rate limit')
                        || str_contains($e->getMessage(), 'RateLimited')
                        || str_contains($e->getMessage(), 'rate_limit_exceeded')
                        || ($e instanceof \Laravel\Ai\Exceptions\RateLimitedException);

                    if ($isRateLimit && $attempts < $maxTries) {
                        $delay = $baseDelay * (2 ** ($attempts - 1)); // 2s, 4s
                        Log::warning("[AgentDispatcherService] Rate limited — retrying in {$delay}s", [
                            'intent'   => $intent,
                            'attempt'  => $attempts,
                            'max'      => $maxTries,
                        ]);
                        sleep($delay);
                        continue;
                    }

                    // Non-rate-limit error or exhausted retries — rethrow to outer catch
                    throw $e;
                }
            }

            $latencyMs     = (int) ((microtime(true) - $start) * 1000);
            $outcomeSignal = $this->resolveOutcomeSignal($intent, $response);

            // ── Token usage from DB ────────────────────────────────────────────
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

            // ── FIX 2: extract and store invoice number from reply ─────────────
            $replyText     = (string) $response;
            $invoiceNumber = null;

            if ($intent === 'invoice') {
                if (preg_match('/INV-\d{8}-\d+/', $replyText, $matches)) {
                    $invoiceNumber = $matches[0];
                }
            }

            $this->writeMetaToMessage(
                conversationId: $response->conversationId,
                intent:         $intent,
                multiIntent:    $multiIntent,
                turnId:         $turnId,
                outcomeSignal:  $outcomeSignal,
                invoiceNumber:  $invoiceNumber,
            );

            return [
                'reply'           => $replyText,
                'conversation_id' => $response->conversationId,
            ];

        } catch (\Throwable $e) {
            $latencyMs = (int) ((microtime(true) - $start) * 1000);

            $isRateLimit = str_contains($e->getMessage(), 'rate limit')
                || str_contains($e->getMessage(), 'RateLimited')
                || str_contains($e->getMessage(), 'rate_limit_exceeded');

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
                'reply'           => $isRateLimit
                    ? "I'm processing a lot right now — please send your message again in a few seconds."
                    : $this->errorResponse($intent),
                'conversation_id' => $conversationId,
                '_error'          => true,
            ];
        }
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function resolveAgent(string $intent, User $user): Agent
    {
        $agents = AgentRegistry::AGENTS;

        if (!isset($agents[$intent])) {
            throw new \InvalidArgumentException(
                "No agent registered for intent: {$intent}"
            );
        }

        return new $agents[$intent]($user);
    }

    /**
     * Configure the agent's conversation context.
     *
     * For single-intent follow-ups after a multi-intent setup turn, prefer the
     * scoped conversation {id}:{intent} if it exists — this ensures the agent
     * loads the history from the multi-intent setup phase rather than a bare
     * base conversation with no relevant history.
     */
    private function configureConversation(
        Agent   $agent,
        User    $user,
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
    ): Agent {
        if ($conversationId === null) {
            return $agent->forUser($user);
        }

        if ($multiIntent) {
            return $agent->continue("{$conversationId}:{$intent}", as: $user);
        }

        $scopedId     = "{$conversationId}:{$intent}";
        $scopedExists = DB::table('agent_conversation_messages')
            ->where('conversation_id', $scopedId)
            ->exists();

        if ($scopedExists) {
            Log::info('[AgentDispatcherService] Single-intent follow-up using scoped conversation', [
                'intent'    => $intent,
                'scoped_id' => $scopedId,
            ]);
            return $agent->continue($scopedId, as: $user);
        }

        return $agent->continue($conversationId, as: $user);
    }

    private function resolveOutcomeSignal(string $intent, mixed $response): ?string
    {
        $agentClass = AgentRegistry::AGENTS[$intent] ?? null;

        if ($agentClass === null || !is_subclass_of($agentClass, BaseAgent::class)) {
            return 'completed';
        }

        $writeTools = $agentClass::writeTools();

        if (empty($writeTools)) {
            return 'completed';
        }

        $toolsUsed = $response->toolsUsed ?? null;

        if ($toolsUsed === null) {
            return null;
        }

        $calledWriteTool  = !empty(array_intersect($toolsUsed, $writeTools));
        $endsWithQuestion = str_ends_with(rtrim((string) $response), '?');

        return match (true) {
            $calledWriteTool && !$endsWithQuestion => 'completed',
            $calledWriteTool && $endsWithQuestion  => 'partial',
            default                                 => 'clarifying',
        };
    }

    /**
     * Build the final prompt string for the specialist agent.
     *
     * Injection order (top to bottom in the prompt):
     *   1. HITL pre-authorization block (if confirmed)
     *   2. Active invoice hint (if invoice intent and number known)
     *   3. Blackboard context preamble (prior agent results)
     *   4. Multi-intent scope wrapper OR raw user message
     */
    private function buildMessage(
        string                  $intent,
        string                  $message,
        ?AgentContextBlackboard $blackboard,
        bool                    $multiIntent,
        bool                    $hitlConfirmed      = false,
        ?string                 $activeInvoiceNumber = null,
    ): string {
        // Block 1: HITL
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

        // Block 2: Active invoice hint
        $invoiceHint = '';
        if ($intent === 'invoice' && $activeInvoiceNumber !== null) {
            $invoiceHint = <<<HINT
            ╔══════════════════════════════════════════════════════════════════╗
            ║  ACTIVE INVOICE: {$activeInvoiceNumber}
            ╠══════════════════════════════════════════════════════════════════╣
            ║  This invoice was created earlier in this conversation.          ║
            ║  REQUIRED: Call get_active_drafts(invoice_number:               ║
            ║  "{$activeInvoiceNumber}") to retrieve the invoice_id before    ║
            ║  calling any write tools (add_line_item, generate_invoice_pdf,  ║
            ║  finalize_invoice). Do NOT invent the invoice_id.               ║
            ╚══════════════════════════════════════════════════════════════════╝

            HINT;
        }

        // Block 3: Blackboard preamble
        $preamble = ($blackboard !== null && !$blackboard->isEmpty())
            ? $blackboard->buildContextPreamble($intent)
            : '';

        if (!$multiIntent) {
            return $hitlBlock . $invoiceHint . $preamble . $message;
        }

        return <<<PROMPT
        {$hitlBlock}{$invoiceHint}{$preamble}The user message may contain requests for multiple domains.

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
     */
    private function writeMetaToMessage(
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
        ?string $turnId        = null,
        ?string $outcomeSignal = null,
        ?string $invoiceNumber = null,
    ): void {
        if ($conversationId === null) return;

        DB::transaction(function () use ($conversationId, $intent, $multiIntent, $turnId, $outcomeSignal, $invoiceNumber): void {
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

            if ($turnId !== null)        $meta['turn_id']        = $turnId;
            if ($outcomeSignal !== null)  $meta['outcome']        = $outcomeSignal;
            if ($invoiceNumber !== null)  $meta['invoice_number'] = $invoiceNumber;

            DB::table('agent_conversation_messages')
                ->where('id', $messageRow->id)
                ->update(['meta' => json_encode($meta)]);
        });
    }

    private function errorResponse(string $intent): string
    {
        $label = ucfirst($intent);
        return "I encountered an issue with {$label} operations. Please try again in a moment. "
            . "If the problem persists, please contact support.";
    }
}
