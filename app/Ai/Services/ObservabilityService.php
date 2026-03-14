<?php

namespace App\Ai\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * ObservabilityService  (v3 — IBM AgentOps evaluation alignment)
 *
 * Implements IBM's AgentOps pattern: structured, per-agent telemetry for every
 * chat turn. Tracks latency, token spend, failure rates, and — new in v3 —
 * intent outcome signals so you can detect when an agent asked clarifying
 * questions instead of completing the user's request.
 *
 * IBM alignment:
 *   - "AgentOps — tracking per-agent failure rates, latency, token spend"
 *   - "AI agent evaluation: did the agent complete the user's intent correctly?"
 *   - "AI agent observability: monitor agent behavior and performance"
 * Source: ibm.com/think/topics/agentops, ibm.com/think/topics/ai-agent-evaluation
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CHANGES FROM v2 — IBM AgentOps evaluation layer (Gap 3 completion)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * v2 recorded latency, tokens, and cost (telemetry) but had no signal for
 * whether the agent COMPLETED the user's intent or merely asked a question.
 *
 * IBM distinguishes telemetry from evaluation:
 *   Telemetry  → "how fast / how much did it cost?" (v2 had this)
 *   Evaluation → "did it actually do what the user asked?" (v3 adds this)
 *
 * NEW: recordAgentCall() now accepts an $outcomeSignal parameter:
 *   'completed'   → agent called a write tool (create, confirm, update, delete)
 *   'clarifying'  → agent returned a question without calling any write tool
 *   'partial'     → agent called at least one write tool but also asked questions
 *   'error'       → agent threw an exception (maps to success: false)
 *   null          → caller did not provide a signal (backwards-compatible)
 *
 * NEW: recordTurnSummary() aggregates and logs outcome distribution per turn,
 *   enabling dashboard queries like "% of invoice turns that completed vs asked".
 *
 * HOW TO POPULATE $outcomeSignal in AgentDispatcherService:
 *   The Laravel AI SDK exposes the tools called during prompt() via the response
 *   object or the conversation message row. A simple heuristic suffices:
 *
 *   $writeTools = ['create_invoice_draft', 'confirm_invoice', 'update_invoice',
 *                  'delete_invoice', 'create_client', 'update_client', ...];
 *   $toolsUsed  = $response->toolsUsed ?? [];  // SDK-provided list
 *   $didWrite   = !empty(array_intersect($toolsUsed, $writeTools));
 *   $didAsk     = str_contains((string) $response, '?');
 *   $outcome    = match(true) {
 *       $didWrite && !$didAsk => 'completed',
 *       $didWrite && $didAsk  => 'partial',
 *       default               => 'clarifying',
 *   };
 *
 * If $response->toolsUsed is not available in your SDK version, pass null and
 * upgrade the signal once the SDK exposes it — the field is nullable and safe.
 */
class ObservabilityService
{
    /**
     * Approximate cost per 1K tokens for cost estimation (USD).
     * Update when OpenAI pricing changes.
     */
    private const MODEL_COSTS = [
        'gpt-4o'      => ['input' => 0.005,   'output' => 0.015],
        'gpt-4o-mini' => ['input' => 0.00015, 'output' => 0.0006],
    ];

    /**
     * Valid outcome signal values.
     * 'completed' is the only "healthy" state — all others warrant monitoring.
     */
    private const OUTCOME_SIGNALS = ['completed', 'clarifying', 'partial', 'error'];

    /**
     * Per-turn metrics buffer — one entry per agent call in the current turn.
     * @var array<int, array>
     */
    private array $turnMetrics = [];


    private ?string $turnId = null;

