<?php

namespace App\Ai\Services;

/**
 * ResponseMergerService
 *
 * Merges the responses from one or more specialist agents into a single
 * coherent reply for the user.
 *
 * Rules:
 *  - Single intent → return the response as-is (no decoration).
 *  - Multi-intent  → combine with labelled emoji section headers.
 *  - Empty / unknown only → return a helpful static fallback at zero AI cost.
 */
class ResponseMergerService
{
    /**
     * Fallback reply when no valid domain intents were resolved.
     * Returned verbatim — no AI agent is invoked, meaning zero gpt-4o cost
     * for greetings, thank-yous, and out-of-scope messages.
     */
    private const UNKNOWN_RESPONSE =
        "I'm your accounting assistant. I can help you with:\n\n"
        . "• 🧾 **Invoices** — create, confirm, view, or generate PDFs\n"
        . "• 👤 **Clients** — add, update, or look up client records\n"
        . "• 📦 **Inventory** — manage products and services\n"
        . "• 📒 **Narration Heads** — set up transaction categories\n"
        . "• 🏢 **Business Profile** — view or update your business details\n"
        . "• 🏦 **Bank Transactions** — review, categorise, reconcile transactions\n\n"
        . "How can I help you today?";

    /** Emoji labels per domain for multi-intent merged replies. */
    private const SECTION_LABELS = [
        'invoice'   => '🧾 Invoice',
        'client'    => '👤 Client',
        'inventory' => '📦 Inventory',
        'narration' => '📒 Narration Heads',
        'business'  => '🏢 Business Profile',
        'bank_transaction' => '🏦 Bank Transactions',
    ];

    /**
     * Produce the final reply string from a map of intent → response.
     *
     * @param  array<string, string> $responses  Keyed by intent.
     * @return string
     */
    public function merge(array $responses): string
    {
        if (empty($responses)) {
            return self::UNKNOWN_RESPONSE;
        }

        // Single specialist → return as-is, clean and undecorated
        if (count($responses) === 1) {
            return reset($responses);
        }

        // Multiple specialists → combine with clear section headers
        return $this->mergeMultiple($responses);
    }

    /**
     * Return the static unknown/fallback response.
     * Called by the orchestrator when no valid domain intents are found.
     */
    public function unknownResponse(): string
    {
        return self::UNKNOWN_RESPONSE;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Combine multiple specialist responses into one readable reply.
     *
     * Each section is labelled with a markdown heading and separated by a
     * horizontal rule so the user can clearly see which part of their request
     * each response addresses.
     */
    private function mergeMultiple(array $responses): string
    {
        $parts = [];

        foreach ($responses as $intent => $reply) {
            $label   = self::SECTION_LABELS[$intent] ?? ucfirst($intent);
            $parts[] = "### {$label}\n\n{$reply}";
        }

        return implode("\n\n---\n\n", $parts);
    }
}
