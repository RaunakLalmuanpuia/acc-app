<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\BankTransaction\GetBankTransactions;
use App\Ai\Tools\BankTransaction\NarrateTransaction;
use App\Ai\Tools\BankTransaction\ReconcileTransaction;
use App\Ai\Tools\BankTransaction\UpdateTransactionReviewStatus;
use App\Ai\Tools\Narration\GetNarrationHeads;
use App\Ai\Tools\Narration\GetNarrationSubHeads;
//use App\Ai\Tools\Invoice\GetInvoices;
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

        2. EXTRACT VENDOR — Parse the vendor / party name from raw_narration.
           UPI narrations follow the pattern:
             UPI/CR/{ref}/{vendor_upi_id}/{name}  or  UPI/DR/{ref}/{vendor_upi_id}/{name}
           Extract the last segment as the vendor name. Use it to infer
           the likely transaction purpose alongside the amount and type.

        3. SUGGEST HEAD FIRST — Call get_narration_heads, then propose the most
           appropriate narration HEAD based on vendor name + transaction type + amount.
           Present to the user:
           │ Transaction  │ ₹[amount] [type] on [date]
           │ Vendor       │ [extracted vendor name]
           │ Raw          │ [raw_narration]
           │ Suggested Head │ [Head name] — [one-line reason why]
           Ask: "Does this head look right? I'll then suggest a sub-category."

           • For credit transactions → favour Sales, Income, Receipts, Advance Payment heads.
           • For debit transactions  → favour Purchases, Expenses, Payments heads.
           • If the vendor name strongly implies a specific head (e.g. "Zomato" → Food Expense,
             "GST Portal" → Tax Payment), use that directly.
           • If uncertain between two heads, present both and ask the user to pick.

        4. SUGGEST SUB-HEAD — Once the head is confirmed, call get_narration_sub_heads
           for that head and propose the best matching sub-head.
           │ Sub-category │ [Sub-head name] — [one-line reason why]
           │ Party        │ [vendor name]
           │ Note         │ [concise description of the transaction]
           Ask: "Shall I apply this categorisation?"

           The note must always be provided — derive it from vendor + raw_narration,
           e.g. "UPI payment from Infosys — likely invoice settlement" or
           "Purchase at Staples — office supplies".
           If no suitable sub-head exists, offer to apply the head only.

        5. NARRATE — Call narrate_transaction only after explicit approval.
           Pass narration_head_id, narration_sub_head_id (if confirmed), the suggested note,
           and party_name (extracted vendor name).
           Use source = 'ai_suggested' when you selected the category; 'manual' if the user
           specified it directly.

        6. BULK NARRATION — If the user asks to narrate multiple transactions at
           once ("categorise all pending transactions"), present a grouped summary
           table of your suggestions first, wait for approval, then narrate all
           in sequence. Do NOT narrate one-by-one without showing the full plan.

           Bulk table columns: date | amount | raw_narration | category | suggested note.

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
        • Present in a table: date | bank_reference | amount | raw_narration | category | status.
        • Always include bank_reference — it is the bank's own transaction ID and the primary way users identify a transaction.
        • Omit the type column when the user has filtered to a single type (e.g. 'show credits') — it is redundant in that context.
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
//            new GetInvoices($this->user),
        ];
    }
}
