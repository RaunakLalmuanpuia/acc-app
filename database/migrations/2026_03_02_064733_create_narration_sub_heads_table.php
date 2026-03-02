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
        Schema::create('narration_sub_heads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('narration_head_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('ledger_code')->nullable();
            $table->string('ledger_name')->nullable();
            $table->boolean('requires_reference')->default(false);
            $table->boolean('requires_party')->default(false);
            $table->json('custom_fields')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['narration_head_id', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('narration_sub_heads');
    }
};
