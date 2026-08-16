<?php

namespace App\Models;

use App\Enums\SecurityEvent;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string, mixed>|null $metadata Decoded JSON detail for the event.
 */
#[Fillable([
    'recorded_at',
    'user_id',
    'attempted_email',
    'company_id',
    'event',
    'ip_address',
    'user_agent',
    'metadata',
])]
class SecurityLog extends Model
{
    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('SecurityLog rows are immutable.');
        });

        static::deleting(function (): void {
            throw new \LogicException('SecurityLog rows are immutable.');
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
            'metadata' => 'array',
            'event' => SecurityEvent::class,
        ];
    }
}
