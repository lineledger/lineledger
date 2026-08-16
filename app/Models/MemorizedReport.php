<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\MemorizedReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved report customization (QuickBooks "memorized report"): the report's
 * route key plus a snapshot of its URL-bound settings, re-applied on run.
 * Per user + company.
 */
#[Fillable([
    'company_id',
    'user_id',
    'memorized_report_group_id',
    'report_key',
    'name',
    'settings',
    'sort_order',
])]
class MemorizedReport extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<MemorizedReportFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<MemorizedReportGroup, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MemorizedReportGroup::class, 'memorized_report_group_id');
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
