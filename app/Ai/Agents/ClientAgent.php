<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\Client\CreateClient;
use App\Ai\Tools\Client\DeleteClient;
use App\Ai\Tools\Client\GetClients;
use App\Ai\Tools\Client\UpdateClient;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Enums\Lab;

/**
 * ClientAgent  (v3 — extends BaseAgent)
 *
 * Specialist for client/customer record management.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * THIS FILE IS ALSO A TEMPLATE FOR NEW AGENTS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * To create a new agent:
 *   1. Copy this file, rename class and file to YourDomainAgent.
 *   2. Update getCapabilities() for your domain.
 *   3. Update writeTools() to list your write tool names.
 *   4. Write your domainInstructions() — workflow + rules specific to your domain.
 *   5. Return your tools from tools().
 *   6. Add #[Model], #[MaxSteps], #[MaxTokens], #[Temperature] attributes.
 *   7. Add one line to AgentRegistry::AGENTS.
 *   8. Done — router, HITL, observability, and dispatcher all update automatically.
 *
 * DO NOT add IBM standard blocks (plan-first, loop-guard, HITL awareness) to
 * domainInstructions() — BaseAgent::instructions() injects them automatically
 * based on your declared capabilities.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(15)]
#[MaxTokens(2000)]
#[Temperature(0.1)]
class ClientAgent extends BaseAgent
{
    /**
     * Declare what this agent can do.
     *
     * READS        → can call get_clients
     * WRITES       → can create and update client records
     * DESTRUCTIVE  → can delete client records (triggers HITL checkpoint)
     * REFERENCE_ONLY → client names are referenced by InvoiceAgent;
     *                  mentioning a client name in an invoice request must NOT
     *                  trigger a standalone ClientAgent dispatch.
     */
    public static function getCapabilities(): array
    {
        return [
            AgentCapability::READS,
            AgentCapability::WRITES,
            AgentCapability::DESTRUCTIVE,
            AgentCapability::REFERENCE_ONLY,
        ];
    }

    /**
     * The tool names that constitute a write operation for this agent.
     * Used by AgentDispatcherService to resolve the IBM AgentOps outcome signal.
     *
     * @return string[]
     */
    public static function writeTools(): array
    {
        return ['create_client', 'update_client', 'delete_client'];
    }

    /**
     * Client-specific behaviour instructions.
     *
     * BaseAgent::instructions() wraps this with:
     *   - Header (agent identity + today's date)
     *   - PLAN FIRST / ReWOO block
     *   - LOOP GUARD block
     *   - DESTRUCTIVE OPERATIONS / HITL block (because DESTRUCTIVE is declared)
     *
     * Write ONLY your domain rules here — do not duplicate the standard blocks.
     */
    protected function domainInstructions(): string
    {
        return <<<PROMPT
        ═════════════════════════════════════════════════════════════════════════
        CLIENT MANAGEMENT — WORKFLOW
        ═════════════════════════════════════════════════════════════════════════

        You handle: creating, viewing, updating, and deleting client records.

        ── CREATING A CLIENT ─────────────────────────────────────────────────

        1. SEARCH FIRST — Call get_clients with the name the user provided.
           • Found    → show the existing record. Ask: "A client with this name
             already exists — do you want to update them instead?"
           • Not found → proceed to gather missing fields.

        2. GATHER GAPS (in one message) — Minimum required fields:
           • Full name (required)
           • Email address (required)
           • Phone number (optional but recommended)
           • Billing address (optional)
           • GSTIN (optional — ask only if user mentions GST)

        3. CREATE — Call create_client. Present the new record in a table.

        ── UPDATING A CLIENT ─────────────────────────────────────────────────

        1. SEARCH FIRST — Call get_clients to locate the record.
           If multiple matches, list them and ask which one.

        2. SHOW CURRENT VALUES — Present what will change vs current values.

        3. CONFIRM CHANGE — Ask: "Shall I update [field] from [old] to [new]?"

        4. UPDATE — Call update_client only after explicit yes.

        ── DELETING A CLIENT ─────────────────────────────────────────────────

        The HITL checkpoint (handled upstream) will have intercepted this
        before this agent is called. When the ✅ HITL PRE-AUTHORIZED block
        is present, execute delete_client immediately after a get_clients
        lookup to confirm the correct record ID.

        ── GENERAL ───────────────────────────────────────────────────────────

        • Never expose raw database IDs to the user.
        • Present client details in a clean table format.
        • If a GSTIN is provided, display it formatted (e.g. 29ABCDE1234F1Z5).
        • Never store or display full payment card numbers.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new GetClients($this->user),
            new CreateClient($this->user),
            new UpdateClient($this->user),
            new DeleteClient($this->user),
        ];
    }
}
