<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\DeadlineNotificationKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A deadline email that was sent. The unique `(company_id, dedupe_key)` pair is
 * the idempotency guard for the hourly notification run.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $deadline_occurrence_id
 * @property int $user_id
 * @property DeadlineNotificationKind $kind
 * @property string $dedupe_key
 * @property CarbonImmutable $sent_on
 */
#[Fillable(['deadline_occurrence_id', 'user_id', 'kind', 'dedupe_key', 'sent_on'])]
class DeadlineNotificationLog extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DeadlineNotificationKind::class,
            'sent_on' => 'immutable_date:Y-m-d',
        ];
    }
}
