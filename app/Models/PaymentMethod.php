<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['company_id', 'name', 'is_cheque', 'is_active'])]
class PaymentMethod extends Model
{
    use BelongsToCompany;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_cheque' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * The stored name for display. Seeded names are stored in English so
     * lookups stay stable; the UI translates them (a renamed one passes through).
     */
    public function displayName(): string
    {
        return __((string) $this->name);
    }
}
