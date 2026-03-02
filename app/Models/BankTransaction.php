<?php

// php artisan make:model BankTransaction -m

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class BankTransaction extends Model
{
    use SoftDeletes,LogsActivity;

    protected $fillable = [
        'bank_account_id',
        'transaction_date', 'bank_reference', 'raw_narration',
        'type', 'amount', 'balance_after',
        'narration_head_id', 'narration_sub_head_id',
        'narration_note', 'party_name', 'party_reference',
        'narration_source', 'ai_confidence', 'ai_suggestions',
        'review_status',
        'is_reconciled', 'reconciled_invoice_id', 'reconciled_at',
        'dedup_hash', 'is_duplicate',
        'import_source', 'import_batch_id','applied_rule_id','ai_metadata'
    ];

    protected $casts = [
        'transaction_date' => 'date',
        'reconciled_at'    => 'date',
        'amount'           => 'decimal:2',
        'balance_after'    => 'decimal:2',
        'ai_confidence'    => 'decimal:2',
        'ai_suggestions'   => 'array',
        'ai_metadata'      => 'array',
        'is_reconciled'    => 'boolean',
        'is_duplicate'     => 'boolean',
    ];

    // ── Activity Log Configuration ─────────────────────────────────────────
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable() // Automatically logs changes to any field in $fillable
            ->logOnlyDirty() // Only logs fields that *actually* changed during an update
            ->dontSubmitEmptyLogs() // Prevents creating a log entry if nothing changed
            ->setDescriptionForEvent(fn(string $eventName) => "Bank transaction was {$eventName}");
    }
    // ── Relationships ──────────────────────────────────────────────────────
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function narrationHead(): BelongsTo
    {
        return $this->belongsTo(NarrationHead::class);
    }

    public function narrationSubHead(): BelongsTo
    {
        return $this->belongsTo(NarrationSubHead::class);
    }

    public function reconciledInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'reconciled_invoice_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    public function narrate(
        NarrationSubHead $subHead,
        string $source = 'manual',
        ?string $note = null,
        ?string $partyName = null
    ): void {
        $this->update([
            'narration_head_id'     => $subHead->narration_head_id,
            'narration_sub_head_id' => $subHead->id,
            'narration_source'      => $source,
            'narration_note'        => $note,
            'party_name'            => $partyName,
            'review_status'         => 'reviewed',
        ]);
    }

    public static function makeDedupHash(string $date, float $amount, string $type, string $ref = ''): string
    {
        return md5("{$date}|{$amount}|{$type}|{$ref}");
    }

    public function isCredit(): bool { return $this->type === 'credit'; }
    public function isDebit(): bool  { return $this->type === 'debit'; }
}
