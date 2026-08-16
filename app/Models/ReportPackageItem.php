<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report inside a management report package: the report's route key plus
 * an optional settings snapshot (filters, presentation) carried into the
 * bundled render. The package's period overrides any saved dates.
 */
#[Fillable([
    'company_id',
    'report_package_id',
    'report_key',
    'label',
    'settings',
    'memorized_report_id',
    'sort_order',
])]
class ReportPackageItem extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<ReportPackage, $this>
     */
    public function package(): BelongsTo
    {
        return $this->belongsTo(ReportPackage::class, 'report_package_id');
    }

    /**
     * @return BelongsTo<MemorizedReport, $this>
     */
    public function memorizedReport(): BelongsTo
    {
        return $this->belongsTo(MemorizedReport::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }
}
