<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\GridPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's column-visibility choices for one data grid within a company, keyed
 * by the grid's stable key (e.g. `chart_of_accounts`). No row means the grid's
 * defaults apply. Per user + company.
 */
#[Fillable([
    'company_id',
    'user_id',
    'grid_key',
    'visible_columns',
])]
class GridPreference extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<GridPreferenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible_columns' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
