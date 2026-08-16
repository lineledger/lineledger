<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\ApiResource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $revoked_at
 * @property CarbonInterface|null $expires_at
 */
#[Fillable([
    'company_id', 'created_by_user_id', 'label',
    'prefix', 'token_hash', 'last_four', 'abilities',
    'last_used_at', 'revoked_at', 'expires_at',
])]
class CompanyApiKey extends Model
{
    use BelongsToCompany;

    public const PREFIX = 'll_live';

    /**
     * Mint a new API key for the given company. Returns the model and the
     * one-time plaintext token the caller must surface to the user.
     *
     * Pass `$abilities` to scope the key; an empty array persists as null
     * (full access).
     *
     * @param  array<int, string>  $abilities
     * @return array{key: CompanyApiKey, plaintext: string}
     */
    public static function mint(Company $company, string $label, ?int $userId = null, array $abilities = [], ?\DateTimeInterface $expiresAt = null): array
    {
        $random = Str::random(40);
        $plaintext = self::PREFIX.'_'.$random;

        $key = self::create([
            'company_id' => $company->id,
            'created_by_user_id' => $userId,
            'label' => $label,
            'prefix' => self::PREFIX,
            'token_hash' => hash('sha256', $plaintext),
            'last_four' => substr($plaintext, -4),
            'abilities' => $abilities === [] ? null : array_values(array_unique($abilities)),
            'expires_at' => $expiresAt,
        ]);

        return ['key' => $key, 'plaintext' => $plaintext];
    }

    /**
     * Whether this key may perform the given `{name}:{action}` ability, where
     * `{name}` is either a coarse domain (e.g. `sales`) or a single resource
     * (e.g. `invoices`). Resolution, most-to-least specific:
     *
     *  - a key with no abilities (null) has full access;
     *  - an exact grant matches;
     *  - a `:write` grant satisfies the matching `:read` requirement;
     *  - a parent-domain grant satisfies any resource scope under it (so
     *    `sales:write` covers `invoices:write`, `customers:read`, …).
     */
    public function hasAbility(string $required): bool
    {
        $granted = $this->abilities;

        if (empty($granted)) {
            return true;
        }

        if (in_array($required, $granted, true)) {
            return true;
        }

        [$name, $action] = array_pad(explode(':', $required, 2), 2, '');

        // A write grant on the same name covers a read requirement.
        if ($action === 'read' && in_array("{$name}:write", $granted, true)) {
            return true;
        }

        // A grant on the resource's parent domain is a superset.
        $domain = ApiResource::tryFrom($name)?->domain();

        if ($domain !== null) {
            if (in_array("{$domain}:{$action}", $granted, true)) {
                return true;
            }

            if ($action === 'read' && in_array("{$domain}:write", $granted, true)) {
                return true;
            }
        }

        return false;
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function revoke(): void
    {
        if ($this->revoked_at) {
            return;
        }

        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function touchUsage(): void
    {
        $this->forceFill(['last_used_at' => now()])->saveQuietly();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
            'abilities' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected $hidden = ['token_hash'];
}
