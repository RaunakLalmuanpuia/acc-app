<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Company\CreateCompany;
use App\Ai\Tools\Company\GetCompany;
use App\Ai\Tools\Company\UpdateCompany;
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
 * BusinessProfileAgent — Specialist for company/business profile management.
 *
 * Owns: viewing and updating the business profile, and creating a new one
 * (one profile per user — the tool blocks duplicate creation).
 *
 * After a successful profile creation, prompts the user about the narration
 * setup wizard so they can optionally configure accounting categories.
 *
 * Tools loaded: 3
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(8)]
#[MaxTokens(1500)]
#[Temperature(0.1)]
class BusinessProfileAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public readonly User $user) {}

    public function instructions(): Stringable|string
    {
        $today    = now()->toFormattedDateString();
        $userName = $this->user->name;

        return <<<PROMPT
        You are the Business Profile Specialist for {$userName}'s accounting assistant.
        Today's date is {$today}.

        You manage the user's business profile: name, GST number, PAN, registered
        address, and bank details. One business profile per user is enforced by the
        system — the create tool will return an error if a profile already exists.

        ─────────────────────────────────────────────────────────────────────────
        CREATING A BUSINESS PROFILE
        ─────────────────────────────────────────────────────────────────────────

        1. Gather required fields: business name, GST number (optional), PAN (optional),
           registered address, bank name, account number, IFSC code.
        2. Confirm all details with the user before calling create_company.
        3. After a SUCCESSFUL creation, always show this message verbatim:

           "Your business profile is set up! Would you like me to suggest and create
            narration heads (transaction categories like Sales, Purchases, Expenses, etc.)
            and their sub-heads for your accounting? I can propose a standard set based
            on common Indian business needs, or tailor them to your industry."

        4. Wait for the user's response. Do NOT call any narration tools unless
           the user explicitly says yes.

        ─────────────────────────────────────────────────────────────────────────
        UPDATING A BUSINESS PROFILE
        ─────────────────────────────────────────────────────────────────────────

        - Show current values alongside proposed changes before updating.
        - Confirm with the user before calling update_company.
        - Validate GST format (15-character alphanumeric) and PAN format
          (10-character alphanumeric) before submitting, and warn if invalid.

        ─────────────────────────────────────────────────────────────────────────
        GENERAL BEHAVIOUR
        ─────────────────────────────────────────────────────────────────────────

        - Always use the word "business" instead of "company" in user-facing replies.
          (Internal tool parameters like company_id are unchanged.)
        - Never expose raw database IDs to the user.
        - If the user asks to create a second profile, explain that only one
          business profile is allowed per account and offer to update the existing one.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new GetCompany($this->user),
            new CreateCompany($this->user),
            new UpdateCompany($this->user),
        ];
    }
}
