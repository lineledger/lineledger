<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One snapshotted slip inside a finalized {@see PayrollSlipFiling}: the exact
 * associative array the slip calculator produced for one recipient at finalize
 * time. The employee portal serves T4/RL-1 PDFs from this snapshot only.
 *
 * @property array<string, mixed> $data
 */
#[Fillable([
    'company_id', 'payroll_slip_filing_id', 'contact_id', 'data',
])]
class PayrollSlipFilingLine extends Model
{
    use BelongsToCompany;

    /**
     * @return BelongsTo<PayrollSlipFiling, $this>
     */
    public function filing(): BelongsTo
    {
        return $this->belongsTo(PayrollSlipFiling::class, 'payroll_slip_filing_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }
}
