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
 * RouterAgent  (v5 — co-routing exception for new clients/inventory)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v4
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The REFERENCE_ONLY suppression rule for "client" now includes an explicit
 * EXCEPTION: when the invoice names a likely-new or unfamiliar client, include
 * "client" so ClientAgent can check existence and create if needed.
 *
 * Similarly, when an invoice names a product that sounds like it may not be in
 * inventory yet (combined with a new/unknown client), include "inventory".
 *
 * The suppression rules are still derived dynamically from AgentRegistry —
 * the exception text is injected via the $examples array which is human-curated.
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o-mini')]
#[MaxSteps(1)]
#[MaxTokens(200)]
#[Temperature(0)]
class RouterAgent implements Agent, HasTools
{
    use Promptable;

    public static function getIntents(): array
    {
        return [...AgentRegistry::validIntents(), 'unknown'];
    }

    public function instructions(): Stringable|string
    {
        $intents          = implode(' | ', self::getIntents());
        $domainDefs       = $this->buildDomainDefinitions();
        $suppressionRules = $this->buildSuppressionRules();

        return <<<PROMPT

        This assistant exists solely to help users manage their accounting data:
        invoices, clients, inventory, narration heads, business profile, and bank transactions.

        You are NOT a general-purpose AI. Your only job is to classify the user's
        message into one or more of the following domain intents:

            {$intents}

        ─────────────────────────────────────────────────────────────────────────
        DOMAIN DEFINITIONS
        ─────────────────────────────────────────────────────────────────────────

        {$domainDefs}

        ─────────────────────────────────────────────────────────────────────────
        RULES  (read every rule before deciding)
        ─────────────────────────────────────────────────────────────────────────

        1. Return ONLY a raw JSON object — no markdown, no explanation.

        2. Multi-intent is allowed ONLY when the message explicitly requests
           operations in multiple domains. Both must be primary goals.

        3. Use "unknown" for greetings, thank-yous, or off-topic messages.

        4. When in doubt, EXCLUDE the domain. A missed intent costs one
           clarifying question. An extra intent costs one unnecessary agent call.

        5. Never include the same intent twice.

        {$suppressionRules}

        ─────────────────────────────────────────────────────────────────────────
        OUTPUT FORMAT (strict)
        ─────────────────────────────────────────────────────────────────────────

        {"intents": ["invoice"]}
        {"intents": ["client", "invoice"]}
        {"intents": ["client", "inventory", "invoice"]}
        {"intents": ["unknown"]}
        PROMPT;
    }

    public function tools(): iterable
    {
        return [];
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function buildDomainDefinitions(): string
    {
        $definitions = [
            'invoice'          => 'creating, confirming, viewing, updating, deleting, or generating
                                   PDFs for invoices; recording payments; checking overdue invoices.',
            'client'           => 'listing, searching, creating, updating, or deleting client records
                                   as the PRIMARY GOAL — OR when invoicing a client who may not exist yet.',
            'inventory'        => 'listing, searching, creating, updating, or deleting inventory items /
                                   products / services as the PRIMARY GOAL.',
            'narration'        => 'narration heads, sub-heads, transaction categories, ledger heads.',
            'business'         => 'company/business profile, GST number, PAN, bank details, address.',
            'bank_transaction' => 'reviewing, categorising, flagging, or reconciling bank transactions;
                                   viewing transaction history; matching credits to invoices.',
            'unknown'          => 'greetings, thank-yous, out-of-scope questions, or anything
                                   unrelated to accounting.',
        ];

        $lines = [];
        foreach ($definitions as $intent => $description) {
            $lines[] = "  {$intent}" . str_repeat(' ', max(1, 18 - strlen($intent))) . "→ {$description}";
        }

        return implode("\n", $lines);
    }

    private function buildSuppressionRules(): string
    {
        $referenceOnlyIntents = AgentRegistry::referenceOnlyIntents();

        if (empty($referenceOnlyIntents)) {
            return '';
        }

        $examples = [
            'client' => [
                'reference' => 'mentioning an EXISTING client name inside an invoice request',
                'wrong'     => '"invoice for Infosys" (Infosys is a known existing client) → ["invoice","client"]',
                'right1'    => '"invoice for Infosys" (Infosys already exists)             → ["invoice"]',
                'right2'    => '"add a new client called Infosys"                          → ["client"]',
                'right3'    => '"add Infosys and invoice them ₹5000"                       → ["client","invoice"]',
                'right4'    => '"create invoice for XYZ, they want 30 chairs"              → ["client","inventory","invoice"]',
                'note'      => 'EXCEPTION — include "client" when the invoice names an unfamiliar
                   or likely-new client (short names, unknown companies, phrases like
                   "new client", "they are new", "just onboarded"). The ClientAgent will
                   check existence and create if needed. When in doubt about whether a
                   client exists, include "client" — the cost of an extra check is lower
                   than the cost of failing to create a needed client.',
            ],
            'inventory' => [
                'reference' => 'mentioning a product name or quantity inside an invoice request',
                'wrong'     => '"add 20 Samsung TVs to invoice" (TVs already in inventory) → ["invoice","inventory"]',
                'right1'    => '"add 20 Samsung TVs to invoice" (item exists)              → ["invoice"]',
                'right2'    => '"add Samsung TV to inventory at ₹54,999"                   → ["inventory"]',
                'right3'    => '"add Samsung TV to inventory and invoice 5 units"          → ["inventory","invoice"]',
                'right4'    => '"create invoice for XYZ, they want 30 chairs"              → ["client","inventory","invoice"]',
                'note'      => 'EXCEPTION — include "inventory" when the invoice names a product
                   alongside a new/unknown client (the product is also likely missing).
                   If the message contains both an unfamiliar client AND an unfamiliar
                   product, return all three: ["client","inventory","invoice"].',
            ],
        ];

        $rules  = [];
        $ruleNo = 6;

        foreach ($referenceOnlyIntents as $intent) {
            $ex = $examples[$intent] ?? null;

            if ($ex) {
                $note   = isset($ex['note'])   ? "\n   NOTE: {$ex['note']}"   : '';
                $right4 = isset($ex['right4']) ? "\n   ✓ RIGHT: {$ex['right4']}" : '';

                $rules[] = <<<RULE
                {$ruleNo}. CRITICAL — "{$intent}" intent = user's PRIMARY GOAL is to manage a {$intent} record.
                   {$ex['reference']} is NOT a {$intent} intent on its own.
                   ✗ WRONG:  {$ex['wrong']}
                   ✓ RIGHT:  {$ex['right1']}
                   ✓ RIGHT:  {$ex['right2']}
                   ✓ RIGHT:  {$ex['right3']}{$right4}{$note}
                RULE;
            } else {
                $rules[] = <<<RULE
                {$ruleNo}. CRITICAL — "{$intent}" intent means the user's PRIMARY GOAL is a {$intent}
                   management operation. Merely referencing a {$intent} name inside another
                   domain's request does NOT constitute a "{$intent}" intent.
                RULE;
            }

            $ruleNo++;
        }

        return implode("\n\n", $rules);
    }
}
