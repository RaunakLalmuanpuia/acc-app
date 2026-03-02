<?php

namespace App\Ai\Services;

use App\Ai\Agents\BusinessProfileAgent;
use App\Ai\Agents\ClientAgent;
use App\Ai\Agents\InventoryAgent;
use App\Ai\Agents\InvoiceAgent;
use App\Ai\Agents\NarrationAgent;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Agent;
use Illuminate\Support\Facades\DB;

/**
 * AgentDispatcherService
 *
 * Resolves an intent string to its specialist agent class, configures the
 * agent with the correct conversation context, and executes the prompt.
 *
 * Responsibilities:
 *  - Agent class registry (intent → FQCN)
 *  - Conversation ID scoping ({base_id}:{intent}) for multi-intent sessions
 *  - Sequential dispatch with per-agent error isolation
 *  - Structured logging for observability
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
     * Dispatch multiple intents sequentially and collect all responses.
     *
     * Sequential (not parallel) dispatch is intentional: multi-intent turns
     * often have data dependencies (e.g. ClientAgent creates a client, then
     * InvoiceAgent uses that client ID). Parallel dispatch would break this.
     *
     * @param  string[]    $intents
     * @param  User        $user
     * @param  string      $message
     * @param  string|null $conversationId
     * @param  array       $attachments
     * @return array<string, string>
     */
    public function dispatchAll(
        array   $intents,
        User    $user,
        string  $message,
        ?string $conversationId,
        array   $attachments = [],
    ): array {
        $multiIntent = count($intents) > 1;
        $results     = [];
        $baseConversationId = $conversationId;

        foreach ($intents as $index => $intent) {

            $result = $this->dispatch(
                intent:         $intent,
                user:           $user,
                message:        $message,
                conversationId: $baseConversationId,
                multiIntent:    $multiIntent,
                attachments:    $attachments,
            );

            $results[$intent] = $result;

            // If this was a new conversation, capture the first created ID
            if ($index === 0 && $baseConversationId === null) {
                $baseConversationId = $result['conversation_id'];
            }
        }

        return $results;

    }

    /**
     * Dispatch a single intent to its specialist agent.
     *
     * Conversation scoping:
     *  - Single-intent: uses the raw conversationId as-is.
     *  - Multi-intent : scopes the ID as "{conversationId}:{intent}" so each
     *    specialist maintains independent memory within the same session.
     *    The frontend always sees only the base ID — scoping is internal.
     *
     * @param  string      $intent
     * @param  User        $user
     * @param  string      $message
     * @param  string|null $conversationId  Base conversation ID from the session.
     * @param  bool        $multiIntent     Whether multiple intents are being dispatched.
     * @param  array       $attachments     Optional file attachments (Image|Document).
     * @return array{reply:string, conversation_id:?string}
     */
    public function dispatch(
        string  $intent,
        User    $user,
        string  $message,
        ?string $conversationId,
        bool    $multiIntent = false,
        array   $attachments = [],
    ): array {
        try {
            $agent = $this->resolveAgent($intent, $user);
            $agent = $this->configureConversation($agent, $user, $conversationId, $intent, $multiIntent);

            Log::info('[AgentDispatcherService] Dispatching', [
                'intent'          => $intent,
                'user_id'         => $user->id,
                'conversation_id' => $conversationId,
                'multi_intent'    => $multiIntent,
            ]);

            $intentScopedMessage = $this->scopeMessageForIntent($intent, $message);

            $response = $agent->prompt(
                prompt: $intentScopedMessage,
                attachments: $attachments,
            );

            // 2️⃣ Atomic meta update
            DB::transaction(function () use ($response, $intent, $multiIntent) {

                $messageRow = DB::table('agent_conversation_messages')
                    ->where('conversation_id', $response->conversationId)
                    ->where('role', 'assistant')
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->first();

                if ($messageRow) {
                    DB::table('agent_conversation_messages')
                        ->where('id', $messageRow->id)
                        ->update([
                            'meta' => DB::raw("
                            JSON_SET(
                                COALESCE(meta, JSON_OBJECT()),
                                '$.intent', '{$intent}',
                                '$.multi_intent', " . ($multiIntent ? 'true' : 'false') . "
                            )
                        ")
                        ]);
                }
            });



            return [
                'reply' => (string) $response,
                'conversation_id' => $response->conversationId,
            ];

        } catch (\Throwable $e) {
            Log::error("[AgentDispatcherService] {$intent} agent failed", [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return $this->errorResponse($intent);
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
     * Configure the agent with the appropriate conversation context.
     *
     * Scoping strategy:
     *  - Single intent : conversationId used directly — clean and simple.
     *  - Multi-intent  : each specialist gets a scoped ID ({base}:{intent})
     *    so individual histories do not bleed into each other.
     *    The frontend never sees scoped IDs — only the base ID is returned.
     */
    private function configureConversation(
        Agent   $agent,
        User    $user,
        ?string $conversationId,
        string  $intent,
        bool    $multiIntent,
    ): Agent {
        if ($conversationId === null) {
            return $agent->forUser($user); // SDK assigns a fresh conversation ID
        }

        return $agent->continue($conversationId, as: $user);
    }

    /**
     * Return a domain-specific error message so the user understands
     * which part of their request failed, without exposing internals.
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
    /**
     * Scope a multi-domain user message to a single specialist intent.
     *
     * Ensures that each specialist agent only processes the portion of the
     * message relevant to its own domain and does not respond defensively
     * about other domains.
     */
    private function scopeMessageForIntent(string $intent, string $message): string
    {
        return <<<PROMPT
        The user message may contain requests for multiple domains.

        You are ONLY responsible for the "{$intent}" domain.

        Ignore all parts of the message unrelated to "{$intent}".
        Do not mention other domains in your response.

        User message:
        {$message}
        PROMPT;
    }
}
