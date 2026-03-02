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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();

            // GST / Tax
            $table->string('gst_number', 20)->nullable();
            $table->string('pan_number', 15)->nullable();
            $table->enum('gst_type', ['regular', 'composition', 'unregistered', 'sez', 'overseas'])->default('regular');

            // Address
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state');
            $table->string('state_code', 5);
            $table->string('pincode', 10)->nullable();
            $table->string('country', 100)->default('India');

            // Billing defaults
            $table->string('currency', 10)->default('INR');
            $table->string('payment_terms')->nullable();
            $table->decimal('credit_limit', 15, 2)->nullable();

            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'name']);
            $table->index('gst_number');

        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
