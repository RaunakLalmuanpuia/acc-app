<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class AiAnalyticsService
{
    public function dashboard()
    {
        return [
            'intentDistribution' => $this->intentDistribution(),
            'outcomeDistribution' => $this->outcomeDistribution(),
            'latencyByAgent' => $this->latencyByAgent(),
            'dailyCost' => $this->dailyCost(),
            'agentComplexity' => $this->agentComplexity(),
            'tokenUsage' => $this->tokenUsage(),
            'agentFailures' => $this->agentFailures(),
        ];
    }

    public function intentDistribution()
    {
        return DB::table('agent_metrics')
            ->select('intent', DB::raw('COUNT(*) as total'))
            ->groupBy('intent')
            ->orderByDesc('total')
            ->get();
    }

    public function outcomeDistribution()
    {
        return DB::table('agent_metrics')
            ->select('outcome', DB::raw('COUNT(*) as total'))
            ->groupBy('outcome')
            ->get();
    }

    public function latencyByAgent()
    {
        return DB::table('agent_metrics')
            ->select('intent', DB::raw('AVG(latency_ms) as avg_latency'))
            ->groupBy('intent')
            ->orderByDesc('avg_latency')
            ->get();
    }

    public function dailyCost()
    {
        return DB::table('agent_metrics')
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(estimated_cost_usd) as cost')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    public function agentComplexity()
    {
        return DB::table('agent_turn_metrics')
            ->select('agent_count', DB::raw('COUNT(*) as turns'))
            ->groupBy('agent_count')
            ->orderBy('agent_count')
            ->get();
    }

    public function tokenUsage()
    {
        return DB::table('agent_metrics')
            ->select(
                DB::raw('DATE(created_at) as day'),
                DB::raw('SUM(input_tokens) as input_tokens'),
                DB::raw('SUM(output_tokens) as output_tokens')
            )
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    public function agentFailures()
    {
        return DB::table('agent_metrics')
            ->select('intent', DB::raw('COUNT(*) as failures'))
            ->where('success', false)
            ->groupBy('intent')
            ->orderByDesc('failures')
            ->get();
    }
}
