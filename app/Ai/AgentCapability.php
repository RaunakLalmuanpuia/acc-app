<?php

namespace App\Ai;

/**
 * AgentCapability  (v1 — IBM MAS governance alignment)
 *
 * A typed enum that each specialist agent declares on itself via
 * getCapabilities(): array<AgentCapability>.
 *
 * This is the single source of truth for what an agent is allowed to do.
 * Every cross-cutting concern — routing, HITL, observability outcome signals —
 * derives its behaviour from these capabilities rather than from hardcoded
 * per-agent lists scattered across services.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY THIS EXISTS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Before this enum, three separate places maintained overlapping lists:
 *
 *   RouterAgent::instructions()     — hardcoded Rules 6 & 7 per domain
 *   HitlService::GUARDED_INTENTS    — hardcoded ['invoice','client',...]
 *   AgentDispatcherService          — no outcome signal at all
 *
 * Adding a new agent (e.g. PayrollAgent) required editing all three files.
 * Now it requires adding one entry in AgentRegistry — nothing else changes.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CAPABILITY MEANINGS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  READS        → agent calls read-only tools (get_*, list_*, search_*).
 *                 All agents should declare this.
 *
 *  WRITES       → agent can create or update records (create_*, update_*, etc.).
 *                 Triggers outcome signal tracking in ObservabilityService.
 *
 *  DESTRUCTIVE  → agent can permanently delete or void records.
 *                 Triggers HITL checkpoint in HitlService.
 *                 All DESTRUCTIVE agents must also declare WRITES.
 *
 *  REFERENCE_ONLY → agent is referenced by other agents (e.g. InvoiceAgent
 *                   looks up clients) but does NOT need to be dispatched as a
 *                   standalone intent when only a name is mentioned.
 *                   Used by RouterAgent to suppress spurious multi-intent routing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * USAGE IN AGENTS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  class InvoiceAgent extends BaseAgent
 *  {
 *      public static function getCapabilities(): array
 *      {
 *          return [
 *              AgentCapability::READS,
 *              AgentCapability::WRITES,
 *              AgentCapability::DESTRUCTIVE,
 *          ];
 *      }
 *  }
 *
 *  class ClientAgent extends BaseAgent
 *  {
 *      public static function getCapabilities(): array
 *      {
 *          return [
 *              AgentCapability::READS,
 *              AgentCapability::WRITES,
 *              AgentCapability::DESTRUCTIVE,
 *              AgentCapability::REFERENCE_ONLY,  // ← suppress spurious routing
 *          ];
 *      }
 *  }
 */
enum AgentCapability: string
{
    case READS        = 'reads';
    case WRITES       = 'writes';
    case DESTRUCTIVE  = 'destructive';

    /**
     * REFERENCE_ONLY: this agent's domain can be referenced by other agents
     * (e.g. an invoice references a client name) without triggering a standalone
     * dispatch. The RouterAgent uses this to suppress the spurious multi-intent
     * routing that caused ClientAgent to fire on "create invoice for Infosys".
     */
    case REFERENCE_ONLY = 'reference_only';
}
