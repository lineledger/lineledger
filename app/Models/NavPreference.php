<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Database\Factories\NavPreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sidebar navigation link or group a user has hidden within a company. Keyed
 * by the catalog item/group key (see SidebarNavCatalog). A row means the key is
 * hidden; no row means visible. Per user + company.
 */
#[Fillable([
    'company_id',
    'user_id',
    'item_key',
])]
class NavPreference extends Model
{
    use BelongsToCompany;

    /** @use HasFactory<NavPreferenceFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
