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
 * RouterAgent  (v6 — bank_transaction listing fix)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v5
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * FIX 1 — bank_transaction domain definition
 *   Added casual trigger phrases ("show me my transactions", "list transactions",
 *   "recent payments", etc.) to the definition. gpt-4o-mini at Temperature(0)
 *   was mapping these to 'unknown' because the v5 definition only listed formal
 *   verbs (reviewing, categorising, reconciling) with no listing/viewing examples.
 *
 * FIX 2 — Rule 8: bank_transaction listing examples
 *   Concrete ✓ RIGHT examples anchor the model to the correct classification
 *   for the full family of "show/list/view transactions" queries.
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
        $coRoutingRules    = $this->buildCoRoutingRules();

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

        {$coRoutingRules}

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
            // FIX 1: added casual listing/viewing trigger phrases so gpt-4o-mini does not
            // map "show me my transactions" / "list transactions" to 'unknown'.
            'bank_transaction' => 'reviewing, categorising, flagging, reconciling, or LISTING / SHOWING /
                                   VIEWING bank transactions; transaction history; matching credits to
                                   invoices. Casual triggers: "show me my transactions", "list
                                   transactions", "what came in", "recent payments / debits / credits",
                                   "my bank entries", "any new transactions".',
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

    private function buildCoRoutingRules(): string
    {
        return <<<RULES

        7. "narration" + "bank_transaction" CO-ROUTING (critical):
           When the user asks to CREATE a narration head AND the conversation
           context involves a bank transaction (words like: assign it, use it,
           categorize, this transaction, this payment, this credit, this debit,
           narrate it, as the head), return BOTH intents.

           ✗ WRONG: "create head ABC and assign it as head" (mid-transaction flow)
                     → ["narration"]
           ✓ RIGHT:  "create head ABC and assign it as head" (mid-transaction flow)
                     → ["narration", "bank_transaction"]

           Trigger signals — include "bank_transaction" alongside "narration" when:
             • Pronouns like "it", "this", "that" follow a creation request
               (they refer back to the active transaction)
             • Phrases: "assign it", "use it", "as the head", "for this",
               "then categorize", "then narrate"

           NOTE: "add a narration head called X" with NO transaction context
                 → ["narration"] only. The pronoun/assignment phrase is the key signal.

        8. "bank_transaction" LISTING / VIEWING queries:
           Any message asking to see, list, show, or fetch transactions — regardless
           of phrasing formality — is "bank_transaction". Never map these to "unknown".

           ✓ RIGHT: "show me my transactions"          → ["bank_transaction"]
           ✓ RIGHT: "list transactions"                → ["bank_transaction"]
           ✓ RIGHT: "what came in this month"          → ["bank_transaction"]
           ✓ RIGHT: "any recent payments"              → ["bank_transaction"]
           ✓ RIGHT: "show new debits"                  → ["bank_transaction"]
           ✓ RIGHT: "my bank entries for last week"    → ["bank_transaction"]
           ✓ RIGHT: "have any credits arrived"         → ["bank_transaction"]
        RULES;
    }
}
