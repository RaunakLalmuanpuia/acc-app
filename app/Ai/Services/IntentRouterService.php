<?php

namespace App\Ai\Services;

use App\Ai\AgentRegistry;
use App\Ai\Agents\RouterAgent;
use Illuminate\Support\Facades\Log;

/**
 * IntentRouterService  (v3 — AgentRegistry-driven VALID_DOMAIN_INTENTS)
 *
 * Encapsulates all logic for calling the RouterAgent, parsing its JSON output,
 * validating intents, and gracefully recovering from failure.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGE FROM v2
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v2 hardcoded:
 *   public const VALID_DOMAIN_INTENTS = ['invoice','client','inventory','narration','business'];
 *
 * This was a second place (alongside AgentDispatcherService::AGENT_MAP) that
 * needed manual updating whenever a new agent was added.
 *
 * v3 derives the valid intents from AgentRegistry::validIntents() — a single
 * call that reads the keys of AgentRegistry::AGENTS. Adding a new agent to
 * the registry automatically makes its intent valid here.
 *
 * The constant is kept as a public getter method (validDomainIntents()) for
 * backwards compatibility with any tests that reference it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GAP 2 FIX (carried from v2)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * On RouterAgent failure → return ['unknown'] not all domain intents.
 * On JSON parse failure  → return []          not all domain intents.
 * Both let the orchestrator's DB fallback or unknownResponse() handle it
 * at zero additional AI cost.
 */
class IntentRouterService
{
    public function __construct(private readonly RouterAgent $router) {}

    /**
     * Return the valid domain intents derived from AgentRegistry.
     * Replaces the old VALID_DOMAIN_INTENTS constant.
     *
     * @return string[]
     */
    public function validDomainIntents(): array
    {
        return AgentRegistry::validIntents();
    }

    /**
     * Route a user message to one or more domain intents.
     *
     * Returns an array of valid domain intents only.
     * Returns an empty array if the message is unknown / greeting / off-topic,
     * or if the router fails — the orchestrator handles both cases the same way.
     *
     * @param  string   $message  The raw user message.
     * @return string[]           e.g. ['invoice'], ['client', 'invoice'], []
     */
    public function resolve(string $message): array
    {
        $raw     = $this->callRouter($message);
        $intents = $this->parseIntents($raw);

        return $this->filterValid($intents);
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Prompt the RouterAgent and return the raw response string.
     *
     * On failure: return ['unknown'] so the orchestrator's DB fallback
     * (getLastIntent) can attempt recovery — or fall through to the zero-cost
     * unknownResponse() static reply.
     */
    private function callRouter(string $message): string
    {
        try {
            $response = $this->router->prompt(prompt: $message);
            return trim((string) $response);
        } catch (\Throwable $e) {
            Log::error('[IntentRouterService] RouterAgent call failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return json_encode(['intents' => ['unknown']]);
        }
    }

    /**
     * Parse the raw router output into an array of intent strings.
     *
     * Handles edge cases:
     *  - Model wraps output in markdown code fences (``` ... ```)
     *  - Model returns invalid JSON
     *  - 'intents' key is missing or not an array
     *
     * On parse failure: return [] (not all intents — prevents 5-agent dispatch).
     */
    private function parseIntents(string $raw): array
    {
        $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $raw);
        $cleaned = preg_replace('/\s*```$/', '', $cleaned ?? $raw);
        $cleaned = trim($cleaned ?? $raw);

        try {
            $decoded = json_decode($cleaned, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::warning('[IntentRouterService] JSON parse failed', [
                'raw'   => $raw,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (!isset($decoded['intents']) || !is_array($decoded['intents'])) {
            Log::warning('[IntentRouterService] Missing or invalid intents key', [
                'decoded' => $decoded,
            ]);

            return [];
        }

        return $decoded['intents'];
    }

    /**
     * Filter parsed intents to only those that have a registered agent.
     * 'unknown' and any unrecognised strings are dropped.
     * Deduplicates the result.
     *
     * Uses AgentRegistry::validIntents() — automatically includes any new agent.
     */
    private function filterValid(array $intents): array
    {
        return array_values(
            array_unique(
                array_intersect($intents, AgentRegistry::validIntents())
            )
        );
    }
}
