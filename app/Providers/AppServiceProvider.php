<?php

namespace App\Providers;

use App\Ai\Services\ObservabilityService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ObservabilityService MUST be a singleton.
        //
        // It buffers per-agent metrics in $turnMetrics[] across the sequential
        // dispatch loop (AgentDispatcherService calls recordAgentCall N times),
        // then flushes them in a single recordTurnSummary() call from
        // ChatOrchestrator. Without singleton binding, the dispatcher and
        // orchestrator each receive a different instance — the buffer written
        // by the dispatcher is never read by the orchestrator, so every turn
        // summary shows per_agent:[], total_tokens:0.
        $this->app->singleton(ObservabilityService::class);
    }

    public function boot(): void
    {
        //
    }
}
