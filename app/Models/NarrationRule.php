<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Builder;

class NarrationRule extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'company_id', 'match_type', 'match_value', 'transaction_type',
        'amount_min', 'amount_max', 'narration_head_id',
        'narration_sub_head_id', 'note_template', 'priority', 'is_active','source','match_count','last_matched_at'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'priority'  => 'integer',
    ];

    // ── Scope ──────────────────────────────────────────────────────────────

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    // ── Relationships ──────────────────────────────────────────────────────

    public function company(): BelongsTo {
        return $this->belongsTo(Company::class);
    }

    public function narrationHead(): BelongsTo {
        return $this->belongsTo(NarrationHead::class);
    }

    public function narrationSubHead(): BelongsTo {
        return $this->belongsTo(NarrationSubHead::class);
    }

    // ── Match Logic ────────────────────────────────────────────────────────

    public static function findBestMatch(string $narration, string $type, float $amount, int $companyId): ?self
    {
        return self::forCompany($companyId)
            ->where('is_active', true)
            ->where(function($q) use ($type) {
                $q->where('transaction_type', $type)->orWhere('transaction_type', 'both');
            })
            ->orderBy('priority', 'asc')
            ->get()
            ->first(fn($rule) => $rule->matches($narration, $type, $amount));
    }

    public function matches(string $narration, string $type, float $amount = 0): bool
    {
        if ($this->amount_min && $amount < $this->amount_min) return false;
        if ($this->amount_max && $amount > $this->amount_max) return false;

        $subject = strtolower($narration);
        $search = strtolower($this->match_value);

        return match ($this->match_type) {
            'contains'    => str_contains($subject, $search),
            'starts_with' => str_starts_with($subject, $search),
            'ends_with'   => str_ends_with($subject, $search),
            'exact'       => $subject === $search,
            'regex'       => (bool) preg_match($this->match_value, $narration),
            default       => false,
        };
    }

    public function generateNote(string $rawNarration, float $amount, $date = null): string
    {
        $template = $this->note_template ?? "{match} Transaction";
        $dateObj = $date ?? now();

        $replacements = [
            '{match}'  => Str::title($this->match_value),
            '{raw}'    => $rawNarration,
            '{amount}' => number_format($amount, 2),
            '{date}'   => $dateObj instanceof \DateTime ? $dateObj->format('d-M-Y') : $dateObj,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}
