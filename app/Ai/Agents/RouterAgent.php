<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * RouterAgent — Ultra-cheap, stateless intent classifier.
 *
 * Design decisions:
 *  - gpt-4o-mini   : classification is simple; ~20x cheaper than gpt-4o.
 *  - Temperature(0): deterministic routing is mandatory.
 *  - MaxTokens(200): output is always a tiny JSON object.
 *  - MaxSteps(1)   : a router must never call tools or iterate.
 *  - No RemembersConversations: routing is stateless by design.
 *  - Zero tools    : tool schemas waste tokens with no benefit here.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o-mini')]
#[MaxSteps(1)]
#[MaxTokens(200)]
#[Temperature(0)]
class RouterAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Valid domain intents the router may emit.
     * Keep in sync with AgentDispatcherService::AGENT_MAP.
     */
    public const INTENTS = [
        'invoice',
        'client',
        'inventory',
        'narration',
        'business',
        'unknown',
    ];

    public function instructions(): Stringable|string
    {
        $intents = implode(' | ', self::INTENTS);

        return <<<PROMPT
        You are the intent router for an AI-powered accounting assistant.

        Your only job is to read the user's message and classify it into one or more
        of the following domain intents:

            {$intents}

        ## Domain Definitions

        - invoice     → anything about creating, confirming, viewing, deleting, updating,
                        or generating PDFs for invoices; recording payments; overdue checks.
        - client      → creating, updating, deleting, or looking up clients / customers.
        - inventory   → products, services, stock levels, pricing, categories.
        - narration   → narration heads, sub-heads, transaction categories, ledger heads.
        - business    → company/business profile, GST number, PAN, bank details, address.
        - unknown     → greetings, out-of-scope questions, or anything unrelated to accounting.

        ## Rules

        1. Return ONLY a raw JSON object — no markdown code fences, no explanation, no preamble.
        2. Multi-intent is allowed and encouraged when the message clearly spans multiple domains.
        3. Use "unknown" when the message is a greeting, thank-you, or completely off-topic.
        4. When in doubt, include the domain rather than exclude it.
        5. Never include the same intent twice.

        ## Output Format (strict)

        {"intents": ["invoice"]}
        {"intents": ["client", "invoice"]}
        {"intents": ["unknown"]}
        PROMPT;
    }

    /**
     * The router carries zero tools — tool schemas add tokens with no benefit here.
     */
    public function tools(): iterable
    {
        return [];
    }
}
