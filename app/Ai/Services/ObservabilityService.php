<?php

namespace App\Ai\Services;

use Illuminate\Support\Facades\Log;

/**
 * ObservabilityService
 *
 * Implements IBM's AgentOps pattern: structured, per-agent telemetry for every
 * chat turn. Tracks latency, token spend, and failure rates so you can detect
 * degradation, control costs, and feed external APM tooling.
 *
 * IBM alignment:
 *   - "AgentOps — tracking per-agent failure rates, latency, token spend"
 *   - "AI agent governance: processes and guardrails for safe and ethical agents"
 *   - "AI agent observability: monitor agent behavior and performance"
 *
 * Design:
 *   - recordAgentCall()  — called once per specialist dispatch (inside dispatcher)
 *   - recordTurnSummary() — called once per chat turn (inside orchestrator)
 *   - Metrics are structured JSON logs: pipe to Datadog / CloudWatch / Grafana.
 *   - State is reset after each turn summary — the service IS a singleton (Laravel
 *     container binds it as shared), but turnMetrics[] is cleared after summary.
 *
 * Extending this:
 *   - Swap Log::info for a dedicated metrics driver (StatsD, Prometheus, etc.)
 *   - Add a DB write to an `agent_metrics` table for historical dashboards.
 *   - Add cost estimation: input_tokens * model_cost + output_tokens * model_cost.
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
     * Per-turn metrics buffer — one entry per agent call in the current turn.
     * @var array<int, array>
     */
    private array $turnMetrics = [];

    /**
     * Record a single specialist agent call.
     *
     * Call this immediately after prompt() returns (or in the catch block
     * on failure). Pass usage data from the SDK response where available.
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
    ): void {
        $estimatedCostUsd = $this->estimateCost($model, $inputTokens, $outputTokens);

        $metric = [
            'intent'             => $intent,
            'user_id'            => $userId,
            'conversation_id'    => $conversationId,
            'model'              => $model,
            'latency_ms'         => $latencyMs,
            'input_tokens'       => $inputTokens,
            'output_tokens'      => $outputTokens,
            'total_tokens'       => ($inputTokens ?? 0) + ($outputTokens ?? 0),
            'estimated_cost_usd' => $estimatedCostUsd,
            'success'            => $success,
            'error'              => $errorMessage,
            'timestamp'          => now()->toIso8601String(),
        ];

        $this->turnMetrics[] = $metric;

        $logLevel = $success ? 'info' : 'error';
        Log::{$logLevel}('[AgentOps] Agent call recorded', $metric);
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
        $totalTokens     = array_sum(array_column($this->turnMetrics, 'total_tokens'));
        $totalCostUsd    = array_sum(array_column($this->turnMetrics, 'estimated_cost_usd'));
        $failedAgents    = array_filter($this->turnMetrics, fn ($m): bool => !$m['success']);
        $agentLatencies  = array_column($this->turnMetrics, 'latency_ms');

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
            'per_agent'           => $this->turnMetrics,
        ]);

        // Reset buffer — ready for the next turn
        $this->turnMetrics = [];
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
}
