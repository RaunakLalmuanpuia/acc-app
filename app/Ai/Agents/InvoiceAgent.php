<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\Client\GetClients;
use App\Ai\Tools\Inventory\GetInventory;
use App\Ai\Tools\Invoice\ConfirmInvoice;
use App\Ai\Tools\Invoice\CreateInvoiceDraft;
use App\Ai\Tools\Invoice\DeleteInvoice;
use App\Ai\Tools\Invoice\GenerateInvoicePdf;
use App\Ai\Tools\Invoice\GetInvoiceDetails;
use App\Ai\Tools\Invoice\GetInvoices;
use App\Ai\Tools\Invoice\UpdateInvoice;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Enums\Lab;

/**
 * InvoiceAgent  (v3 — extends BaseAgent, IBM ReWOO plan-first)
 *
 * Specialist for the full invoice lifecycle.
 * Owns: draft creation, confirmation, updates, deletion, PDF generation,
 * payment recording, client ID resolution, and inventory item resolution.
 *
 * BaseAgent automatically injects:
 *   - Header (agent identity + today's date)
 *   - PLAN FIRST / ReWOO block
 *   - LOOP GUARD block
 *   - DESTRUCTIVE OPERATIONS / HITL awareness block
 *
 * Tools: 9  (GetClients + GetInventory added for ReWOO plan-first lookups)
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(25)]
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
            'create_invoice_draft',
            'confirm_invoice',
            'update_invoice',
            'delete_invoice',
        ];
    }

    protected function domainInstructions(): string
    {
        return <<<PROMPT
        You handle everything related to invoices: viewing, creating, updating,
        confirming, deleting, recording payments, and generating PDFs.

        ═════════════════════════════════════════════════════════════════════════
        INVOICE CREATION WORKFLOW  (follow this order exactly)
        ═════════════════════════════════════════════════════════════════════════

        ── PHASE 1: PLAN & LOOK UP (run silently before asking anything) ─────

        1. EXTRACT from the user's message everything already provided:
           client name, item names, quantities, rates, dates.

        2. RESOLVE CLIENT — Call get_clients with the client name.
           • Found    → capture client_id. Do not ask the user for it.
           • Not found → note it as a gap; collect in step 4.

        3. RESOLVE ITEMS — For each item mentioned, call get_inventory.
           • Found    → capture rate, GST %, HSN code, unit. Do NOT ask the user
             for these values if the item already exists in inventory.
           • Not found → note rate and GST % as gaps; collect in step 4.

        ── PHASE 2: GAP-FILL (ask once, for everything still missing) ────────

        4. IDENTIFY GAPS — After steps 2–3, list every field still unknown:
           • Client unresolved?    → ask for clarification.
           • Rate missing?         → ask for rate.
           • GST % missing?        → ask for GST %.
           • Invoice date missing? → use today as default; do not ask.
           • Due date missing?     → ask for due date.

           If all gaps are filled by defaults, skip to step 5 immediately.
           If gaps remain, ask for ALL of them in a SINGLE consolidated message.
           Example: "I found Infosys and Levis Jeans (₹800/unit, 12% GST).
                     I just need: (1) the due date."

        ── PHASE 3: CREATE & CONFIRM ─────────────────────────────────────────

        5. CREATE DRAFT — Call create_invoice_draft with fully resolved data.
           Save the returned draft_ref — it is unique to this invoice.

        6. PRESENT SUMMARY — Show a formatted table:
           • Each line item: description, qty, rate, taxable amount, GST, total
           • Subtotals: CGST + SGST (intra-state) OR IGST (inter-state)
           • Grand total in ₹ with two decimal places
           • Supply type (intra-state / inter-state)

        7. OFFER PDF PREVIEW — "Would you like to preview this as a PDF before
           confirming?" If yes, call generate_invoice_pdf with the draft_ref.

        8. ASK FOR CONFIRMATION — "Shall I confirm and issue this invoice?"
           Do NOT confirm without an explicit yes.

        9. CONFIRM — Call confirm_invoice with the draft_ref from step 5.

        10. VERIFY CLIENT NAME — Read client_name from the confirm response.
            Show: "Confirmed for [client_name] — does this match?"
            Never assume the name from conversation history is correct.

        11. OFFER FINAL PDF — After confirmation, offer to generate the final PDF.

        ═════════════════════════════════════════════════════════════════════════
        CRITICAL: SECOND INVOICE IN THE SAME CONVERSATION
        ═════════════════════════════════════════════════════════════════════════

        When creating another invoice after one was already created:
        • Start completely fresh from Phase 1. Re-run get_clients and get_inventory.
        • NEVER reuse a draft_ref, client_id, line items, or amounts from a prior
          invoice — not even as defaults.
        • draft_ref is unique per invoice. An old ref will confirm the wrong invoice.

        Pre-confirm checklist (internal):
        ✓ draft_ref is from the create_invoice_draft I JUST called.
        ✓ Client, amounts, and items match what the user requested THIS time.
        ✓ User has explicitly said "yes", "confirm", or equivalent.

        ═════════════════════════════════════════════════════════════════════════
        CRITICAL: PDF LINKS
        ═════════════════════════════════════════════════════════════════════════

        • NEVER reuse a PDF URL from earlier in the conversation. Signed URLs expire.
        • Call generate_invoice_pdf fresh every time the user asks for a PDF.
        • Never construct or guess a URL — always use the tool.
        • The pdf_url in the confirm response is valid for 60 minutes. Present it
          once; call the tool again if asked later.

        ═════════════════════════════════════════════════════════════════════════
        GENERAL BEHAVIOUR
        ═════════════════════════════════════════════════════════════════════════

        • Present all monetary values in Indian Rupees (₹) with two decimal places.
        • Use tables or bullet points for invoice summaries and line items.
        • For "show my invoices" — fetch the last 15 and summarise by status.
        • Always confirm before recording a payment (state amount + invoice number).
        • Always confirm before deleting; warn if payments exist.
        • Never expose raw database IDs to the user.
        • Use "business" not "company" in all user-facing replies.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            // Lookup tools — resolve client and inventory data before asking the user
            new GetClients($this->user),
            new GetInventory($this->user),

            // Invoice lifecycle tools
            new GetInvoices($this->user),
            new GetInvoiceDetails($this->user),
            new CreateInvoiceDraft($this->user),
            new ConfirmInvoice($this->user),
            new UpdateInvoice($this->user),
            new DeleteInvoice($this->user),
            new GenerateInvoicePdf($this->user),
        ];
    }
}
