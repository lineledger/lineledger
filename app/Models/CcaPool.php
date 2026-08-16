<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\CcaClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The opening undepreciated capital cost (UCC) carried into a tax year for a CCA
 * class. Maintained by the user on the T2125 CCA worksheet; additions for the
 * year come from the asset register, so only the opening balance is stored.
 */
#[Fillable([
    'company_id',
    'tax_year',
    'cca_class',
    'opening_ucc_cents',
])]
class CcaPool extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'tax_year' => 'integer',
            'cca_class' => CcaClass::class,
            'opening_ucc_cents' => 'integer',
        ];
    }
}
