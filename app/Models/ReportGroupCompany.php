<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivot row linking a report group to one of its member companies.
 */
#[Fillable(['report_group_id', 'company_id'])]
class ReportGroupCompany extends Model
{
    /**
     * @return BelongsTo<ReportGroup, $this>
     */
    public function reportGroup(): BelongsTo
    {
        return $this->belongsTo(ReportGroup::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
