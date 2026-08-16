<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'name', 'days', 'is_active'])]
class PaymentTerm extends Model
{
    use BelongsToCompany;

    public function dueDateFrom(CarbonInterface $invoiceDate): CarbonImmutable
    {
        return CarbonImmutable::parse($invoiceDate)->addDays((int) $this->days);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
