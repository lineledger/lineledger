<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\ReportFavoriteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A report a user has starred for quick access within a company. Keyed by the
 * report's route name (see ReportCatalog). Per user + company.
 */
#[Fillable([
    'company_id',
    'user_id',
    'report_key',
])]
class ReportFavorite extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<ReportFavoriteFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
