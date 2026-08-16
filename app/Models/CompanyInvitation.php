<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Database\Factories\CompanyInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'email', 'role', 'sections', 'invited_by', 'expires_at', 'accepted_at'])]
class CompanyInvitation extends Model
{
    /** @use HasFactory<CompanyInvitationFactory> */
    use HasFactory;

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (CompanyInvitation $invitation) {
            if (empty($invitation->code)) {
                $invitation->code = Str::random(64);
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'sections' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }
}
