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
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();

            // Classification
            $table->string('category')->nullable();
            $table->string('brand')->nullable();
            $table->string('unit', 50);

            // Tax
            $table->string('hsn_code', 20)->nullable();
            $table->decimal('gst_rate', 5, 2)->default(18.00);

            // Pricing
            $table->decimal('rate', 15, 2);
            $table->decimal('cost_price', 15, 2)->nullable();
            $table->decimal('mrp', 15, 2)->nullable();

            // Stock tracking
            $table->boolean('track_stock')->default(false);
            $table->integer('stock_quantity')->default(0);
            $table->integer('low_stock_alert')->nullable();

            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['company_id', 'sku']);
            $table->index(['company_id', 'name']);
            $table->index('hsn_code');
            $table->index('category');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
