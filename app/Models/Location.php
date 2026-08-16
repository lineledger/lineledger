<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A QuickBooks-style "Location" — a reporting dimension transactions can be
 * tagged with, alongside {@see Classification}.
 */
#[Fillable(['company_id', 'name', 'is_active'])]
class Location extends Model
{
    use BelongsToCompany;

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
