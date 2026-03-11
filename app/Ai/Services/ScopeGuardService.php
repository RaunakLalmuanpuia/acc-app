<?php

namespace App\Ai\Services;

use Illuminate\Support\Facades\Log;

/**
 * ScopeGuardService
 *
 * A zero-cost pre-dispatch gate that enforces the accounting-only scope
 * of the AI assistant BEFORE any RouterAgent or specialist agent is invoked.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * LAYERED DEFENCE STRATEGY
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Layer 1 — OUT_OF_SCOPE_PATTERNS  (hard block, zero AI cost)
 *   Keyword patterns that are unambiguously outside accounting. If matched,
 *   the request is rejected immediately with a static response. No RouterAgent
 *   call. No token spend. No ambiguity.
 *
 * Layer 2 — JAILBREAK_PATTERNS  (security block, zero AI cost)
 *   Prompt injection / persona override attempts. Caught here as a scope
 *   violation because their intent is to bypass the accounting scope.
 *
 * Layer 3 — RouterAgent returns 'unknown'  (AI-cost gate)
 *   For ambiguous messages that pass the keyword check but are still off-topic,
 *   the router classifies them as unknown → orchestrator returns static reply.
 *
 * Layer 4 — BaseAgent system prompt  (final AI-side enforcement)
 *   Every specialist agent's instructions declare the accounting-only scope.
 *   Even if layers 1–3 are somehow bypassed, the agent refuses to respond
 *   to non-accounting content.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PHILOSOPHY
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * We do NOT try to enumerate every off-topic subject. That is an arms race
 * we will lose. Instead:
 *   - Block the most obvious abuse patterns cheaply here.
 *   - Trust the RouterAgent to handle the long tail of ambiguous inputs.
 *   - Trust the BaseAgent system prompt as the last line of defence.
 *
 * The response messages are intentionally clear and non-apologetic.
 * We do not say "I'm sorry I can't help with that." We say what we ARE for.
 */
class ScopeGuardService
{
    /**
     * Patterns that are unambiguously outside accounting scope.
     * Evaluated case-insensitively against the full user message.
     *
     * Design rules:
     *  - Each pattern must be specific enough to avoid false positives.
     *    "write" alone is too broad (users say "write an invoice"). Use
     *    "write (me )?(a |an )?(poem|story|essay|code|script|song)" instead.
     *  - Prefer word boundaries (\b) to avoid partial matches.
     *  - Group related patterns into one regex for efficiency.
     */
    private const OUT_OF_SCOPE_PATTERNS = [

        // General-purpose chatbot attempts
        '/\b(what (is|are) your (capabilities|features|functions|limits))\b/i',
        '/\b(who (are|created|made|built|trained) you)\b/i',
        '/\b(what (llm|model|ai|gpt|claude|gemini) (are you|do you use|powers you))\b/i',
        '/\bpretend (you are|to be|you\'re)\b/i',
        '/\byour (system |base |original )?prompt\b/i',
        '/\brepeat (everything|all|your instructions|what (i|you) (said|wrote))\b/i',

        // Creative / generative content
        '/\bwrite (me )?(a |an )?(poem|story|essay|novel|song|lyrics|haiku|limerick|script|screenplay)/i',
        '/\b(generate|create|make) (a |an )?(image|picture|photo|video|meme|gif)\b/i',
        '/\b(draw|illustrate|design) (me |us )?(a |an )?\b/i',

        // Coding / technical assistance
        '/\b(write|generate|create|give me)(\s+\w+){0,4}\s*(code|script|function|class|program|algorithm|snippet|regex)\b/i',
        '/\b(debug|fix|refactor|optimise|optimize) (my |this |the )?(code|script|function|class|bug)\b/i',
        '/\bhow (do i|to) (install|configure|set up|deploy|run)\b/i',

        // Medical / health
        '/\b(symptoms?|diagnosis|diagnose|treatment|medicine|medication|dosage|prescription)\b/i',
        '/\b(am i|are you) (sick|ill|pregnant|dying|having a (heart attack|stroke))\b/i',

        // Legal advice (non-tax)
        '/\b(sue|lawsuit|legal advice|attorney|lawyer|litigation|court|tribunal)\b/i',

        // Personal / relationship advice
        '/\b(relationship advice|dating|marriage|divorce|breakup|my (partner|boyfriend|girlfriend|husband|wife))\b/i',

        // Travel / geography
        '/\b(best (flights?|hotels?|restaurants?|places to (visit|eat|stay)))\b/i',
        '/\b(travel (to|from|between|itinerary|guide|tips))\b/i',

        // Political / religious
        '/\b(who (should i|to) vote for|political party|election|god|religion|bible|quran|prayer)\b/i',

        // Entertainment / trivia
        '/\b(movie|film|tv show|series|episode|celebrity|actor|actress|singer|band|album|song)\b.*recommend/i',
        '/\b(sports?|football|cricket|basketball|tennis) (score|result|match|team|player)\b/i',

        // Explicit content
        '/\b(sex|porn|nude|naked|explicit|adult content|18\+|nsfw)\b/i',

        // General Knowledge
        '/\bwhat (is|are|was|were) the (capital|population|currency|language|flag|president|prime minister|largest|smallest|tallest|oldest|richest) of\b/i',
        '/\bwho (invented|discovered|painted|wrote|composed|founded|built|won) (the |a |an )?\b/i',
        '/\bwhen (was|were|did) .{3,40} (born|die|invented|discovered|founded|built|won)\b/i',
        '/\b(what|where) (is|are) (the )?(weather|temperature|forecast)\b/i',
        '/\btell me (a )?joke\b/i',
        '/\b(recommend|suggest) (a |an )?(movie|book|song|restaurant|hotel|place)\b/i',
    ];

