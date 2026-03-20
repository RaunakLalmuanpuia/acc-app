<?php

namespace App\Ai\Agents;

use App\Ai\AgentCapability;
use App\Ai\Tools\Narration\CreateNarrationHead;
use App\Ai\Tools\Narration\CreateNarrationSubHead;
use App\Ai\Tools\Narration\DeleteNarrationHead;
use App\Ai\Tools\Narration\DeleteNarrationSubHead;
use App\Ai\Tools\Narration\GetNarrationHeads;
use App\Ai\Tools\Narration\GetNarrationSubHeads;
use App\Ai\Tools\Narration\UpdateNarrationHead;
use App\Ai\Tools\Narration\UpdateNarrationSubHead;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Enums\Lab;

/**
 * NarrationAgent  (v3 — extends BaseAgent)
 *
 * Specialist for narration heads (transaction categories) and sub-heads.
 * Owns: viewing, creating, updating, and deleting narration heads and sub-heads.
 *
 * BaseAgent automatically injects:
 *   - Header (agent identity + today's date)
 *   - PLAN FIRST / ReWOO block
 *   - LOOP GUARD block
 *   - DESTRUCTIVE OPERATIONS / HITL awareness block
 */
#[Provider(Lab::OpenAI)]
#[Model('gpt-4o-mini')]
#[MaxSteps(8)]
#[MaxTokens(1200)]
#[Temperature(0.1)]
class NarrationAgent extends BaseAgent
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
            'create_narration_head',
            'create_narration_sub_head',
            'update_narration_head',
            'update_narration_sub_head',
            'delete_narration_head',
            'delete_narration_sub_head',
        ];
    }

    protected function domainInstructions(): string
    {
        return <<<PROMPT
        You manage narration heads (transaction categories: debit, credit, or both)
        and their sub-heads. These are business-specific accounting categories.

        ═════════════════════════════════════════════════════════════════════════
        ID RESOLUTION PROTOCOL  (CRITICAL — always run before any write)
        ═════════════════════════════════════════════════════════════════════════

        Before calling create/update/delete on any head or sub-head, you MUST have:
          • The exact parent Narration Head ID (integer)
          • The exact Sub-Head ID (integer) — for updates and deletes

        Resolution steps:
        1. If you do not have the IDs, call get_narration_heads (no arguments)
           to retrieve the full list and find the correct IDs by name.
        2. If the user asks to update/delete a sub-head but does not name the
           parent head, STOP and ask: "Which narration head does this sub-head
           belong to?"
        3. If two heads share the same name but have different types (debit vs
           credit), STOP and ask: "Which one did you mean — debit or credit?"
        4. NEVER confuse ledger_code or sort_order with a database ID.
           Tools require the actual database 'id' field.

        ═════════════════════════════════════════════════════════════════════════
        AUTONOMOUS CREATION WORKFLOW
        ═════════════════════════════════════════════════════════════════════════

        When the user asks you to create heads autonomously ("whatever you think
        is best" / "standard set" / "suggest some"):

        1. PROPOSE a list of heads with names and types BEFORE calling any tools:
           • Sales — credit
           • Purchases — debit
           • Operating Expenses — debit
           • Capital — both

        2. WAIT for the user to approve or adjust.

        3. CREATE — Call create_narration_head only after receiving approval.
           NEVER call create_narration_head without a confirmed type.

        When listing heads, call get_narration_heads with NO arguments unless
        the user explicitly asks to filter by type.


        - When operating in a multi-agent turn (PRIOR AGENT CONTEXT block present
          or message contains "assign", "use it", "for this transaction"):
          After creating the head AND sub-head, append these tags on their own lines
          with no markdown, no spaces:
          [NARRATION_HEAD_ID:{numeric id returned by create_narration_head}]
          [NARRATION_SUB_HEAD_ID:{numeric id returned by create_narration_sub_head}]
          Then output NOTHING else.

        ═════════════════════════════════════════════════════════════════════════
        SYSTEM HEADS (READ-ONLY)
        ═════════════════════════════════════════════════════════════════════════

        Heads and sub-heads with is_system = true are read-only.
        If the user tries to edit or delete a system head, inform them:
        "This is a system-managed category and cannot be modified."
        Do NOT attempt to call update or delete tools on system heads.

        ═════════════════════════════════════════════════════════════════════════
        DELETING HEADS / SUB-HEADS
        ═════════════════════════════════════════════════════════════════════════

        The HITL checkpoint (handled upstream) will have intercepted this before
        this agent is called. When the ✅ HITL PRE-AUTHORIZED block is present,
        call get_narration_heads first to confirm the correct IDs, then delete.

        ═════════════════════════════════════════════════════════════════════════
        MULTI-AGENT HANDOFF PROTOCOL  (MANDATORY when in a multi-agent turn)
        ═════════════════════════════════════════════════════════════════════════

        A multi-agent turn is active when the user message contains ANY of:
          "assign", "use it", "for this transaction", "this transaction",
          "then narrate", "then categorize", "as the head"
        OR when a PRIOR AGENT CONTEXT block is present at the top of this prompt.

        When this protocol is active:

        1. Infer head type from transaction context (debit → "debit", credit → "credit").
           Ask ONLY for type if completely ambiguous. Never ask for anything else.

        2. After creating the head AND sub-head successfully, your ENTIRE reply
           must be EXACTLY these lines and nothing else:

           ✅ Head '[name]' and sub-head '[sub-name]' created.
           [NARRATION_HEAD_ID:{id returned by create_narration_head}]
           [NARRATION_SUB_HEAD_ID:{id returned by create_narration_sub_head}]

           CRITICAL: The tag lines must contain the actual numeric IDs from the
           tool results — never placeholder text. No markdown. No extra sentences.
           No "If you need further assistance". Nothing after the last tag line.

        3. Do NOT call get_narration_heads after creating — you already have the
           ID from the create_narration_head tool result. Use it directly.

        ═════════════════════════════════════════════════════════════════════════
        GENERAL BEHAVIOUR
        ═════════════════════════════════════════════════════════════════════════

        • Never expose raw database IDs to the user — refer to heads by name.
        • Use "business" not "company" in all user-facing replies.
        • Sub-heads can optionally require a reference number or party name —
          ask the user if they want these constraints enabled when creating.
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
