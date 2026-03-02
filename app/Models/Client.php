<?php

// php artisan make:model Client -m

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Client extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name', 'email', 'phone',
        'gst_number', 'pan_number', 'gst_type',
        'address', 'city', 'state', 'state_code', 'pincode', 'country',
        'currency', 'payment_terms', 'credit_limit',
        'notes', 'is_active',
    ];

    protected $casts = [
        'is_active'    => 'boolean',
        'credit_limit' => 'decimal:2',
    ];

    // ── Relationships ──────────────────────────────────────────────────────

    /** Client belongs to a Company */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** Client has many Invoices */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }



    // ── Computed ───────────────────────────────────────────────────────────

    public function getTotalOutstandingAttribute(): float
    {
        return $this->invoices()
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('amount_due');
    }
}
