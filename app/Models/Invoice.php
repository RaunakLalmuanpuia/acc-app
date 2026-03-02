<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Invoice extends Model
{
    use SoftDeletes, LogsActivity;

    protected $fillable = [
        'invoice_number',
        'company_id', 'client_id',
        'company_name', 'company_gst_number', 'company_state', 'company_state_code',
        'client_name', 'client_email', 'client_address', 'client_gst_number', 'client_state', 'client_state_code',
        'invoice_date', 'due_date',
        'currency',
        'subtotal', 'discount_amount', 'taxable_amount',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'gst_amount',
        'total_amount', 'amount_paid', 'amount_due',
        'invoice_type', 'status', 'supply_type',
        'payment_terms',
        'bank_account_name', 'bank_account_number', 'bank_ifsc_code',
        'notes', 'terms_and_conditions', 'pdf_path',
        'reference_invoice_id',
    ];

    protected $casts = [
        'invoice_date'    => 'date',
        'due_date'        => 'date',
        'subtotal'        => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount'  => 'decimal:2',
        'cgst_amount'     => 'decimal:2',
        'sgst_amount'     => 'decimal:2',
        'igst_amount'     => 'decimal:2',
        'gst_amount'      => 'decimal:2',
        'total_amount'    => 'decimal:2',
        'amount_paid'     => 'decimal:2',
        'amount_due'      => 'decimal:2',
    ];

    // ── Activity Log Configuration ─────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Invoice was {$eventName}");
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class)->orderBy('sort_order');
    }

    public function referenceInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'reference_invoice_id');
    }

    // ── Scopes ─────────────────────────────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeForClient(Builder $query, int $clientId): Builder
    {
        return $query->where('client_id', $clientId);
    }

    /**
     * Resolve an invoice by either its integer ID or its invoice_number string.
     * Usage: Invoice::forCompany($id)->resolveByRef($ref)->first()
     */
    public function scopeResolveByRef(Builder $query, int|string $ref): Builder
    {
        return is_numeric($ref)
            ? $query->where('id', $ref)
            : $query->where('invoice_number', $ref);
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('status', ['sent', 'partial'])
            ->where('due_date', '<', now()->toDateString());
    }

    /**
     * Scope to find stale draft invoices older than the given number of days.
     * Used by the scheduled cleanup command.
     */
    public function scopeStaleDrafts(Builder $query, int $days = 7): Builder
    {
        return $query->where('status', 'draft')
            ->where('created_at', '<', now()->subDays($days));
    }

    // ── Business Logic ─────────────────────────────────────────────────────

    /**
     * Generate a unique invoice number in the format INV-YYYYMMDD-NNNNN.
     * Retries on the rare chance of a collision (date + 5-digit random = 1-in-99999 per day).
     *
     * FIX: Replaced timestamp + 2-digit random (collision-prone) with
     *      date-prefixed + 5-digit random + uniqueness check.
     */
    public static function generateNumber(): string
    {
        $attempts = 0;

        do {
            $number = 'INV-' . now()->format('Ymd') . '-' . str_pad(random_int(1, 99999), 5, '0', STR_PAD_LEFT);
            $attempts++;

            if ($attempts > 10) {
                // Absolute fallback: use microseconds — astronomically unlikely to reach here
                $number = 'INV-' . now()->format('YmdHis') . '-' . random_int(100, 999);
                break;
            }
        } while (static::where('invoice_number', $number)->exists());

        return $number;
    }

    /** Determine supply type from company vs client state codes */
    public function determineSupplyType(): string
    {
        return $this->company_state_code === $this->client_state_code
            ? 'intra_state'
            : 'inter_state';
    }

    /**
     * Recalculate all totals from line items.
     *
     * FIX: Now also calculates invoice-level discount_amount as the sum of all
     *      per-line discount amounts, so the field is never silently left at 0.
     */
    public function recalculateTotals(): void
    {
        $this->load('lineItems');

        // Line-level discounted subtotal (amount = qty * rate * (1 - discount%))
        $subtotal       = $this->lineItems->sum('amount');
        $cgst           = $this->lineItems->sum('cgst_amount');
        $sgst           = $this->lineItems->sum('sgst_amount');
        $igst           = $this->lineItems->sum('igst_amount');
        $gstTotal       = $cgst + $sgst + $igst;

        // Invoice-level discount = sum of (qty * rate * discount%) across all lines
        // Assumes InvoiceLineItem has a computed/stored `discount_amount` column.
        // If your line item model stores the raw pre-discount value, adjust accordingly.
        $discountAmount = $this->lineItems->sum('discount_amount');

        $this->update([
            'subtotal'       => $subtotal,
            'discount_amount'=> $discountAmount,
            'taxable_amount' => $subtotal,
            'cgst_amount'    => $cgst,
            'sgst_amount'    => $sgst,
            'igst_amount'    => $igst,
            'gst_amount'     => $gstTotal,
            'total_amount'   => $subtotal + $gstTotal,
            'amount_due'     => ($subtotal + $gstTotal) - $this->amount_paid,
        ]);
    }

    /**
     * Mark invoice as fully paid, settling any remaining balance.
     */
    public function markAsPaid(): void
    {
        $this->update([
            'amount_paid' => $this->total_amount,
            'amount_due'  => 0,
            'status'      => 'paid',
        ]);
    }

    /** Record a partial or full payment and update invoice status accordingly */
    public function recordPayment(float $amount): void
    {
        $paid   = $this->amount_paid + $amount;
        $due    = $this->total_amount - $paid;
        $status = match (true) {
            $due <= 0 => 'paid',
            $paid > 0 => 'partial',
            default   => $this->status,
        };

        $this->update([
            'amount_paid' => $paid,
            'amount_due'  => max(0, $due),
            'status'      => $status,
        ]);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['sent', 'partial']) && $this->due_date?->isPast();
    }
}
