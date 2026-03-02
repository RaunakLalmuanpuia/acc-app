<?php

namespace App\Ai\Services;

use Illuminate\Support\Str;

/**
 * ConversationIdService
 *
 * Manages conversation ID scoping for the multi-agent architecture.
 *
 * The Problem:
 *   When a single user turn triggers multiple specialist agents (e.g. "create a
 *   client and make an invoice"), each agent needs its own conversation history.
 *   If all agents share the same ID, their histories bleed into each other.
 *
 * The Solution:
 *   - The frontend sees and sends only ONE base conversation ID (e.g. "abc-123").
 *   - For multi-intent dispatches, each specialist gets a scoped ID:
 *       "abc-123:invoice", "abc-123:client"
 *   - On the next turn the frontend sends "abc-123" again; the orchestrator
 *     re-derives the scoped IDs and each specialist resumes its own thread.
 *   - Single-intent turns skip scoping entirely for simplicity.
 */
class ConversationIdService
{
    /**
     * Generate a new base conversation ID.
     * Called when the frontend sends a null conversationId (new conversation).
     */
    public function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Derive the conversation ID a specialist agent should use.
     *
     * @param  string|null $baseId       The base ID from the frontend.
     * @param  string      $intent       The domain intent (e.g. 'invoice').
     * @param  bool        $multiIntent  Whether multiple intents are active this turn.
     * @return string|null               null = new conversation; string = resume existing.
     */
    public function scopedId(?string $baseId, string $intent, bool $multiIntent): ?string
    {
        if ($baseId === null) {
            return null; // SDK will assign a fresh ID
        }

        return $multiIntent
            ? "{$baseId}:{$intent}"
            : $baseId;
    }

    /**
     * Extract the base conversation ID from a potentially scoped ID.
     * e.g. "abc-123:invoice" → "abc-123"
     *
     * Used when returning the conversation ID to the frontend so it always
     * receives the clean base ID regardless of internal scoping.
     */
    public function baseId(string $scopedId): string
    {
        return explode(':', $scopedId, 2)[0];
    }
}
