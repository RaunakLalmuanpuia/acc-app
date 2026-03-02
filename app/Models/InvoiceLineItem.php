<?php

// php artisan make:model InvoiceLineItem -m

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Contracts\Activity;


class InvoiceLineItem extends Model
{
    use LogsActivity;
    protected $fillable = [
        'invoice_id', 'inventory_item_id',
        'description', 'hsn_code', 'unit',
        'quantity', 'rate', 'discount_percent', 'discount_amount', 'amount',
        'gst_rate', 'cgst_rate', 'sgst_rate', 'igst_rate',
        'cgst_amount', 'sgst_amount', 'igst_amount', 'total_tax_amount', 'total_amount',
        'sort_order',
    ];

    protected $casts = [
        'quantity'         => 'decimal:3',
        'rate'             => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'discount_amount'  => 'decimal:2',
        'amount'           => 'decimal:2',
        'gst_rate'         => 'decimal:2',
        'cgst_rate'        => 'decimal:2',
        'sgst_rate'        => 'decimal:2',
        'igst_rate'        => 'decimal:2',
        'cgst_amount'      => 'decimal:2',
        'sgst_amount'      => 'decimal:2',
        'igst_amount'      => 'decimal:2',
        'total_tax_amount' => 'decimal:2',
        'total_amount'     => 'decimal:2',
    ];

    // ── Activity Log Configuration ─────────────────────────────────────────

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->setDescriptionForEvent(fn(string $eventName) => "Line item was {$eventName}");
    }

    /**
     * Tap into the activity before it is saved to the database.
     * This forces the child log to "remember" its parent invoice ID forever,
     * allowing you to build a unified timeline even if this line item is later deleted.
     */
    public function tapActivity(Activity $activity, string $eventName)
    {
        if ($this->invoice_id) {
            $activity->properties = $activity->properties->put('invoice_id', $this->invoice_id);
        }
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    /**
     * Calculate and populate all tax amounts.
     * @param string $supplyType 'intra_state' → CGST+SGST | 'inter_state' → IGST
     */
    public function calculateAmounts(string $supplyType = 'inter_state'): void
    {
        $base        = round($this->quantity * $this->rate, 2);
        $discount    = round($base * ($this->discount_percent / 100), 2);
        $taxable     = $base - $discount;

        if ($supplyType === 'intra_state') {
            $half        = $this->gst_rate / 2;
            $cgst        = round($taxable * $half / 100, 2);
            $sgst        = $cgst;
            $igst        = 0;
        } else {
            $cgst = $sgst = 0;
            $igst        = round($taxable * $this->gst_rate / 100, 2);
        }

        $this->fill([
            'discount_amount'  => $discount,
            'amount'           => $taxable,
            'cgst_rate'        => $supplyType === 'intra_state' ? $this->gst_rate / 2 : 0,
            'sgst_rate'        => $supplyType === 'intra_state' ? $this->gst_rate / 2 : 0,
            'igst_rate'        => $supplyType === 'inter_state' ? $this->gst_rate : 0,
            'cgst_amount'      => $cgst,
            'sgst_amount'      => $sgst,
            'igst_amount'      => $igst,
            'total_tax_amount' => $cgst + $sgst + $igst,
            'total_amount'     => $taxable + $cgst + $sgst + $igst,
        ]);
    }
}
