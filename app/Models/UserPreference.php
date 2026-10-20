<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Support\Preferences\UserPreferences;
use Database\Factories\UserPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One remembered screen setting for a user within a company — the bank account
 * they last worked in, a report filter, a register toggle — keyed by a stable
 * dotted key (e.g. `banking.last_account`). No row means the screen's default
 * applies. Read and written through {@see UserPreferences}.
 */
#[Fillable([
    'company_id',
    'user_id',
    'pref_key',
    'value',
])]
class UserPreference extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<UserPreferenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
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
