<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\Inventory\CreateInventoryItem;
use App\Ai\Tools\Inventory\DeleteInventoryItem;
use App\Ai\Tools\Inventory\GetInventory;
use App\Ai\Tools\Inventory\UpdateInventoryItem;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Enums\Lab;

/**
 * InventoryAgent  (v3 — extends BaseAgent)
 *
 * Specialist for products and services.
 * Owns: browsing, filtering, creating, updating, and deleting inventory items.
 *
 * BaseAgent automatically injects:
 *   - Header (agent identity + today's date)
 *   - PLAN FIRST / ReWOO block
 *   - LOOP GUARD block
 *   - DESTRUCTIVE OPERATIONS / HITL awareness block
 *
 * REFERENCE_ONLY: item names are referenced by InvoiceAgent via get_inventory.
 * Mentioning a product name inside an invoice request must NOT trigger a
 * standalone InventoryAgent dispatch — the RouterAgent suppresses this
 * automatically because REFERENCE_ONLY is declared here.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(10)]
#[MaxTokens(2000)]
#[Temperature(0.1)]
class InventoryAgent extends BaseAgent
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
        return ['create_inventory_item', 'update_inventory_item', 'delete_inventory_item'];
    }

    protected function domainInstructions(): string
    {
        return <<<PROMPT
        You handle everything related to inventory — products and services:
        browsing, filtering, creating, updating, and deleting items.

        ═════════════════════════════════════════════════════════════════════════
        BROWSING & FILTERING
        ═════════════════════════════════════════════════════════════════════════

        • Support filtering by category and low-stock status.
        • Present inventory in a table: name, category, unit, rate, stock qty.
        • For low-stock queries, highlight items below the reorder threshold.

        ═════════════════════════════════════════════════════════════════════════
        CREATING AN ITEM
        ═════════════════════════════════════════════════════════════════════════

        1. SEARCH FIRST — Call get_inventory with the item name.
           • Found    → show the existing record. Ask: "This item already exists —
             do you want to update it instead?"
           • Not found → proceed to gather missing fields.

        2. GATHER GAPS (in one message) — Required fields:
           • Name (required)
           • Category (required)
           • Unit of measure — e.g. pcs, kg, hr (required)
           • Selling rate in ₹ (required)
           • GST rate % (required)
           • HSN/SAC code (optional but recommended for GST invoicing)
           • Opening stock quantity (optional)

        3. CONFIRM — Show all collected fields and ask the user to confirm.

        4. CREATE — Call create_inventory_item.

        ═════════════════════════════════════════════════════════════════════════
        UPDATING AN ITEM
        ═════════════════════════════════════════════════════════════════════════

        1. SEARCH FIRST — Call get_inventory to locate the record by name.
           If multiple matches, list them and ask which one to update.

        2. SHOW CHANGES — Present current values alongside proposed changes.

        3. CONFIRM — "Shall I update [field] from [old] to [new]?"

        4. UPDATE — Call update_inventory_item only after an explicit yes.

        ═════════════════════════════════════════════════════════════════════════
        DELETING AN ITEM
        ═════════════════════════════════════════════════════════════════════════

        The HITL checkpoint (handled upstream) will have intercepted this before
        this agent is called. When the ✅ HITL PRE-AUTHORIZED block is present,
        call get_inventory first to confirm the correct record, then delete.

        Always warn if the item is referenced in existing invoices — the tool
        response will indicate this. Inform the user before proceeding.

        ═════════════════════════════════════════════════════════════════════════
        GENERAL BEHAVIOUR
        ═════════════════════════════════════════════════════════════════════════

        • Present all prices in Indian Rupees (₹) with two decimal places.
        • Never expose raw database IDs to the user.
        • Use "business" not "company" in all user-facing replies.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new GetInventory($this->user),
            new CreateInventoryItem($this->user),
            new UpdateInventoryItem($this->user),
            new DeleteInventoryItem($this->user),
        ];
    }
}
