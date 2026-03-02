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
        Schema::create('narration_rules', function (Blueprint $table) {
            $table->id();
            // Strict Multi-tenancy
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            $table->enum('match_type', ['contains', 'starts_with', 'ends_with', 'exact', 'regex'])->default('contains');
            $table->string('match_value');
            $table->enum('transaction_type', ['credit', 'debit', 'both'])->default('both');

            $table->decimal('amount_min', 15, 2)->nullable();
            $table->decimal('amount_max', 15, 2)->nullable();

            $table->foreignId('narration_head_id')->constrained()->cascadeOnDelete();
            $table->foreignId('narration_sub_head_id')->constrained()->cascadeOnDelete();

            $table->string('note_template')->nullable();
            $table->integer('priority')->default(10);
            $table->boolean('is_active')->default(true);
            $table->enum('source', ['manual', 'learned'])->default('manual');
            $table->unsignedInteger('match_count')->default(0);
            $table->timestamp('last_matched_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexing for performance and privacy
            $table->index(['company_id', 'transaction_type', 'is_active'], 'company_rule_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('narration_rules');
    }
};
