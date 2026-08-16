<?php

namespace App\Models;

use Database\Factories\ReportGroupAccountMapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Maps one source account (in a member company) to a combined report line.
 * The account belongs to a tenant, so its relation skips the company global scope.
 */
#[Fillable(['report_group_id', 'report_group_line_id', 'company_id', 'account_id'])]
class ReportGroupAccountMap extends Model
{
    /** @use HasFactory<ReportGroupAccountMapFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<ReportGroup, $this>
     */
    public function reportGroup(): BelongsTo
    {
        return $this->belongsTo(ReportGroup::class);
    }

    /**
     * @return BelongsTo<ReportGroupLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(ReportGroupLine::class, 'report_group_line_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScopes();
    }
}
