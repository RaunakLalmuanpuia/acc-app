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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_number')->unique();

            // Ownership
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();

            // ── Immutable snapshot: Company at time of invoice ─────────────
            $table->string('company_name');
            $table->string('company_gst_number', 20)->nullable();
            $table->string('company_state');
            $table->string('company_state_code', 5);

            // ── Immutable snapshot: Client at time of invoice ──────────────
            $table->string('client_name');
            $table->string('client_email')->nullable();
            $table->text('client_address')->nullable();
            $table->string('client_gst_number', 20)->nullable();
            $table->string('client_state');
            $table->string('client_state_code', 5);

            // Dates
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            // Amounts
            $table->string('currency', 10)->default('INR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('taxable_amount', 15, 2)->default(0);

            // GST breakdown
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('igst_amount', 15, 2)->default(0);
            $table->decimal('gst_amount', 15, 2)->default(0);

            $table->decimal('total_amount', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('amount_due', 15, 2)->default(0);

            // Type & Status
            $table->enum('invoice_type', ['tax_invoice', 'proforma', 'credit_note', 'debit_note'])->default('tax_invoice');
            $table->enum('status', ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled', 'void'])->default('draft');
            $table->enum('supply_type', ['intra_state', 'inter_state', 'sez', 'export'])->default('inter_state');

            // Payment
            $table->string('payment_terms')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number', 50)->nullable();
            $table->string('bank_ifsc_code', 20)->nullable();

            // Notes
            $table->text('notes')->nullable();
            $table->text('terms_and_conditions')->nullable();

            $table->string('pdf_path')->nullable();

            // Self-reference for credit/debit notes
            $table->foreignId('reference_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'client_id']);
            $table->index('invoice_date');
            $table->index('due_date');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
