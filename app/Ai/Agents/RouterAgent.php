<?php

namespace App\Ai\Agents;

use App\Ai\AgentRegistry;
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
 * RouterAgent  (v4 — AgentRegistry-driven, fully dynamic)
 *
 * Ultra-cheap, stateless intent classifier.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v3
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v3 hardcoded Rules 6 and 7 specifically for 'client' and 'inventory'.
 * That meant every new agent with REFERENCE_ONLY capability required a manual
 * edit to the router's instructions — a maintenance trap.
 *
 * v4 derives the routing suppression rules dynamically from AgentRegistry:
 *   - AgentRegistry::referenceOnlyIntents() returns all intents whose agent
 *     declares AgentCapability::REFERENCE_ONLY.
 *   - instructions() builds the suppression rules in a loop — adding a new
 *     REFERENCE_ONLY agent automatically adds a new router rule with zero
 *     manual changes to this file.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IBM ALIGNMENT
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * IBM Plan-and-Execute:
 *   "A capable model creates a strategy that cheaper models execute — reducing
 *    costs by up to 90%." (ibm.com/think/topics/ai-agents)
 *
 * The RouterAgent IS the planner. Its job is precise classification so that
 * the expensive Worker agents (gpt-4o specialists) are only dispatched when
 * genuinely needed. Over-routing defeats the entire Plan-and-Execute pattern.
 *
 * IBM on intent precision:
 *   "When in doubt, exclude the domain. A missed intent costs one clarifying
 *    question. An extra intent costs one unnecessary gpt-4o call."
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
     * Derived from AgentRegistry — keep this in sync.
     */
    public const INTENTS = [
        'invoice',
        'client',
        'inventory',
        'narration',
        'business',
        'bank_transaction',
        'unknown',
    ];

    public function instructions(): Stringable|string
    {
        $intents          = implode(' | ', self::INTENTS);
        $domainDefs       = $this->buildDomainDefinitions();
        $suppressionRules = $this->buildSuppressionRules();

        return <<<PROMPT

        This assistant exists solely to help users manage their accounting data:
        invoices, clients, inventory, narration heads, business profile, and bank transactions.

        You are NOT a general-purpose AI. You are NOT a chatbot. You are NOT an assistant
        for coding, writing, travel, legal advice, medical advice, or any other domain.

        Your only job is to classify the user's message into one of the accounting intents
        below. If the message is not about accounting, classify it as "unknown" — do NOT
        attempt to answer it, do NOT engage with it, do NOT acknowledge the topic.


        Your only job is to read the user's message and classify it into one or more
        of the following domain intents:

            {$intents}

        ─────────────────────────────────────────────────────────────────────────
        DOMAIN DEFINITIONS
        ─────────────────────────────────────────────────────────────────────────

        {$domainDefs}

        ─────────────────────────────────────────────────────────────────────────
        RULES  (read every rule before deciding)
        ─────────────────────────────────────────────────────────────────────────

        1. Return ONLY a raw JSON object — no markdown code fences, no explanation,
           no preamble.

        2. Multi-intent is allowed ONLY when the message explicitly requests
           operations in multiple domains. Both operations must be primary goals,
           not incidental references.

        3. Use "unknown" when the message is a greeting, thank-you, or off-topic.

        4. When in doubt, EXCLUDE the domain. Prefer fewer intents over more.
           A missed intent costs one clarifying question.
           An extra intent costs one unnecessary specialist agent call.

        5. Never include the same intent twice.

        {$suppressionRules}

        ─────────────────────────────────────────────────────────────────────────
        OUTPUT FORMAT (strict)
        ─────────────────────────────────────────────────────────────────────────

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

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Build the domain definitions block.
     * Hardcoded descriptions per intent — these are human-curated and intentionally
     * not derived from agent classes (agents don't know their own routing description).
     */
    private function buildDomainDefinitions(): string
    {
        $definitions = [
            'invoice'   => 'creating, confirming, viewing, updating, deleting, or generating
                     PDFs for invoices; recording payments; checking overdue invoices.',
            'client'    => 'listing, searching, creating, updating, deleting, or looking up client records as the PRIMARY GOAL of the message.',
            'inventory' => 'listing, searching, creating, updating, deleting, or looking up inventory items / products / services as the PRIMARY GOAL.',
            'narration' => 'narration heads, sub-heads, transaction categories, ledger heads.',
            'business'  => 'company/business profile, GST number, PAN, bank details, address.',
            'bank_transaction' => 'reviewing, narrating (categorising), flagging, or reconciling
                         bank transactions; viewing transaction history; matching credits to invoices.',  // ← ADD THIS
            'unknown'   => 'greetings, thank-yous, out-of-scope questions, or anything
                     unrelated to accounting.',
        ];

        $lines = [];
        foreach ($definitions as $intent => $description) {
            $lines[] = "  {$intent}" . str_repeat(' ', max(1, 12 - strlen($intent))) . "→ {$description}";
        }

        return implode("\n", $lines);
    }

    /**
     * Dynamically build the REFERENCE_ONLY suppression rules from AgentRegistry.
     *
     * For every intent that declares AgentCapability::REFERENCE_ONLY, we emit
     * a numbered rule explaining that merely referencing that domain's entity
     * inside another domain's request does not constitute an intent for it.
     *
     * This means adding a new REFERENCE_ONLY agent (e.g. NarrationAgent if
     * narration heads are referenced inside invoice narrations) automatically
     * produces a new rule here — no manual edit required.
     */
    private function buildSuppressionRules(): string
    {
        $referenceOnlyIntents = AgentRegistry::referenceOnlyIntents();

        if (empty($referenceOnlyIntents)) {
            return '';
        }

        // Static examples per intent — used in the rule to make it concrete
        $examples = [
            'client'    => [
                'reference' => 'mentioning a client name inside an invoice request',
                'wrong'     => '"create invoice for Infosys"          → ["invoice","client"]',
                'right1'    => '"create invoice for Infosys"          → ["invoice"]',
                'right2'    => '"add a new client called Infosys"     → ["client"]',
                'right3'    => '"add Infosys and invoice them ₹5000"  → ["client","invoice"]',
            ],
            'inventory' => [
                'reference' => 'mentioning a product name or quantity inside an invoice request',
                'wrong'     => '"add 20 units of Samsung TV to invoice"     → ["invoice","inventory"]',
                'right1'    => '"add 20 units of Samsung TV to invoice"     → ["invoice"]',
                'right2'    => '"add Samsung TV to inventory at ₹54,999"    → ["inventory"]',
                'right3'    => '"add Samsung TV to inventory and invoice 5" → ["inventory","invoice"]',
            ],
        ];

        $rules  = [];
        $ruleNo = 6;

        foreach ($referenceOnlyIntents as $intent) {
            $ex = $examples[$intent] ?? null;

            if ($ex) {
                $rules[] = <<<RULE
                {$ruleNo}. CRITICAL — "{$intent}" intent = user's PRIMARY GOAL is to manage a {$intent} record.
                   {$ex['reference']} is NOT a {$intent} intent — the specialist agent already
                   has lookup tools to resolve {$intent} names without a separate dispatch.
                   ✗ WRONG: {$ex['wrong']}
                   ✓ RIGHT: {$ex['right1']}
                   ✓ RIGHT: {$ex['right2']}
                   ✓ RIGHT: {$ex['right3']}
                RULE;
            } else {
                // Generic rule for intents without curated examples
                $rules[] = <<<RULE
                {$ruleNo}. CRITICAL — "{$intent}" intent means the user's PRIMARY GOAL is to perform
                   a {$intent} management operation. Merely referencing a {$intent} name or concept
                   inside another domain's request does NOT constitute a "{$intent}" intent.
                RULE;
            }

            $ruleNo++;
        }

        return implode("\n\n", $rules);
    }
}
