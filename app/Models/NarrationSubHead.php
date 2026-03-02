<?php



namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
class NarrationSubHead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'narration_head_id', 'name', 'slug', 'description',
        'ledger_code', 'ledger_name', 'requires_reference',
        'requires_party', 'custom_fields', 'sort_order',
        'is_active', 'is_system'
    ];

    protected $casts = [
        'is_active'          => 'boolean',
        'is_system'          => 'boolean',
        'requires_reference' => 'boolean',
        'requires_party'     => 'boolean',
        'custom_fields'      => 'array',
    ];

    public function narrationHead(): BelongsTo
    {
        return $this->belongsTo(NarrationHead::class, 'narration_head_id');
    }


    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(NarrationRule::class);
    }
}
