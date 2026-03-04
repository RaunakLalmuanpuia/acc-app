<?php

namespace App\Ai\Services;

use App\Ai\AgentRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HitlService  (v3 — AgentRegistry-driven GUARDED_INTENTS)
 *
 * Implements IBM's governance pattern: a hard checkpoint BEFORE any destructive
 * operation is dispatched to a specialist agent.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGE FROM v2
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v2 hardcoded:
 *   private const GUARDED_INTENTS = ['invoice', 'client', 'inventory', 'narration'];
 *
 * This meant adding a new destructive agent (e.g. PayrollAgent) required
 * manually editing this constant — a maintenance trap that would silently
 * fail (no HITL checkpoint) if the developer forgot.
 *
 * v3 derives the guarded intents dynamically from AgentRegistry:
 *   AgentRegistry::destructiveIntents()
 *   → returns all intents whose agent declares AgentCapability::DESTRUCTIVE
 *   → called once at checkpoint evaluation time (no caching needed — it's a
 *     static array_filter, sub-microsecond)
 *
 * Adding a new destructive agent now requires ZERO changes to this file.
 * The agent simply declares AgentCapability::DESTRUCTIVE in getCapabilities()
 * and it is automatically guarded.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * IBM ALIGNMENT
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * IBM HITL governance (ibm.com/think/tutorials/human-in-the-loop-ai-agent):
 *   "Human-in-the-loop: ensure humans can review and approve agent actions
 *    before irreversible operations are executed."
 *
 * IBM on conservative checkpointing:
 *   "False positives (checkpoint triggered unnecessarily) are far less damaging
 *    than false negatives (destructive action without confirmation)."
 */
class HitlService
{
    /** Pending actions expire after this many minutes. */
    private const TTL_MINUTES = 15;

    /** Cache key prefix for pending actions. */
    private const CACHE_PREFIX = 'hitl:pending:';

    /**
     * Regex patterns that signal a potentially destructive operation.
     * Evaluated against the raw user message (case-insensitive).
     *
     * To extend: add new patterns here. The intent guard is handled separately
     * via AgentRegistry::destructiveIntents() — no other changes needed.
     */
    private const DESTRUCTIVE_PATTERNS = [
        '/\b(delete|remove|destroy|drop|erase|wipe|cancel|void|purge)\b/i',
    ];

    /**
     * Determine whether a HITL checkpoint is required for this turn.
     *
     * Returns true only when ALL of these conditions hold:
     *   1. At least one resolved intent belongs to a DESTRUCTIVE agent
     *      (derived dynamically from AgentRegistry — no hardcoded list)
     *   2. The raw user message matches a destructive keyword pattern
     *
     * @param  string   $message  Raw user message
     * @param  string[] $intents  Resolved domain intents
     */
    public function requiresCheckpoint(string $message, array $intents): bool
    {
        // Derive guarded intents dynamically — automatically includes any new
        // agent that declares AgentCapability::DESTRUCTIVE
        $guardedIntents = AgentRegistry::destructiveIntents();
        $guardedMatches = array_intersect($intents, $guardedIntents);

        if (empty($guardedMatches)) {
            return false;
        }

        foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                Log::info('[HitlService] Destructive pattern matched', [
                    'intents'        => $intents,
                    'guarded_intents' => $guardedIntents,
                    'pattern'        => $pattern,
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Persist the pending action in cache and return a UUID to the frontend.
     *
     * Note: file attachments are NOT serialised — they are SDK objects that
     * cannot be stored in the cache. If the user confirms, they will need to
     * re-attach files. This is intentional and displayed in the checkpoint message.
     *
     * @param  string      $userId
     * @param  string      $message         Raw user message
     * @param  string[]    $intents         Resolved domain intents
     * @param  string|null $conversationId  Existing conversation ID, if any
     * @return string                       UUID to send to the frontend as pending_id
     */
    public function storePendingAction(
        string  $userId,
        string  $message,
        array   $intents,
        ?string $conversationId,
    ): string {
        $pendingId = Str::uuid()->toString();

        Cache::put(
            key:   self::CACHE_PREFIX . $pendingId,
            value: [
                'user_id'         => $userId,
                'message'         => $message,
                'intents'         => $intents,
                'conversation_id' => $conversationId,
                'created_at'      => now()->toIso8601String(),
            ],
            ttl:   now()->addMinutes(self::TTL_MINUTES),
        );

        Log::info('[HitlService] Pending action stored', [
            'pending_id' => $pendingId,
            'user_id'    => $userId,
            'intents'    => $intents,
        ]);

        return $pendingId;
    }

    /**
     * Retrieve a pending action without consuming (deleting) it.
     * Use for read-only checks (e.g. "is this still valid?").
     */
    public function retrievePendingAction(string $pendingId): ?array
    {
        return Cache::get(self::CACHE_PREFIX . $pendingId);
    }

    /**
     * Retrieve and immediately delete a pending action from the cache.
     *
     * This is the "consume" pattern — once retrieved for execution it cannot
     * be replayed. Returns null if the action has expired or was already consumed.
     */
    public function consumePendingAction(string $pendingId): ?array
    {
        $action = $this->retrievePendingAction($pendingId);

        if ($action !== null) {
            Cache::forget(self::CACHE_PREFIX . $pendingId);

            Log::info('[HitlService] Pending action consumed', [
                'pending_id' => $pendingId,
                'user_id'    => $action['user_id'],
                'intents'    => $action['intents'],
            ]);
        }

        return $action;
    }

    /**
     * Build the human-readable checkpoint warning shown to the user.
     *
     * Returned as the 'reply' from the orchestrator when HITL is triggered.
     * The frontend should render "Confirm" / "Cancel" buttons alongside this.
     */
    public function buildCheckpointMessage(string $message, array $intents): string
    {
        $domainList = implode(', ', array_map('ucfirst', $intents));

        return <<<MD
        ⚠️ **Confirmation Required — Destructive Operation**

        You are about to perform an action that **cannot be undone**.

        **Affected domain(s):** {$domainList}
        **Your request:** _{$message}_

        If you had file attachments, please re-attach them when confirming.

        Are you sure you want to proceed?
        MD;
    }
}
