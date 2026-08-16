<?php

namespace App\Models;

use App\Concerns\HasCompanies;
use App\Enums\CalculatorMode;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

// `site_admin` (privilege flag), `current_company_id` (tenant pointer) and the
// `disabled_*` lockout columns are deliberately NOT mass-assignable — they are
// set via forceFill in the few server-controlled spots (CreateNewUser,
// switchCompany, the site admin portal) so a stray update($validated) can never
// escalate a user, move them between tenants, or unlock a disabled account.
#[Fillable(['name', 'email', 'password', 'calculator_mode'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasCompanies, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The model's default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'calculator_mode' => CalculatorMode::Standard->value,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'calculator_mode' => CalculatorMode::class,
            'site_admin' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * Whether a site admin has locked this account out of the platform.
     *
     * Enforced by the EnsureUserIsActive middleware (existing sessions) and the
     * EnsureUserIsNotDisabled login-pipeline step (new sign-ins).
     */
    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * The user's legal-document acceptances (one row per accepted version).
     *
     * @return HasMany<LegalAcceptance, $this>
     */
    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    /**
     * Devices the user marked "trusted" on the two-factor challenge so future
     * logins skip the 2FA prompt until each row expires.
     *
     * @return HasMany<TwoFactorRememberedDevice, $this>
     */
    public function twoFactorRememberedDevices(): HasMany
    {
        return $this->hasMany(TwoFactorRememberedDevice::class);
    }

    /**
     * Devices this user has logged in from (new-device detection).
     *
     * @return HasMany<LoginDevice, $this>
     */
    public function loginDevices(): HasMany
    {
        return $this->hasMany(LoginDevice::class);
    }

    /**
     * Support tickets this user has opened.
     *
     * @return HasMany<SupportTicket, $this>
     */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /**
     * Number of unread Site Admin replies across this user's tickets — the count
     * shown as the "(1)" badge in the user menu.
     */
    public function unreadSupportRepliesCount(): int
    {
        return SupportTicketMessage::query()
            ->where('from_admin', true)
            ->whereNull('read_at')
            ->whereHas('ticket', fn ($q) => $q->where('user_id', $this->getKey()))
            ->count();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }
}
