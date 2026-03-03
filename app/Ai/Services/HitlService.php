<?php

namespace App\Ai\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * HitlService  (Human-in-the-Loop)
 *
 * Implements IBM's governance pattern: a hard checkpoint BEFORE any destructive
 * operation is dispatched to a specialist agent. The agent never executes the
 * delete/destructive action until the user explicitly confirms via a second
 * HTTP request.
 *
 * IBM alignment:
 *   - "Human-in-the-loop: ensure humans can review and approve agent actions"
 *   - "AI agent governance: guardrails for safe and ethical agents"
 *   - "Agentic AI: humans remain in control of high-stakes decisions"
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * TWO-PHASE DISPATCH FLOW
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Phase 1 — Proposal (automatic, ChatOrchestrator::handle):
 *   1. User sends: "Delete the invoice for Acme Corp"
 *   2. HitlService::requiresCheckpoint() returns true
 *   3. Action is stored in Cache with a UUID (pending_id), TTL = 15 min
 *   4. Orchestrator returns: reply = warning message, hitl_pending = true,
 *      pending_id = "<uuid>"
 *   5. Frontend renders the warning + "Confirm" / "Cancel" buttons
 *
 * Phase 2 — Execution (user-triggered, ChatOrchestrator::confirm):
 *   1. User clicks "Confirm"
 *   2. Frontend POSTs to /ai/chat/confirm with pending_id
 *   3. HitlService::consumePendingAction() retrieves and deletes the cached action
 *   4. Orchestrator re-dispatches to the specialist — this time without
 *      re-triggering the HITL check (confirmed actions bypass it)
 *   5. Specialist executes the destructive operation
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHAT COUNTS AS DESTRUCTIVE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Detected by keyword pattern on the raw user message:
 *   delete / remove / destroy / drop / erase / wipe / cancel / void / purge
 *
 * Only applied to guarded intents (not 'business' — profile updates are safe
 * because the agent already confirms before writing).
 *
 * To extend: add patterns to DESTRUCTIVE_PATTERNS, add/remove intents from
 * GUARDED_INTENTS. The check is intentionally conservative — false positives
 * (checkpoint triggered when not needed) are far less damaging than false
 * negatives (destructive action executed without confirmation).
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
     */
    private const DESTRUCTIVE_PATTERNS = [
        '/\b(delete|remove|destroy|drop|erase|wipe|cancel|void|purge)\b/i',
    ];

    /**
     * Intents where destructive-pattern matching is enforced.
     * 'business' is excluded — the agent enforces confirmation in its own prompt.
     * 'narration' is included — deleting ledger heads affects all accounting records.
     */
    private const GUARDED_INTENTS = [
        'invoice',
        'client',
        'inventory',
        'narration',
    ];

    /**
     * Determine whether a HITL checkpoint is required for this turn.
     *
     * Returns true only when ALL of these conditions hold:
     *   1. At least one resolved intent is in GUARDED_INTENTS
     *   2. The raw user message matches a destructive keyword pattern
     */
    public function requiresCheckpoint(string $message, array $intents): bool
    {
        $guardedMatches = array_intersect($intents, self::GUARDED_INTENTS);

        if (empty($guardedMatches)) {
            return false;
        }

        foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                Log::info('[HitlService] Destructive pattern matched', [
                    'intents' => $intents,
                    'pattern' => $pattern,
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
