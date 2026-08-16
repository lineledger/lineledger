<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\InsightSource;
use Carbon\CarbonImmutable;
use Database\Factories\DailyInsightFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One "Did you know?" insight per company per calendar day (company-local),
 * written nightly by the insights:generate command. `type` is the detector
 * key that produced it, `facts` is the winning candidate's payload (money as
 * integer cents plus pre-formatted display strings — never contact names or
 * transaction descriptions), and `source` records whether the copy came from
 * Claude or the detector's own template.
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonImmutable $insight_date
 * @property string $type
 * @property InsightSource $source
 * @property string $headline
 * @property string $body
 * @property array<string, mixed>|null $facts
 */
#[Fillable([
    'company_id', 'insight_date', 'type', 'source', 'headline', 'body', 'facts',
])]
class DailyInsight extends Model
{
    /** @use HasFactory<DailyInsightFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'insight_date' => 'date:Y-m-d',
            'source' => InsightSource::class,
            'facts' => 'array',
        ];
    }
}
