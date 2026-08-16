<?php

namespace App\Models;

use App\Services\Security\TrustedDeviceManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A device a user marked "trusted" on the two-factor challenge, letting future
 * logins on that device skip the 2FA prompt until {@see $expires_at}. The
 * cookie carries a random token; only its SHA-256 hash is stored here.
 *
 * @see TrustedDeviceManager
 */
class TwoFactorRememberedDevice extends Model
{
    protected $fillable = [
        'user_id',
        'token_hash',
        'expires_at',
        'last_used_at',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
