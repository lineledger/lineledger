<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device (normalized user-agent fingerprint) a user has logged in from. Used
 * to detect a login from an unseen device.
 *
 * @property CarbonInterface $first_seen_at
 * @property CarbonInterface $last_seen_at
 */
#[Fillable([
    'user_id', 'device_hash', 'user_agent', 'ip_address', 'first_seen_at', 'last_seen_at',
])]
class LoginDevice extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
