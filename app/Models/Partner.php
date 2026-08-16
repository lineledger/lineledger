<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A partner in a partnership. Holds an ownership share in basis points
 * (10000 = 100%) used to allocate the partnership's net income on the T5013.
 */
#[Fillable([
    'company_id',
    'name',
    'business_number',
    'share_bps',
])]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use BelongsToCompany, HasFactory;

    protected function casts(): array
    {
        return [
            'share_bps' => 'integer',
        ];
    }

    public function sharePercent(): float
    {
        return $this->share_bps / 100;
    }
}
