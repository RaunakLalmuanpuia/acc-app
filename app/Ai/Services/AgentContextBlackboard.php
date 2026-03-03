<?php

namespace App\Ai\Services;

/**
 * AgentContextBlackboard
 *
 * Implements IBM MAS "communication through the shared environment" pattern.
 *
 * Problem it solves:
 *   In a multi-intent turn, agents execute sequentially but have no visibility
 *   into each other's work. If ClientAgent creates "Acme Corp" (client_id: 42),
 *   InvoiceAgent still calls get_clients("Acme Corp") redundantly — wasting a
 *   tool round-trip and risking a "client not found" race.
 *
 * Solution:
 *   After each agent completes, its reply is written to the blackboard.
 *   Before the next agent is dispatched, the blackboard injects a context
 *   preamble into its message. The specialist reads this as established fact
 *   and skips redundant tool calls.
 *
 * IBM alignment:
 *   - "Agents model each other's goals and memory" (IBM MAS definition)
 *   - "Communication between agents can be indirect through altering the
 *     shared environment" (IBM decentralized communication pattern)
 *
 * Lifecycle: created fresh per chat turn inside dispatchAll() — not a singleton.
 * This keeps turns isolated from each other.
 */
class AgentContextBlackboard
{
    /**
     * @var array<string, array{reply: string, recorded_at: string}>
     */
    private array $state = [];

    /**
     * Record a completed agent's reply onto the blackboard.
     *
     * @param  string $intent  The domain intent (e.g. 'client', 'invoice')
     * @param  string $reply   The agent's natural-language response
     */
    public function record(string $intent, string $reply): void
    {
        $this->state[$intent] = [
            'reply'       => $reply,
            'recorded_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Whether the blackboard has any recorded context.
     */
    public function isEmpty(): bool
    {
        return empty($this->state);
    }

    /**
     * Whether a specific intent has been recorded.
     */
    public function has(string $intent): bool
    {
        return isset($this->state[$intent]);
    }

    /**
     * Retrieve a specific agent's recorded reply.
     */
    public function getReply(string $intent): ?string
    {
        return $this->state[$intent]['reply'] ?? null;
    }

    /**
     * Build a context preamble for a given specialist agent.
     *
     * Includes all prior agents' replies except the current one.
     * Injected at the top of the specialist's prompt so it treats prior
     * work as established fact — no redundant lookups.
     *
     * Returns an empty string if no prior context exists (single-intent turns
     * or the first agent in a multi-intent sequence).
     *
     * @param  string $forIntent  The intent about to be dispatched
     * @return string
     */
    public function buildContextPreamble(string $forIntent): string
    {
        $priorIntents = array_filter(
            array_keys($this->state),
            fn (string $i): bool => $i !== $forIntent
        );

        if (empty($priorIntents)) {
            return '';
        }

        $lines = [
            '╔══════════════════════════════════════════════════════════════╗',
            '║  PRIOR AGENT CONTEXT — treat as established fact             ║',
            '║  Do NOT re-fetch, re-create, or contradict this information. ║',
            '╚══════════════════════════════════════════════════════════════╝',
            '',
        ];

        foreach ($priorIntents as $intent) {
            $lines[] = "── [{$intent} agent completed] ──────────────────────────────────";
            $lines[] = $this->state[$intent]['reply'];
            $lines[] = "── [end {$intent} context] ──────────────────────────────────────";
            $lines[] = '';
        }

        $lines[] = '════════════════════════════════════════════════════════════════';
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Return the full blackboard state for observability / debugging.
     */
    public function all(): array
    {
        return $this->state;
    }
}
