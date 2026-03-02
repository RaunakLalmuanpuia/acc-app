<?php

// php artisan make:model BankAccount -m

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'account_name', 'bank_name',
        'account_number', 'ifsc_code', 'branch', 'account_type', 'currency',
        'opening_balance', 'opening_balance_date', 'current_balance',
        'is_primary', 'is_active',
    ];

    protected $casts = [
        'opening_balance'      => 'decimal:2',
        'current_balance'      => 'decimal:2',
        'opening_balance_date' => 'date',
        'is_primary'           => 'boolean',
        'is_active'            => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }

}
