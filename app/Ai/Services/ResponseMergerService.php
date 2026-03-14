<?php

namespace App\Ai\Services;

/**
 * ResponseMergerService  (v2 — HANDOFF suppression + pending signal consolidation)
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v1
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * HANDOFF suppression:
 *   ClientAgent replies with the single word "HANDOFF" when it has nothing
 *   useful to add (client already created, user said "proceed"). This is
 *   filtered out before merging so the user only sees the invoice reply.
 *
 * Pending signal consolidation:
 *   When gather-phase agents (client, inventory) reply with ⏳ pending signals,
 *   InvoiceAgent replies with a short "waiting" sentence. The merger strips
 *   InvoiceAgent's waiting reply and appends a single consolidated ⏳ footer
 *   so the user sees one clear call-to-action instead of three separate sections.
 *
 * Single-content shortcut:
 *   After filtering HANDOFF and invoice-waiting replies, if only one agent
 *   has real content, return it without section headers.
 */
class ResponseMergerService
{
    private const UNKNOWN_RESPONSE =
        "I'm your accounting assistant. I can help you with:\n\n"
        . "• 🧾 **Invoices** — create, confirm, view, or generate PDFs\n"
        . "• 👤 **Clients** — add, update, or look up client records\n"
        . "• 📦 **Inventory** — manage products and services\n"
        . "• 📒 **Narration Heads** — set up transaction categories\n"
        . "• 🏢 **Business Profile** — view or update your business details\n"
        . "• 🏦 **Bank Transactions** — review, categorise, reconcile transactions\n\n"
        . "How can I help you today?";

    private const SECTION_LABELS = [
        'invoice'          => '🧾 Invoice',
        'client'           => '👤 Client',
        'inventory'        => '📦 Inventory',
        'narration'        => '📒 Narration Heads',
        'business'         => '🏢 Business Profile',
        'bank_transaction' => '🏦 Bank Transactions',
    ];

    public function merge(array $responses): string
    {
        // Strip HANDOFF signals — client handoff markers, not user-facing
        $responses = array_filter(
            $responses,
            fn($r) => trim($r) !== 'HANDOFF'
        );

        if (empty($responses)) {
            return '';
        }

        if (count($responses) === 1) {
            return reset($responses);
        }

        return $this->mergeMultiple($responses);
    }

    public function unknownResponse(): string
    {
        return self::UNKNOWN_RESPONSE;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function mergeMultiple(array $responses): string
    {
        $contentParts     = [];
        $hasPendingSignal = false;
        $hasError         = false;

        foreach ($responses as $intent => $reply) {
            $label = self::SECTION_LABELS[$intent] ?? ucfirst($intent);

            if ($intent === 'invoice' && $this->isInvoiceWaiting($reply)) {
                continue;
            }

            // Detect error replies — suppress them from the merged output
            // when other agents succeeded. We'll add a unified retry message.
            if ($this->isErrorReply($reply)) {
                $hasError = true;
                continue;
            }

            if (str_contains($reply, '⏳')) {
                $hasPendingSignal = true;
            }

            $cleanReply     = $this->stripPendingSignal($reply);
            $contentParts[] = "### {$label}\n\n{$cleanReply}";
        }

        if (empty($contentParts) && $hasError) {
            return "I'm processing a lot right now — please send your message again in a few seconds.";
        }

        if (empty($contentParts)) {
            return "I'll create the invoice once the details above are confirmed.";
        }

        $merged = count($contentParts) === 1
            ? ltrim(preg_replace('/^### [^\n]+\n\n/', '', reset($contentParts)))
            : implode("\n\n---\n\n", $contentParts);

        if ($hasPendingSignal) {
            $merged .= "\n\n---\n\n⏳ **Once I have these details, I'll create both records and generate your invoice automatically.**";
        }

        // If invoice failed but client/inventory succeeded, prompt retry
        if ($hasError) {
            $merged .= "\n\n---\n\n⚠️ The invoice step hit a rate limit. Your client and inventory were saved — **please send your message again** to complete the invoice.";
        }

        return $merged;
    }

    private function isErrorReply(string $reply): bool
    {
        return str_contains($reply, 'I encountered an issue')
            || str_contains($reply, 'please try again in a moment')
            || str_contains($reply, 'processing a lot right now');
    }

    private function isInvoiceWaiting(string $reply): bool
    {
        $lower = strtolower(trim($reply));
        return str_contains($lower, "once the") && str_contains($lower, "i'll proceed");
    }

    private function stripPendingSignal(string $reply): string
    {
        return trim(preg_replace('/\n?⏳[^\n]+/', '', $reply));
    }
}
