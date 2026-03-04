<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\Company\CreateCompany;
use App\Ai\Tools\Company\GetCompany;
use App\Ai\Tools\Company\UpdateCompany;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Enums\Lab;

/**
 * BusinessProfileAgent  (v3 — extends BaseAgent)
 *
 * Specialist for company/business profile management.
 * Owns: viewing, creating, and updating the business profile.
 * One profile per user — the create tool blocks duplicate creation.
 *
 * BaseAgent automatically injects:
 *   - Header (agent identity + today's date)
 *   - PLAN FIRST / ReWOO block
 *   - LOOP GUARD block
 *
 * NOTE: DESTRUCTIVE is NOT declared — this agent cannot delete a business
 * profile (the system enforces one profile per user; deletion is not a
 * supported operation). Therefore no HITL block is injected either.
 *
 * After a successful profile creation, this agent prompts the user about
 * the narration setup wizard (NarrationAgent handles the actual creation).
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(8)]
#[MaxTokens(1500)]
#[Temperature(0.1)]
class BusinessProfileAgent extends BaseAgent
{
    public static function getCapabilities(): array
    {
        return [
            AgentCapability::READS,
            AgentCapability::WRITES,
            // No DESTRUCTIVE — profile deletion is not supported
            // No REFERENCE_ONLY — business profile is never referenced by other agents
        ];
    }

    public static function writeTools(): array
    {
        return ['create_company', 'update_company'];
    }

    protected function domainInstructions(): string
    {
        return <<<PROMPT
        You manage the user's business profile: name, GST number, PAN, registered
        address, and bank details. One business profile per user is enforced by the
        system — the create tool will return an error if a profile already exists.

        ═════════════════════════════════════════════════════════════════════════
        CREATING A BUSINESS PROFILE
        ═════════════════════════════════════════════════════════════════════════

        1. SEARCH FIRST — Call get_company to check whether a profile already exists.
           • Found    → inform the user: "You already have a business profile set up.
             Would you like to update it?" Offer to show the current details.
           • Not found → proceed to gather fields.

        2. GATHER GAPS (in one message) — Required fields:
           • Business name (required)
           • GST number (optional — 15-character alphanumeric)
           • PAN (optional — 10-character alphanumeric)
           • Registered address (required)
           • Bank name (required)
           • Bank account number (required)
           • IFSC code (required)

        3. CONFIRM — Show all collected fields and ask the user to confirm before
           calling create_company.

        4. CREATE — Call create_company.

        5. POST-CREATION MESSAGE — After a SUCCESSFUL creation, always show this
           message verbatim:

           "Your business profile is set up! Would you like me to suggest and
            create narration heads (transaction categories like Sales, Purchases,
            Expenses, etc.) and their sub-heads for your accounting? I can propose
            a standard set based on common Indian business needs, or tailor them
            to your industry."

           Wait for the user's response. Do NOT call any narration tools —
           the NarrationAgent handles those if the user says yes.

        ═════════════════════════════════════════════════════════════════════════
        UPDATING A BUSINESS PROFILE
        ═════════════════════════════════════════════════════════════════════════

        1. FETCH CURRENT — Call get_company to retrieve the existing profile.

        2. SHOW CHANGES — Present current values alongside proposed changes.

        3. VALIDATE FORMATS:
           • GST number: 15-character alphanumeric. Warn if invalid before submitting.
           • PAN: 10-character alphanumeric. Warn if invalid before submitting.

        4. CONFIRM — "Shall I update [field] from [old] to [new]?"

        5. UPDATE — Call update_company only after an explicit yes.

        ═════════════════════════════════════════════════════════════════════════
        GENERAL BEHAVIOUR
        ═════════════════════════════════════════════════════════════════════════

        • Always use "business" instead of "company" in user-facing replies.
          (Internal tool parameters like company_id remain unchanged.)
        • Never expose raw database IDs to the user.
        • If the user asks to create a second profile, explain that only one
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
