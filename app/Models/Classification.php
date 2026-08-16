<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A QuickBooks-style "Class" — a reporting dimension transactions can be tagged
 * with. Named Classification because "Class" is a reserved PHP word; the column
 * and UI label remain "class".
 */
#[Fillable(['company_id', 'name', 'is_active'])]
class Classification extends Model
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
