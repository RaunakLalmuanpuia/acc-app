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
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->restrictOnDelete();

            // Raw bank data
            $table->date('transaction_date');
            $table->string('bank_reference')->nullable();
            $table->text('raw_narration');
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 15, 2);
            $table->decimal('balance_after', 15, 2)->nullable();

            // Narration (categorization)
            $table->foreignId('narration_head_id')->nullable()->constrained('narration_heads')->nullOnDelete();
            $table->foreignId('narration_sub_head_id')->nullable()->constrained('narration_sub_heads')->nullOnDelete();
            $table->string('narration_note')->nullable();
            $table->string('party_name')->nullable();
            $table->string('party_reference')->nullable();

            // AI / automation
            $table->enum('narration_source', ['manual', 'ai_suggested', 'rule_based', 'auto_matched'])->default('manual');
            $table->decimal('ai_confidence', 5, 2)->nullable();
            $table->json('ai_suggestions')->nullable();

            // Status
            $table->enum('review_status', ['pending', 'reviewed', 'flagged'])->default('pending');

            // Reconciliation
            $table->boolean('is_reconciled')->default(false);
            $table->foreignId('reconciled_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->date('reconciled_at')->nullable();

            // Dedup
            $table->string('dedup_hash')->nullable()->index();
            $table->boolean('is_duplicate')->default(false);

            // Import metadata
            $table->string('import_source')->nullable();
            $table->string('import_batch_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('transaction_date');
            $table->index('review_status');
            // Link to the rule that categorized it (optional, for auditing)
            $table->foreignId('applied_rule_id')
                ->nullable()
                ->constrained('narration_rules')
                ->nullOnDelete();

            // Store what the AI originally thought vs. what was picked
            $table->json('ai_metadata')->nullable();

            $table->index('type');
            $table->index('bank_account_id');
            $table->index('narration_head_id');
            $table->index('narration_sub_head_id');
            $table->index('import_batch_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
