<?php

namespace App\Ai\Services;

use App\Ai\Agents\RouterAgent;
use Illuminate\Support\Facades\Log;

/**
 * IntentRouterService  (v2 — Gap 2 fixed)
 *
 * Encapsulates all logic for calling the RouterAgent, parsing its JSON output,
 * validating intents, and gracefully recovering from failure.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * GAP 2 FIX — RouterAgent failure fallback
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * BEFORE (v1):
 *   On RouterAgent failure, return json_encode(['intents' => VALID_DOMAIN_INTENTS])
 *   → Dispatches ALL 5 specialist agents (5x gpt-4o calls for a routing failure).
 *   → Expensive and produces a multi-section reply that confuses the user.
 *
 * AFTER (v2):
 *   On RouterAgent failure, return json_encode(['intents' => ['unknown']])
 *   → 'unknown' is filtered out by filterValid(), resolve() returns []
 *   → ChatOrchestrator's DB fallback (getLastIntent) tries to recover from
 *     conversation history instead.
 *   → If DB fallback also finds nothing, the static unknownResponse() is
 *     returned — zero AI cost, no confusing multi-domain noise.
 *
 * This is the correct failure mode: a routing failure should be cheap and quiet,
 * not loud and expensive.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SAME PARSE FALLBACK LOGIC — also fixed
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * BEFORE: parseIntents() returned VALID_DOMAIN_INTENTS on JSON parse failure,
 *   again triggering all 5 agents unnecessarily.
 *
 * AFTER: parseIntents() returns [] on JSON parse failure, letting the DB
 *   fallback or unknownResponse() handle it.
 */
class IntentRouterService
{
    /**
     * Domain intents that map to specialist agents.
     * Any intent NOT in this list (including 'unknown') is filtered out.
     */
    public const VALID_DOMAIN_INTENTS = [
        'invoice',
        'client',
        'inventory',
        'narration',
        'business',
    ];

    public function __construct(private readonly RouterAgent $router) {}

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
     * GAP 2 FIX: On failure, return ['unknown'] instead of all domain intents.
     * This lets the orchestrator's DB fallback (getLastIntent) take over, or
     * fall through to the zero-cost unknownResponse() static reply.
     *
     * The old "route to all domains" fallback was expensive (5x gpt-4o calls)
     * and produced a confusing multi-domain response for what was a routing error.
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

            // Return 'unknown' — the orchestrator's DB fallback will attempt
            // to recover from conversation history. If that also fails, the
            // static unknownResponse() is returned at zero AI cost.
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
     * GAP 2 FIX: On parse failure, return [] instead of VALID_DOMAIN_INTENTS.
     * Prevents unnecessary 5-agent dispatch on a parse error.
     */
    private function parseIntents(string $raw): array
    {
        // Strip markdown code fences if the model accidentally wraps its output
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

            return []; // GAP 2 FIX: was VALID_DOMAIN_INTENTS — too expensive on failure
        }

        if (!isset($decoded['intents']) || !is_array($decoded['intents'])) {
            Log::warning('[IntentRouterService] Missing or invalid intents key', [
                'decoded' => $decoded,
            ]);

            return []; // GAP 2 FIX: was VALID_DOMAIN_INTENTS
        }

        return $decoded['intents'];
    }

    /**
     * Filter parsed intents to only those that have a registered agent.
     * 'unknown' and any unrecognised strings are dropped.
     * Deduplicates the result.
     */
    private function filterValid(array $intents): array
    {
        return array_values(
            array_unique(
                array_intersect($intents, self::VALID_DOMAIN_INTENTS)
            )
        );
    }
}
