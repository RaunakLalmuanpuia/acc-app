<?php

namespace App\Ai;

use App\Ai\Agents\BusinessProfileAgent;
use App\Ai\Agents\ClientAgent;
use App\Ai\Agents\InventoryAgent;
use App\Ai\Agents\InvoiceAgent;
use App\Ai\Agents\NarrationAgent;
use App\Ai\Agents\BankTransactionAgent;

/**
 * AgentRegistry  (v1 — single source of truth)
 *
 * The one place that knows about every specialist agent in the system.
 * All cross-cutting services derive their behaviour from this registry
 * instead of maintaining separate hardcoded lists.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW TO ADD A NEW AGENT
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. Create your agent class in App\Ai\Agents\ extending BaseAgent.
 *  2. Implement getCapabilities() declaring READS / WRITES / DESTRUCTIVE /
 *     REFERENCE_ONLY as appropriate.
 *  3. Add one entry to AGENTS below — intent key + FQCN value.
 *  4. Done. The router, HITL, dispatcher, and observability all update
 *     automatically.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT CONSUMES THIS REGISTRY
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  RouterAgent          — builds routing rules from capability metadata
 *  HitlService          — derives GUARDED_INTENTS from DESTRUCTIVE capability
 *  AgentDispatcherService — maps intent → agent class, reads AGENT_MODELS
 *  IntentRouterService  — validates resolved intents against VALID_DOMAIN_INTENTS
 *  ObservabilityService — determines whether to track outcome signals (WRITES)
 */
class AgentRegistry
{
    /**
     * Maps domain intent string → specialist agent FQCN.
     *
     * ⚠️  Keep intent keys in sync with RouterAgent::INTENTS.
     *     Every key here must appear there, and vice versa (except 'unknown').
     *
     * @var array<string, class-string<\App\Ai\Contracts\BaseAgent>>
     */
    public const AGENTS = [
        'invoice'   => InvoiceAgent::class,
        'client'    => ClientAgent::class,
        'inventory' => InventoryAgent::class,
        'narration' => NarrationAgent::class,
        'business'  => BusinessProfileAgent::class,
        'bank_transaction' => BankTransactionAgent::class,
    ];

    /**
     * Maps intent → the OpenAI model name used by that specialist.
     * Used by ObservabilityService for per-agent cost estimation.
     * Keep in sync with each agent's #[Model(...)] attribute.
     *
     * @var array<string, string>
     */
    public const AGENT_MODELS = [
        'invoice'   => 'gpt-4o',
        'client'    => 'gpt-4o',
        'inventory' => 'gpt-4o',
        'narration' => 'gpt-4o',
        'business'  => 'gpt-4o',
        'bank_transaction' => 'gpt-4o',
    ];

    // ── Derived helpers (consumed by services) ─────────────────────────────────

    /**
     * Return all valid domain intent strings (excludes 'unknown').
     * Used by IntentRouterService::VALID_DOMAIN_INTENTS.
     *
     * @return string[]
     */
    public static function validIntents(): array
    {
        return array_keys(self::AGENTS);
    }

    /**
     * Return intents whose agent declares the DESTRUCTIVE capability.
     * Used by HitlService to derive GUARDED_INTENTS dynamically.
     *
     * @return string[]
     */
    public static function destructiveIntents(): array
    {
        return self::intentsByCapability(AgentCapability::DESTRUCTIVE);
    }

    /**
     * Return intents whose agent declares the WRITES capability.
     * Used by ObservabilityService to decide whether to track outcome signals.
     *
     * @return string[]
     */
    public static function writableIntents(): array
    {
        return self::intentsByCapability(AgentCapability::WRITES);
    }

    /**
     * Return intents whose agent declares the REFERENCE_ONLY capability.
     * Used by RouterAgent to suppress spurious standalone dispatch when a domain
     * is merely referenced (e.g. a client name inside an invoice request).
     *
     * @return string[]
     */
    public static function referenceOnlyIntents(): array
    {
        return self::intentsByCapability(AgentCapability::REFERENCE_ONLY);
    }

    /**
     * Return the capabilities declared by the agent for a given intent.
     *
     * @return AgentCapability[]
     */
    public static function capabilitiesFor(string $intent): array
    {
        $class = self::AGENTS[$intent] ?? null;

        if ($class === null || !method_exists($class, 'getCapabilities')) {
            return [];
        }

        return $class::getCapabilities();
    }

    /**
     * Check whether the agent for a given intent declares a specific capability.
     */
    public static function hasCapability(string $intent, AgentCapability $capability): bool
    {
        return in_array($capability, self::capabilitiesFor($intent), true);
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Filter all registered intents to those whose agent declares the given capability.
     *
     * @return string[]
     */
    private static function intentsByCapability(AgentCapability $capability): array
    {
        return array_values(array_filter(
            array_keys(self::AGENTS),
            fn (string $intent): bool => self::hasCapability($intent, $capability),
        ));
    }
}
