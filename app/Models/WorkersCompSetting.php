<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A company's workers'-comp (WSIB/WCB) assessment rate for one province: the rate
 * per $100 of assessable payroll (basis points), the per-worker annual maximum
 * assessable earnings, and the board account number for remittance. Quebec is
 * handled separately by CNESST.
 *
 * @property int $rate_bp
 * @property int|null $annual_max_assessable_cents
 */
#[Fillable([
    'company_id', 'province', 'rate_bp', 'annual_max_assessable_cents', 'board_account', 'is_active',
])]
class WorkersCompSetting extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate_bp' => 'integer',
            'annual_max_assessable_cents' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
