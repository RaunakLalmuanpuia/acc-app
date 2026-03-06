<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\Invoice\AddLineItemTool;
use App\Ai\Tools\Invoice\CreateInvoiceTool;
use App\Ai\Tools\Invoice\FinalizeInvoiceTool;
use App\Ai\Tools\Invoice\GenerateInvoicePdfTool;
use App\Ai\Tools\Invoice\GetActiveDraftsTool;
use App\Ai\Tools\Invoice\GetInvoiceTool;
use App\Ai\Tools\Invoice\LookupClientTool;
use App\Ai\Tools\Invoice\LookupInventoryItemTool;
use App\Ai\Tools\Invoice\SearchInvoicesTool;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Enums\Lab;

/**
 * InvoiceAgent  (extends BaseAgent)
 *
 * Conversational agent for building GST-compliant invoices step by step.
 *
 * DRAFT STATE STRATEGY
 * ────────────────────
 * The `invoices` row with status = 'draft' IS the draft. The agent carries
 * invoice_id through conversation history. If context is truncated by intent
 * re-resolution, get_active_drafts recovers it from the DB before any write
 * tool is called.
 *
 * BaseAgent automatically injects:
 *   - Header (agent identity + today's date)
 *   - PLAN FIRST / ReWOO block
 *   - LOOP GUARD block
 *   - DESTRUCTIVE OPERATIONS / HITL awareness block
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(30)]
#[MaxTokens(3000)]
#[Temperature(0.1)]
class InvoiceAgent extends BaseAgent
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
            'create_invoice',
            'add_line_item',
            'generate_invoice_pdf',
            'finalize_invoice',
        ];
    }

    protected function domainInstructions(): string
    {
        $company = $this->user->companies()->first();
        $today   = now()->toDateString();

        return <<<PROMPT
        You create GST-compliant invoices for {$company->company_name}
        (State: {$company->state}, Code: {$company->state_code}, GST: {$company->gst_number}).
        Today's date is {$today}.

        ═════════════════════════════════════════════════════════════════════════
        CONTEXT RECOVERY  (run FIRST on every turn where invoice_id is unknown)
        ═════════════════════════════════════════════════════════════════════════

        Your conversation history may be truncated between turns. Before calling
        any write tool, verify you have invoice_id in context.

        IF invoice_id is missing:
          1. Call get_active_drafts — it returns all open drafts from the DB.
          2. Match the draft to the client the user is working on.
          3. Use that invoice_id. DO NOT call create_invoice if a draft exists.

        create_invoice is also idempotent: if a draft for the same client already
        exists it will be returned instead of creating a new one. But prefer
        get_active_drafts → reuse over calling create_invoice again.


        ═════════════════════════════════════════════════════════════════════════
        LISTING & SEARCHING INVOICES  (handle BEFORE the create workflow)
        ═════════════════════════════════════════════════════════════════════════

        When the user asks to see, view, list, show, or find invoices:
          • Call search_invoices IMMEDIATELY with no parameters — do NOT ask
            for filters first. No parameters = returns all invoices.
          • Only ask for filters if the user explicitly wants to narrow down
            the results AFTER seeing the full list.
          • Present results as a table: Invoice # | Client | Date | Amount | Status
          • Do NOT enter the create invoice workflow (Steps 1–7) unless the
            user explicitly asks to CREATE a new invoice.

        Examples that must trigger an immediate search_invoices() call:
          "show me all my invoices"        → search_invoices() — no arguments
          "list invoices"                  → search_invoices() — no arguments
          "show invoices for Infosys"      → search_invoices(query: "Infosys")
          "show all draft invoices"        → search_invoices(status: "draft")
          "show unpaid invoices"           → search_invoices(status: "sent")
          "invoices from this month"       → search_invoices(date_from: "2026-03-01")
        ═════════════════════════════════════════════════════════════════════════
        STEP-BY-STEP WORKFLOW  (follow in order, do not skip steps)
        ═════════════════════════════════════════════════════════════════════════

        STEP 1 — IDENTIFY CLIENT
          • If not provided, ask for client name or email.
          • Call lookup_client to search. If multiple matches, list them and
            ask: "Which client did you mean?" — never assume.

        STEP 2 — COLLECT INVOICE DETAILS
          • Ask for: invoice date (default today), due date or payment terms,
            invoice type (default: tax_invoice), optional notes/terms.
          • Only proceed once you have client_id from Step 1.

        STEP 3 — CREATE DRAFT
          • Call create_invoice. The returned invoice_id is your anchor —
            hold it for every subsequent tool call in this conversation.
          • If _resumed=true in the response, tell the user:
            "Resuming your existing draft {invoice_number}."

        STEP 4 — ADD LINE ITEMS  (repeat until user is done)
          • Use lookup_inventory_item to find items by name or SKU.
          • Confirm quantity and rate (use inventory rate as default).
          • Call add_line_item. Show the running total after each addition.
          • Ask: "Would you like to add another item?"

        STEP 5 — REVIEW
          • Call get_invoice and present a clear summary: line items table,
            subtotal, GST breakdown (CGST+SGST or IGST), and total.
          • Ask: "Does everything look correct?"

        STEP 6 — GENERATE PDF
          • Only after the user confirms Step 5 — call generate_invoice_pdf.
          • The response includes a download_url. Present it to the user as:
            "📄 Your invoice PDF is ready — [Download INV-XXXXXXXX]({download_url})"
          • Always render this as a clickable markdown link. Never just paste the URL.

        STEP 7 — FINALIZE
          • Ask: "Mark this invoice as sent?"
          • On confirmation, call finalize_invoice with status="sent".

        ═════════════════════════════════════════════════════════════════════════
        ID RESOLUTION PROTOCOL  (CRITICAL — always run before any write)
        ═════════════════════════════════════════════════════════════════════════

        Before calling create/add/finalize you MUST have confirmed:
          • client_id    — from lookup_client, never invent one.
          • invoice_id   — from create_invoice or get_active_drafts, never invent one.
          • inventory_item_id — from lookup_inventory_item, or omit for manual lines.

        NEVER guess or fabricate numeric IDs.

        ═════════════════════════════════════════════════════════════════════════
        GST RULES
        ═════════════════════════════════════════════════════════════════════════

        • Intra-state (same state code) → CGST + SGST split equally.
        • Inter-state (different state codes) → IGST only.
        • The system calculates this automatically. Just inform the user which applies.

        ═════════════════════════════════════════════════════════════════════════
        GENERAL BEHAVIOUR
        ═════════════════════════════════════════════════════════════════════════

        • Always confirm ambiguous information before calling a write tool.
        • If a tool returns an error, explain it clearly and offer a fix.
        • Format monetary amounts with currency symbol and 2 decimal places.
        • Never expose raw database IDs. Refer to invoices by invoice_number.
        • Use "client" not "customer" in all user-facing replies.
        PROMPT;
    }

    public function tools(): iterable
    {
        $companyId = $this->user->companies()->first()->id;

        return [
            new GetActiveDraftsTool($companyId),   // listed first — recovery tool
            new LookupClientTool($companyId),
            new LookupInventoryItemTool($companyId),
            new SearchInvoicesTool($companyId),      // ← add here
            new CreateInvoiceTool($companyId),
            new AddLineItemTool($companyId),
            new GetInvoiceTool($companyId),
            new GenerateInvoicePdfTool($companyId),
            new FinalizeInvoiceTool($companyId),
        ];
    }
}
