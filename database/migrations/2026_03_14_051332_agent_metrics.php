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
        Schema::create('agent_metrics', function (Blueprint $table) {

            $table->id();

            // conversation context
            $table->string('conversation_id', 60)->nullable()->index();
            $table->string('turn_id', 60)->nullable()->index();

            $table->foreignId('user_id')->nullable()->index();

            // agent metadata
            $table->string('intent')->index();
            $table->string('agent_name')->nullable();
            $table->string('model')->nullable();

            // performance
            $table->integer('latency_ms')->nullable();

            // token usage
            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('total_tokens')->nullable();

            // cost
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);

            // result state
            $table->boolean('success')->default(true);
            $table->string('outcome')->nullable()->index();
            // completed | clarifying | partial | error

            $table->text('error')->nullable();

            // JSON telemetry
            $table->json('meta')->nullable();

            $table->timestamp('created_at')->index();

            $table->index(['intent', 'created_at']);
            $table->index(['model', 'created_at']);
            $table->index(['outcome', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
        Schema::dropIfExists('agent_metrics');
    }
};
