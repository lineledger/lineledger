<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DeadlineDigestFrequency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member's deadline email choices in one organization. A member without a
 * row gets the defaults (reminders on, weekly digest).
 *
 * @property int $id
 * @property int $company_id
 * @property int $user_id
 * @property bool $email_reminders
 * @property DeadlineDigestFrequency $digest
 */
#[Fillable(['user_id', 'email_reminders', 'digest'])]
class DeadlineNotificationSetting extends Model
{
    use BelongsToCompany;

    protected $attributes = [
        'email_reminders' => true,
        'digest' => 'weekly',
    ];

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
            'email_reminders' => 'boolean',
            'digest' => DeadlineDigestFrequency::class,
        ];
    }
}
