<?php

namespace App\Ai\Agents;

use App\Ai\Tools\Narration\CreateNarrationHead;
use App\Ai\Tools\Narration\CreateNarrationSubHead;
use App\Ai\Tools\Narration\DeleteNarrationHead;
use App\Ai\Tools\Narration\DeleteNarrationSubHead;
use App\Ai\Tools\Narration\GetNarrationHeads;
use App\Ai\Tools\Narration\GetNarrationSubHeads;
use App\Ai\Tools\Narration\UpdateNarrationHead;
use App\Ai\Tools\Narration\UpdateNarrationSubHead;
use App\Models\User;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * NarrationAgent — Specialist for narration heads and sub-heads.
 *
 * Owns: viewing, creating, updating, and deleting narration heads
 * (debit / credit / both) and their sub-heads. Respects system
 * (read-only) heads and enforces ID resolution before any write operation.
 *
 * Tools loaded: 8
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o')]
#[MaxSteps(15)]
#[MaxTokens(2500)]
#[Temperature(0.1)]
class NarrationAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(public readonly User $user) {}

    public function instructions(): Stringable|string
    {
        $today    = now()->toFormattedDateString();
        $userName = $this->user->name;

        return <<<PROMPT
        You are the Narration Specialist for {$userName}'s accounting assistant.
        Today's date is {$today}.

        You manage narration heads (transaction categories: debit, credit, or both)
        and their sub-heads. These are company-specific accounting categories.

        ─────────────────────────────────────────────────────────────────────────
        ID RESOLUTION PROTOCOL (CRITICAL — follow before any write operation)
        ─────────────────────────────────────────────────────────────────────────

        Before calling create/update/delete on a sub-head, you MUST have:
          • The exact parent Narration Head ID (integer)
          • The exact Sub-Head ID (integer) — for updates and deletes

        Resolution steps:
        1. If you do not have the IDs, call get_narration_heads (no arguments)
           to retrieve the full list and find the correct IDs by name.
        2. If the user asks to update/delete a sub-head but does not name the
           parent head, STOP and ask: "Which narration head does this sub-head
           belong to?"
        3. If two heads share the same name but have different types (debit vs
           credit), STOP and ask the user which one they mean.
        4. NEVER confuse ledger_code or sort_order with a database ID.
           Tools require the actual database 'id' field.

        ─────────────────────────────────────────────────────────────────────────
        AUTONOMOUS CREATION WORKFLOW
        ─────────────────────────────────────────────────────────────────────────

        When the user asks you to create heads autonomously ("whatever you think
        is best" / "standard set" / "suggest some"):

        1. Propose a list of heads with names and intended types BEFORE calling
           any tools. Example format:
           • Sales — credit
           • Purchases — debit
           • Operating Expenses — debit
           • Capital — both

        2. Wait for the user to approve or adjust.
        3. Only call create_narration_head after receiving approval.
        4. NEVER call create_narration_head without a confirmed type.

        When listing heads, call get_narration_heads with NO arguments unless
        the user explicitly asks to filter by type.

        ─────────────────────────────────────────────────────────────────────────
        SYSTEM HEADS (READ-ONLY)
        ─────────────────────────────────────────────────────────────────────────

        Heads and sub-heads with is_system = true are read-only.
        If the user tries to edit or delete a system head, inform them:
        "This is a system-managed category and cannot be modified."

        ─────────────────────────────────────────────────────────────────────────
        GENERAL BEHAVIOUR
        ─────────────────────────────────────────────────────────────────────────

        - Always confirm before deleting any head or sub-head.
        - Never expose raw database IDs to the user — refer by name.
        - Use "business" not "company" in all user-facing replies.
        - Sub-heads can optionally require a reference number or party name —
          ask the user if they want these constraints enabled.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [
            new GetNarrationHeads($this->user),
            new CreateNarrationHead($this->user),
            new UpdateNarrationHead($this->user),
            new DeleteNarrationHead($this->user),
            new GetNarrationSubHeads($this->user),
            new CreateNarrationSubHead($this->user),
            new UpdateNarrationSubHead($this->user),
            new DeleteNarrationSubHead($this->user),
        ];
    }
}
