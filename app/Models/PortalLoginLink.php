<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time, hashed magic-link token granting a customer access to the payment
 * portal. The plaintext token is emailed to the customer and never stored; only
 * its SHA-256 hash is persisted so a leaked database row cannot be replayed.
 */
#[Fillable([
    'company_id', 'contact_id', 'token_hash', 'expires_at', 'used_at', 'intended_path',
])]
class PortalLoginLink extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * Whether this link can still be exchanged for a session: never used and not
     * yet expired.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }
}
