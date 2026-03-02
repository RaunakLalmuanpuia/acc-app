<?php
// php artisan make:model NarrationHead -m
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class NarrationHead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id',
        'name', 'slug', 'type', 'description', 'color',
        'icon', 'sort_order', 'is_active', 'is_system'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'sort_order' => 'integer'
    ];

    public function subHeads(): HasMany
    {
        return $this->hasMany(NarrationSubHead::class)->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForTransactionType(Builder $query, string $type): Builder
    {
        return $query->whereIn('type', [$type, 'both']);
    }
    public function activeSubHeads()
    {
        return $this->hasMany(NarrationSubHead::class)
            ->where('is_active', true)
            ->orderBy('sort_order');
    }

    /**
     * Scope: get heads for a specific company + shared system heads (company_id = null)
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where(function ($q) use ($companyId) {
            $q->where('company_id', $companyId)
                ->orWhereNull('company_id'); // global system heads
        });
    }
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(NarrationRule::class);
    }

}