    /**
     * Record a single specialist agent call.
     *
     * Call this immediately after prompt() returns (or in the catch block
     * on failure). Pass usage data from the SDK response where available.
     *
     * @param  string|null $outcomeSignal  IBM AgentOps evaluation signal (v3):
     *                                    'completed' | 'clarifying' | 'partial' | 'error' | null
     *                                    Pass null if not yet available in your SDK version.
     */
    public function recordAgentCall(
        string  $intent,
        string  $userId,
        ?string $conversationId,
        string  $model,
        int     $latencyMs,
        ?int    $inputTokens   = null,
        ?int    $outputTokens  = null,
        bool    $success       = true,
        ?string $errorMessage  = null,
        ?string $outcomeSignal = null,    // v3: IBM AgentOps evaluation layer
    ): void {
        // Normalise outcome: failed calls are always 'error' regardless of what was passed
        $outcome = !$success
            ? 'error'
            : (in_array($outcomeSignal, self::OUTCOME_SIGNALS, true) ? $outcomeSignal : null);

        $estimatedCostUsd = $this->estimateCost($model, $inputTokens, $outputTokens);

        $metric = [
            'intent'             => $intent,
            'user_id'            => $userId,
            'conversation_id'    => $conversationId,
            'turn_id'            => $this->turnId,
            'model'              => $model,
            'latency_ms'         => $latencyMs,
            'input_tokens'       => $inputTokens,
            'output_tokens'      => $outputTokens,
            'total_tokens'       => ($inputTokens ?? 0) + ($outputTokens ?? 0),
            'estimated_cost_usd' => $estimatedCostUsd,
            'success'            => $success,
            'outcome'            => $outcome,    // v3 evaluation signal
            'error'              => $errorMessage,
            'created_at'         => now()->toIso8601String(),
        ];

        DB::table('agent_metrics')->insert($metric);

        $this->turnMetrics[] = $metric;

        $logLevel = $success ? 'info' : 'error';
        Log::{$logLevel}('[AgentOps] Agent call recorded', $metric);

        // v3: emit a dedicated evaluation log when the agent clarified instead
        // of completing — this is the key signal for prompt quality monitoring.
        if ($outcome === 'clarifying') {
            Log::warning('[AgentOps] Agent asked a clarifying question instead of completing', [
                'intent'          => $intent,
                'user_id'         => $userId,
                'conversation_id' => $conversationId,
                'action'          => 'Review agent instructions — ReWOO plan-first workflow may not be triggering lookups.',
            ]);
        }
    }

    /**
     * Record the summary for the entire chat turn.
     *
     * Call this at the end of ChatOrchestrator::handle(), after all agents
     * have completed. Resets the internal buffer for the next turn.
     */
    public function recordTurnSummary(
        string  $userId,
        ?string $conversationId,
        array   $intents,
        int     $totalLatencyMs,
    ): void {
        $totalTokens    = array_sum(array_column($this->turnMetrics, 'total_tokens'));
        $totalCostUsd   = array_sum(array_column($this->turnMetrics, 'estimated_cost_usd'));
        $failedAgents   = array_filter($this->turnMetrics, fn ($m): bool => !$m['success']);
        $agentLatencies = array_column($this->turnMetrics, 'latency_ms');

        $outcomesRaw = array_column($this->turnMetrics, 'outcome');
        $hasAnySignal = count(array_filter($outcomesRaw, fn($o) => $o !== null)) > 0;

        $outcomes = array_count_values(array_filter($outcomesRaw));
        $completionRate = (!$hasAnySignal)
            ? null   // ← SDK doesn't expose toolsUsed yet, don't report 0%
            : (count($this->turnMetrics) > 0
                ? round(($outcomes['completed'] ?? 0) / count($this->turnMetrics) * 100, 1)
                : null);

        Log::info('[AgentOps] Turn summary', [
            'user_id'             => $userId,
            'conversation_id'     => $conversationId,
            'intents'             => $intents,
            'agent_count'         => count($intents),
            'total_latency_ms'    => $totalLatencyMs,
            'agent_latencies_ms'  => $agentLatencies,
            'total_tokens'        => $totalTokens,
            'total_cost_usd'      => round($totalCostUsd, 6),
            'failed_agent_count'  => count($failedAgents),
            'all_succeeded'       => count($failedAgents) === 0,
            // v3 evaluation fields
            'outcome_distribution' => $outcomes,
            'completion_rate_pct'  => $completionRate,
            'per_agent'            => $this->turnMetrics,
        ]);


        DB::table('agent_turn_metrics')->insert([
            'turn_id' => $this->turnId,
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'agent_count' => count($intents),
            'total_latency_ms' => $totalLatencyMs,
            'total_tokens' => $totalTokens,
            'total_cost_usd' => $totalCostUsd,
            'failed_agent_count' => count($failedAgents),
            'all_succeeded' => count($failedAgents) === 0,
            'completion_rate_pct' => $completionRate,
            'outcome_distribution' => json_encode($outcomes),
            'intents' => json_encode($intents),
            'created_at' => now(),
        ]);
        // Reset buffer — ready for the next turn
        $this->turnMetrics = [];
        $this->turnId = null;
    }

    /**
     * Return current buffered metrics without resetting.
     * Useful for testing and inline diagnostics.
     */
    public function getTurnMetrics(): array
    {
        return $this->turnMetrics;
    }

    // ── Private ────────────────────────────────────────────────────────────────

    /**
     * Estimate USD cost for an agent call based on model pricing.
     * Returns 0.0 if tokens or model costs are unknown.
     */
    private function estimateCost(
        string $model,
        ?int   $inputTokens,
        ?int   $outputTokens,
    ): float {
        $rates = self::MODEL_COSTS[$model] ?? null;

        if (!$rates || $inputTokens === null || $outputTokens === null) {
            return 0.0;
        }

        return round(
            ($inputTokens  / 1000 * $rates['input']) +
            ($outputTokens / 1000 * $rates['output']),
            6
        );
    }
    public function setTurnId(string $turnId): void
    {
        $this->turnId = $turnId;
    }
}