    /**
     * Jailbreak / prompt injection patterns.
     * Treated as scope violations — they attempt to bypass the accounting scope.
     *
     * These are intentionally separate from OUT_OF_SCOPE_PATTERNS so they
     * can be logged with a different severity level (security vs. off-topic).
     */
    private const JAILBREAK_PATTERNS = [
        '/ignore\s+(all\s+)?(previous|prior|above|system)\s+(instructions?|prompts?|rules?)/i',
        '/you\s+are\s+now\s+(a\s+)?(?!the\s+\w+\s+specialist)/i',
        '/forget\s+(everything|all|your\s+instructions?|your\s+rules?)/i',
        '/new\s+(instructions?|rules?|persona|role|identity)\s*:/i',
        '/act\s+as\s+(a\s+)?(different|another|new|unrestricted|jailbroken|unfiltered)\b/i',
        '/do\s+anything\s+now|DAN\s+mode|developer\s+mode|jailbreak/i',
        '/<\|(?:im_start|im_end|endoftext)\|>/i',   // OpenAI token injection
        '/\[\s*INST\s*\]|\[\s*\/INST\s*\]/i',        // LLaMA role injection
        '/system\s*:\s*.{0,200}(you are|ignore|forget)/i',
    ];

    /**
     * The static response returned for out-of-scope messages.
     * Deliberately does not apologise — sets clear expectations.
     */
    private const SCOPE_RESPONSE =
        "I'm an **accounting assistant** — I can only help with:\n\n"
        . "• 🧾 **Invoices** — create, confirm, view, update, delete, generate PDFs\n"
        . "• 👤 **Clients** — add, update, search client records\n"
        . "• 📦 **Inventory** — manage products and services\n"
        . "• 📒 **Narration Heads** — transaction categories and ledger heads\n"
        . "• 🏢 **Business Profile** — company details, GST, PAN, bank info\n"
        . "• 🏦 **Bank Transactions** — review, categorise, reconcile transactions\n\n"
        . "Please ask me something related to your accounting.";

    /**
     * The static response returned for jailbreak attempts.
     * Firm and unambiguous — does not engage with the premise.
     */
    private const JAILBREAK_RESPONSE =
        "This assistant is scoped to accounting operations only and cannot "
        . "change its role or instructions. Please ask me about invoices, "
        . "clients, inventory, narration heads, business profile, or bank transactions.";

    /**
     * Evaluate the message against scope and jailbreak patterns.
     *
     * Returns a ScopeGuardResult indicating whether to proceed or block,
     * and if blocking, what response to return to the user.
     */
    public function evaluate(string $message, string $userId): ScopeGuardResult
    {
        // Check jailbreak first — higher severity
        foreach (self::JAILBREAK_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                Log::warning('[ScopeGuard] Jailbreak attempt detected', [
                    'user_id' => $userId,
                    'pattern' => $pattern,
                    'preview' => mb_substr($message, 0, 100),
                ]);

                return ScopeGuardResult::blocked(self::JAILBREAK_RESPONSE, 'jailbreak');
            }
        }

        // Check out-of-scope patterns
        foreach (self::OUT_OF_SCOPE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                Log::info('[ScopeGuard] Out-of-scope message blocked', [
                    'user_id' => $userId,
                    'pattern' => $pattern,
                    'preview' => mb_substr($message, 0, 100),
                ]);

                return ScopeGuardResult::blocked(self::SCOPE_RESPONSE, 'out_of_scope');
            }
        }

        return ScopeGuardResult::allowed();
    }
}
