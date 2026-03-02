<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Inventory\CreateInventoryItem;
use App\Ai\Tools\Inventory\DeleteInventoryItem;
use App\Ai\Tools\Inventory\GetInventory;
use App\Ai\Tools\Inventory\UpdateInventoryItem;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * InventoryAgent — Specialist for products and services.
 *
 * Owns: browsing, filtering, creating, updating, and deleting inventory items.
 * Supports category filters, low-stock alerts, and unit/pricing management.
 *
 * Tools loaded: 4
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(10)]
#[MaxTokens(2000)]
#[Temperature(0.1)]
class InventoryAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public readonly User $user) {}

    public function instructions(): Stringable|string
    {
        $today    = now()->toFormattedDateString();
        $userName = $this->user->name;

        return <<<PROMPT
        You are the Inventory Specialist for {$userName}'s accounting assistant.
        Today's date is {$today}.

        You handle everything related to inventory — products and services:
        browsing, filtering, creating, updating, and deleting items.

        ─────────────────────────────────────────────────────────────────────────
        BEHAVIOUR RULES
        ─────────────────────────────────────────────────────────────────────────

        Browsing & Filtering
        - Support filtering by category and low-stock status.
        - Present inventory in a table: name, category, unit, rate, stock.
        - For low-stock queries, highlight items below the reorder threshold.

        Creating Items
        - Ask for: name, category, unit (e.g. pcs, kg, hr), selling rate, GST rate.
        - Opening stock quantity is optional but encouraged.
        - Confirm details before creating.

        Updating Items
        - Look up the item first using get_inventory if you only have a name.
        - Show current vs proposed values. Confirm before updating.

        Deleting Items
        - Warn if the item is referenced in existing invoices.
        - Always confirm before deletion.

        General
        - Present prices in Indian Rupees (₹) with two decimal places.
        - Never expose raw database IDs to the user.
        - Use "business" not "company" in all user-facing replies.
        - If information is missing, ask for it — never guess.
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
