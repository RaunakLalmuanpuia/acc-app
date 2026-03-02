<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class InventoryItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'name', 'sku', 'description',
        'category', 'brand', 'unit',
        'hsn_code', 'gst_rate',
        'rate', 'cost_price', 'mrp',
        'track_stock', 'stock_quantity', 'low_stock_alert',
        'image_path', 'is_active',
    ];

    protected $casts = [
        'gst_rate'    => 'decimal:2',
        'rate'        => 'decimal:2',
        'cost_price'  => 'decimal:2',
        'mrp'         => 'decimal:2',
        'track_stock' => 'boolean',
        'is_active'   => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function lineItems(): HasMany
    {
        return $this->hasMany(InvoiceLineItem::class);
    }
}
