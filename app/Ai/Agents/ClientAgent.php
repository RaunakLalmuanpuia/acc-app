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

#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(15)]
#[MaxTokens(2000)]
#[Temperature(0.1)]
class ClientAgent extends BaseAgent
{
    public static function getCapabilities(): array
    {
        return [
            AgentCapability::READS,
            AgentCapability::WRITES,
            AgentCapability::DESTRUCTIVE,
            AgentCapability::REFERENCE_ONLY,
        ];
    }

    public static function writeTools(): array
    {
        return ['create_client', 'update_client', 'delete_client'];
    }

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

        2. GATHER GAPS — Minimum viable to create:
           • Full name  (required — already provided if user named the client)
           • Phone      (required)
           • Email      (required)

           Collect only what is MISSING. Ask for ONLY the missing required
           fields — never optional ones upfront.

           PARSING RULE for multi-value messages like "7640876052, xyz@mail.com, 200":
            - A token containing @ → email.
            - A 10-digit number → phone.
            - Any remaining number in a message that asks for phone + email → IGNORE IT.
              It belongs to another agent (InventoryAgent needs it as the rate).
              Do NOT pass it as credit_limit, payment_terms, or any other client field.

            Only use numbers as client fields when the user explicitly labels them:
              "credit limit 5000" → credit_limit: 5000
              "payment terms 30"  → payment_terms: 30
            An unlabeled number alongside phone + email is always the inventory rate — ignore it.

           CRITICAL — when gathering info as part of an invoice request:
           End your reply with EXACTLY this line:
           "⏳ Once I have these details, I'll create the client and your invoice will proceed."

           Do NOT ask for optional fields (GSTIN, address, etc.).

        3. CREATE — Call create_client. After creating, present the record in a
           table and add ONE line:
           "Phone, GSTIN, and address can be updated anytime."

           INVOICE WORKFLOW: If the original user message mentioned an invoice,
           end your reply with EXACTLY:
           "✅ Client created. Proceeding to create your invoice now."
           Then output NOTHING else — no questions, no optional field prompts.

        ── ALREADY CREATED / HANDOFF ─────────────────────────────────────────

        If the conversation history shows a client was ALREADY created in a
        prior message (look for "created successfully" or "✅ Client"), AND the
        current message is "yes", "proceed", "ok", "go ahead", or similar with
        no new client data — reply with ONLY the single word:
        HANDOFF
        Do not add any other text.

        ── UPDATING A CLIENT ─────────────────────────────────────────────────

        1. SEARCH FIRST — Call get_clients to locate the record.
           If multiple matches, list them and ask which one.
        2. SHOW CURRENT VALUES — Present what will change.
        3. CONFIRM CHANGE — Ask: "Shall I update [field] from [old] to [new]?"
        4. UPDATE — Call update_client only after explicit yes.

        ── DELETING A CLIENT ─────────────────────────────────────────────────

        The HITL checkpoint (handled upstream) will have intercepted this.
        When the ✅ HITL PRE-AUTHORIZED block is present, execute delete_client
        immediately after a get_clients lookup to confirm the correct record ID.

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
