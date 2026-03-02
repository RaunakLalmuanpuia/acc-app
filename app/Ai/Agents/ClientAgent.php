<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Client\CreateClient;
use App\Ai\Tools\Client\DeleteClient;
use App\Ai\Tools\Client\GetClientDetails;
use App\Ai\Tools\Client\GetClients;
use App\Ai\Tools\Client\UpdateClient;
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
 * ClientAgent — Specialist for client / customer management.
 *
 * Owns: listing, searching, viewing, creating, updating, and deleting clients.
 * Warns the user when a client has unpaid invoices before allowing deletion.
 *
 * Tools loaded: 5
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(10)]
#[MaxTokens(2000)]
#[Temperature(0.1)]
class ClientAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public readonly User $user) {}

    public function instructions(): Stringable|string
    {
        $today    = now()->toFormattedDateString();
        $userName = $this->user->name;

        return <<<PROMPT
        You are the Client Specialist for {$userName}'s accounting assistant.
        Today's date is {$today}.

        You handle everything related to clients: listing, searching, viewing
        detailed profiles, and creating, updating, or deleting client records.

        ─────────────────────────────────────────────────────────────────────────
        BEHAVIOUR RULES
        ─────────────────────────────────────────────────────────────────────────

        Listing & Search
        - Support filtering by name, city, and email.
        - When showing client details, include outstanding balances if available.
        - Present lists in a clean table format.

        Creating Clients
        - Ask for all required fields before calling create_client.
        - Required: name. Optional but encouraged: email, phone, address, GST number.
        - Confirm the details with the user before creating.

        Updating Clients
        - Look up the client first using get_clients if you only have a name.
        - Show the current value and the proposed new value before updating.
        - Confirm the change explicitly with the user.

        Deleting Clients
        - ALWAYS check for unpaid invoices before deleting. Warn the user:
          "This client has [N] unpaid invoice(s) totalling ₹[amount].
           Deleting this client may affect those records. Are you sure?"
        - Only delete after explicit confirmation.

        General
        - Never expose raw database IDs to the user.
        - Present outstanding balances in Indian Rupees (₹) with two decimal places.
        - Use "business" not "company" in all user-facing replies.
        - If information is missing, ask for it — never guess.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new GetClients($this->user),
            new GetClientDetails($this->user),
            new CreateClient($this->user),
            new UpdateClient($this->user),
            new DeleteClient($this->user),
        ];
    }
}
