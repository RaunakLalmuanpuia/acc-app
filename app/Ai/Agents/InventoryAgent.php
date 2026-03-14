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

        // In InventoryAgent::domainInstructions() — replace CREATING AN ITEM:

        ═════════════════════════════════════════════════════════════════════════
        CREATING AN ITEM
        ═════════════════════════════════════════════════════════════════════════

        1. SEARCH FIRST — Call get_inventory with the item name.
           • Found    → show the existing record and ask if user wants to update.
           • Not found → proceed to step 2.

        2. GATHER ONE FIELD ONLY — The only field you ever ask for is the rate.
           Everything else is inferred automatically. Never ask for brand,
           description, cost price, MRP, stock quantity, or low stock threshold
           — these are optional and can be updated later.

           INFER these defaults silently (show them, do not ask):
             "chairs", "tables", "desks", "sofa"   → Category: Furniture,   Unit: pcs, GST: 18%
             "laptop", "phone", "TV", "computer"   → Category: Electronics, Unit: pcs, GST: 18%
             "consulting", "design", "dev", "audit"→ Category: Services,    Unit: hr,  GST: 18%
             "paper", "pens", "files"              → Category: Stationery,  Unit: pcs, GST: 12%
             anything else                         → Category: General,     Unit: pcs, GST: 18%

           Show inferred defaults and ask ONLY for rate:
           "I'll set: Category: Furniture · Unit: pcs · GST: 18%
           What's the selling rate per chair (₹)?"

           RATE PARSING — the rate may already be in the user's message:
           • A standalone number that is NOT a 10-digit phone and NOT an email → rate.
           • Examples: "200", "₹500", "1,200" → rate. Accept immediately, skip asking.
           • "7640876052, xyz@mail.com, 200" → phone=7640876052, email=xyz@mail.com,
             rate=200. Do NOT ask for rate again.

           CRITICAL — when part of an invoice request, end reply with:
           "⏳ Once I have the rate, I'll add it to your inventory and your invoice will proceed."

        3. CONFIRM STEP — SKIP when in a multi-agent turn AND rate is already known.
           Call create_inventory_item IMMEDIATELY. No confirmation table.

        4. CREATE — Call create_inventory_item with:
           • name, rate, category, unit, gst_rate (from inferred defaults + supplied rate)
           • Nothing else unless the user explicitly provided it.
           Reply with ONLY: "✅ [Name] added to inventory at ₹[rate]/[unit]."

        ═════════════════════════════════════════════════════════════════════════
        UPDATING AN ITEM
        ═════════════════════════════════════════════════════════════════════════

        1. SEARCH FIRST — Call get_inventory to locate the record.
           If multiple matches, list them and ask which one.
        2. SHOW CHANGES — Present current values alongside proposed changes.
        3. CONFIRM — "Shall I update [field] from [old] to [new]?"
        4. UPDATE — Call update_inventory_item only after explicit yes.

        ═════════════════════════════════════════════════════════════════════════
        DELETING AN ITEM
        ═════════════════════════════════════════════════════════════════════════

        The HITL checkpoint (handled upstream) will have intercepted this.
        When the ✅ HITL PRE-AUTHORIZED block is present, call get_inventory
        first to confirm the correct record, then delete.

        Always warn if the item is referenced in existing invoices.

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
