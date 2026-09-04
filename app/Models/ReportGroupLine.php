<?php

namespace App\Models;

use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Observers\ReportGroupLineObserver;
use App\Support\Reporting\CashFlowBucket;
use Database\Factories\ReportGroupLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A combined/target line within a report group. Source accounts from member
 * companies are mapped onto it; its `type` decides which report section it lands
 * in and how the summed raw balance is converted to a natural-balance figure.
 *
 * `cash_flow_activity` is an optional per-line override of the activity the line
 * is presented under on the combined Statement of Cash Flows (the combined
 * counterpart of {@see Account::$cash_flow_activity}); resolved by
 * {@see CashFlowBucket::forLine()}.
 */
#[Fillable(['report_group_id', 'report_group_section_id', 'name', 'type', 'subtype', 'cash_flow_activity', 'sort_order', 'is_passthrough'])]
#[ObservedBy(ReportGroupLineObserver::class)]
class ReportGroupLine extends Model
{
    /** @use HasFactory<ReportGroupLineFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ReportGroup, $this>
     */
    public function reportGroup(): BelongsTo
    {
        return $this->belongsTo(ReportGroup::class);
    }

    /**
     * @return BelongsTo<ReportGroupSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(ReportGroupSection::class, 'report_group_section_id');
    }

    /**
     * @return HasMany<ReportGroupAccountMap, $this>
     */
    public function accountMaps(): HasMany
    {
        return $this->hasMany(ReportGroupAccountMap::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'subtype' => AccountSubtype::class,
            'cash_flow_activity' => CashFlowActivity::class,
            'sort_order' => 'integer',
            'is_passthrough' => 'boolean',
        ];
    }
}
