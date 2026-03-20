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

    private array $meta = [];

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

    // Add these two methods after getReply():
    public function setMeta(string $key, mixed $value): void
    {
        $this->meta[$key] = $value;
    }

    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
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
            '║  CRITICAL: This context was ALREADY shown to the user.       ║',  // ← ADD
            '║  Do NOT repeat, summarise, or mention it in your reply.      ║',
            '║  If a prior agent asked for missing info, that resource does ║',
            '║  NOT exist yet. Tell the user to complete that step first.   ║',
            '║  Use it silently for lookups and decisions only.             ║',  // ← ADD
            '╚══════════════════════════════════════════════════════════════╝',
            '',
        ];


        foreach ($priorIntents as $intent) {
            $lines[] = "── [{$intent} agent completed] ──────────────────────────────────";
            $lines[] = $this->state[$intent]['reply'];
            $lines[] = "── [end {$intent} context] ──────────────────────────────────────";
            $lines[] = '';
        }

        // Add after the foreach loop, before the closing lines:
        if ($forIntent === 'invoice') {
            $clientId = $this->getMeta('client_id');
            $itemId   = $this->getMeta('inventory_item_id');

            if ($clientId || $itemId) {
                $lines[] = '── [resolved IDs — use directly, skip lookup tools] ────────────';
                if ($clientId) {
                    $lines[] = "⚙ client_id = {$clientId} → pass directly to create_invoice. Do NOT call lookup_client.";
                }
                if ($itemId) {
                    $lines[] = "⚙ inventory_item_id = {$itemId} → pass directly to add_line_item. Do NOT call lookup_inventory_item.";
                }
                $lines[] = '── [end resolved IDs] ───────────────────────────────────────────';
                $lines[] = '';
            }
        }

        if ($forIntent === 'bank_transaction') {
            $headId    = $this->getMeta('narration_head_id');
            $subHeadId = $this->getMeta('narration_sub_head_id');

            if ($headId || $subHeadId) {
                $lines[] = '── [resolved IDs — use directly, skip lookup tools] ────────────';
                if ($headId) {
                    $lines[] = "⚙ narration_head_id = {$headId} → pass directly to narrate_transaction.";
                }
                if ($subHeadId) {
                    $lines[] = "⚙ narration_sub_head_id = {$subHeadId} → pass directly to narrate_transaction.";
                }
                $lines[] = '── [end resolved IDs] ───────────────────────────────────────────';
                $lines[] = '';
            }
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
