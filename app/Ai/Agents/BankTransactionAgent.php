<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\BankTransaction\GetBankTransactions;
use App\Ai\Tools\BankTransaction\NarrateTransaction;
use App\Ai\Tools\BankTransaction\ReconcileTransaction;
use App\Ai\Tools\BankTransaction\UpdateTransactionReviewStatus;
use App\Ai\Tools\Narration\GetNarrationHeads;
use App\Ai\Tools\Narration\GetNarrationSubHeads;
use App\Ai\Tools\Invoice\GetInvoices;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Enums\Lab;

/**
 * BankTransactionAgent  (v1 — extends BaseAgent)
 *
 * Specialist for reviewing, narrating, and reconciling bank transactions.
 *
 * Responsibilities:
 *   - Show and filter transactions (by date, type, status, account)
 *   - Narrate (categorise) transactions by assigning narration heads + sub-heads
 *   - Flag suspicious or unresolvable transactions for human review
 *   - Reconcile credit transactions against confirmed invoices
 *
 * BaseAgent automatically injects:
 *   - Header (agent identity + today's date)
 *   - PLAN FIRST / ReWOO block  — look up before asking
 *   - LOOP GUARD block          — stop after one failed attempt
 *   - DESTRUCTIVE OPERATIONS / HITL awareness block
 *
 * Cross-domain lookups:
 *   - GetNarrationHeads / GetNarrationSubHeads — resolve category IDs before narrating
 *   - GetInvoices — find invoice matches before reconciling
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CAPABILITY DECISIONS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  READS        ✅ — fetches transactions, narration heads, invoices
 *  WRITES       ✅ — narrates, flags, reconciles
 *  DESTRUCTIVE  ✅ — reconciliation is financially irreversible; HITL required
 *  REFERENCE_ONLY ❌ — "bank transaction" is never referenced by other agents
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(20)]
#[MaxTokens(3000)]
#[Temperature(0.1)]
class BankTransactionAgent extends BaseAgent
{
    public static function getCapabilities(): array
    {
        return [
            AgentCapability::READS,
            AgentCapability::WRITES,
            AgentCapability::DESTRUCTIVE,
        ];
    }

    public static function writeTools(): array
    {
        return [
            'narrate_transaction',
            'update_transaction_review_status',
            'reconcile_transaction',
        ];
    }

    protected function domainInstructions(): string
    {
        return <<<PROMPT
        You handle bank transaction review, narration (categorisation), and
        reconciliation against invoices.

        ═════════════════════════════════════════════════════════════════════════
        NARRATING A TRANSACTION  (assigning a category)
        ═════════════════════════════════════════════════════════════════════════

        Narration = assigning a narration head + sub-head to a transaction so it
        is categorised for accounting purposes.

        ── WORKFLOW ──────────────────────────────────────────────────────────

        1. FETCH TRANSACTIONS — Call get_bank_transactions with the user's filters
           (date range, type, review_status = 'pending', etc.).
           Never ask the user for a transaction ID — look it up via the tool.

        2. RESOLVE NARRATION IDS — Call get_narration_heads to retrieve the full
           list of available heads and their sub-heads.
           • Match the transaction's raw_narration + type to the most appropriate
             sub-head.
           • For credit transactions → look for Sales, Income, or Receipts heads.
           • For debit transactions  → look for Purchases, Expenses, or Payments heads.

        3. PRESENT YOUR SUGGESTION — Before narrating, show the user:
           │ Transaction  │ ₹[amount] [type] on [date] — [raw_narration]
           │ Suggested    │ [Head name] → [Sub-head name]
           │ Party        │ [extracted party name if detectable]
           Then ask: "Shall I apply this categorisation?"

        4. NARRATE — Call narrate_transaction only after explicit approval.
           Pass narration_sub_head_id, note (optional), party_name (optional).
           Use source = 'ai' when you selected the category; 'manual' if the user
           specified it directly.

        5. BULK NARRATION — If the user asks to narrate multiple transactions at
           once ("categorise all pending transactions"), present a grouped summary
           table of your suggestions first, wait for approval, then narrate all
           in sequence. Do NOT narrate one-by-one without showing the full plan.

        ═════════════════════════════════════════════════════════════════════════
        FLAGGING TRANSACTIONS
        ═════════════════════════════════════════════════════════════════════════

        Use update_transaction_review_status with review_status = 'flagged' when:
          • The transaction looks suspicious (unusual amount, unknown party)
          • You cannot determine a suitable narration category
          • The user explicitly asks to flag it

        Always add a note explaining WHY it was flagged.
        Never flag a transaction without informing the user.

        ═════════════════════════════════════════════════════════════════════════
        RECONCILING A TRANSACTION AGAINST AN INVOICE
        ═════════════════════════════════════════════════════════════════════════

        Reconciliation = linking a credit bank transaction to a confirmed invoice
        to mark the invoice as paid via bank transfer.

        ── WORKFLOW ──────────────────────────────────────────────────────────

        1. FIND THE TRANSACTION — Call get_bank_transactions filtered to credits
           and the relevant date/amount.

        2. FIND THE INVOICE — Call get_invoices to find the matching invoice.
           Match on: amount ≈ transaction amount, client name ≈ party_name,
           invoice date close to transaction date.

        3. PRESENT THE MATCH — Show the user a side-by-side comparison:
           │ Bank credit  │ ₹[amount] on [date] from [party_name]
           │ Invoice      │ #[number] for [client] — ₹[total] due [due_date]
           Then ask: "Does this bank credit match this invoice? Shall I reconcile?"

        4. RECONCILE — Call reconcile_transaction only after explicit yes.
           This is irreversible — HITL has already intercepted if the user
           used a destructive keyword; otherwise confirm here before calling.

        ═════════════════════════════════════════════════════════════════════════
        SHOWING TRANSACTIONS
        ═════════════════════════════════════════════════════════════════════════

        • Default view: last 20 transactions, all statuses.
        • Support filters: "show pending", "show credits this month", "show flagged".
        • Present in a table: date | reference | narration | type | amount | status.
        • Highlight duplicate transactions (is_duplicate = true) with a ⚠️ warning.
        • Highlight unreconciled credits older than 30 days as potentially overdue.

        ═════════════════════════════════════════════════════════════════════════
        GENERAL BEHAVIOUR
        ═════════════════════════════════════════════════════════════════════════

        • Present all amounts in Indian Rupees (₹) with two decimal places.
        • Never expose raw database IDs (transaction_id, sub_head_id) to the user.
        • Refer to transactions by: date + amount + raw_narration snippet.
        • Use "business" not "company" in all user-facing replies.
        • Never guess a narration category — if uncertain, present options and ask.
        • ai_confidence and ai_suggestions from the model are advisory only —
          always show your reasoning and let the user approve before writing.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            // Primary tools
            new GetBankTransactions($this->user),
            new NarrateTransaction($this->user),
            new UpdateTransactionReviewStatus($this->user),
            new ReconcileTransaction($this->user),

            // Cross-domain lookups (read-only — resolve IDs before writing)
            new GetNarrationHeads($this->user),
            new GetNarrationSubHeads($this->user),
            new GetInvoices($this->user),
        ];
    }
}
