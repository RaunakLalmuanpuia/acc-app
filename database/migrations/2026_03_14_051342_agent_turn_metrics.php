<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        //
        Schema::create('agent_turn_metrics', function (Blueprint $table) {

            $table->id();

            $table->string('conversation_id', 60)->nullable()->index();
            $table->string('turn_id', 60)->unique();

            $table->foreignId('user_id')->nullable()->index();

            $table->integer('agent_count')->default(0);

            $table->integer('total_latency_ms')->nullable();

            $table->integer('total_tokens')->nullable();

            $table->decimal('total_cost_usd', 10, 6)->default(0);

            $table->integer('failed_agent_count')->default(0);

            $table->boolean('all_succeeded')->default(true);

            // evaluation metrics
            $table->decimal('completion_rate_pct', 5, 2)->nullable();

            $table->json('outcome_distribution')->nullable();

            $table->json('intents')->nullable();

            $table->timestamp('created_at')->index();

            $table->index(['agent_count','created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('agent_turn_metrics');
    }
};
