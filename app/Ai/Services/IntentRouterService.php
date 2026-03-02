<?php

namespace App\Ai\Services;

use App\Ai\Agents\RouterAgent;
use Illuminate\Support\Facades\Log;

/**
 * IntentRouterService
 *
 * Encapsulates all logic for calling the RouterAgent, parsing its JSON output,
 * validating intents, and gracefully recovering from failure.
 *
 * Separation of concerns: the orchestrator delegates ALL routing concerns here.
 */
class IntentRouterService
{
    /**
     * Domain intents that map to specialist agents.
     * Any intent NOT in this list (including 'unknown') is filtered out
     * before agent dispatch.
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
     * Returns an empty array if the message is unknown / greeting / off-topic.
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
     * On failure, returns a safe fallback JSON string that routes to all domains.
     */
    private function callRouter(string $message): string
    {
        try {
            $response = $this->router->prompt(prompt: $message);
            return trim((string) $response);
        } catch (\Throwable $e) {
            Log::error('[IntentRouterService] RouterAgent call failed', [
                'message' => $message,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            // Safe fallback: route to all domain agents (slightly expensive but never wrong)
            return json_encode(['intents' => self::VALID_DOMAIN_INTENTS]);
        }
    }

    /**
     * Parse the raw router output into an array of intent strings.
     *
     * Handles edge cases:
     *  - Model wraps output in markdown code fences (``` ... ```)
     *  - Model returns invalid JSON
     *  - 'intents' key is missing or not an array
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

            return self::VALID_DOMAIN_INTENTS; // fallback: route to all
        }

        if (!isset($decoded['intents']) || !is_array($decoded['intents'])) {
            Log::warning('[IntentRouterService] Missing or invalid intents key', [
                'decoded' => $decoded,
            ]);

            return self::VALID_DOMAIN_INTENTS; // fallback: route to all
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
