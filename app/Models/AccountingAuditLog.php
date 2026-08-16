<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'company_id',
    'sequence',
    'recorded_at',
    'actor_user_id',
    'api_key_id',
    'actor_ip',
    'actor_user_agent',
    'action',
    'auditable_type',
    'auditable_id',
    'journal_entry_id',
    'payload',
    'hash_input',
    'previous_hash',
    'row_hash',
])]
class AccountingAuditLog extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('AccountingAuditLog rows are immutable.');
        });

        static::deleting(function (): void {
            throw new \LogicException('AccountingAuditLog rows are immutable.');
        });
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    /**
     * @return BelongsTo<CompanyApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(CompanyApiKey::class, 'api_key_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'payload' => 'array',
            'action' => AuditAction::class,
        ];
    }
}
