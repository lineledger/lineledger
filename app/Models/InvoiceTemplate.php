<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id', 'name', 'is_active',
])]
class InvoiceTemplate extends Model
{
    use BelongsToCompany, SoftDeletes;

    /**
     * @return HasMany<InvoiceTemplateLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceTemplateLine::class)->orderBy('line_order');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
