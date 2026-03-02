<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    //
    use SoftDeletes;

    protected $fillable = [
        'user_id','company_name', 'gst_number', 'pan_number',
        'state', 'state_code',
        'address', 'city', 'pincode', 'country',
        'email', 'phone', 'website',
        'bank_account_name', 'bank_account_number', 'bank_ifsc_code', 'bank_name', 'bank_branch',
        'logo_path', 'invoice_footer_note',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
    // ── Relationships ──────────────────────────────────────────────────────

    /** A company Belongs to users */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A company has many clients */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** A company has many inventory items */
    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
    /** A company has many narration heads */
    public function narrationHeads(): HasMany
    {
        return $this->hasMany(NarrationHead::class)->orderBy('sort_order');
    }
    /** A company has many bank accounts */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
    public function rules(): HasMany
    {
        return $this->hasMany(NarrationRule::class);
    }
    /** A company has many invoices (via clients or directly) */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }
}
