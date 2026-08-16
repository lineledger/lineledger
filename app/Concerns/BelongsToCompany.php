<?php

namespace App\Concerns;

use App\Models\Company;
use App\Scopes\CompanyScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            // Within a request `current_company` is always bound, so the new
            // record is forced to belong to it. This is authoritative: it
            // overrides any client-supplied/mass-assigned company_id, closing
            // cross-tenant write injection. Console/seeders/tests don't bind
            // it, so an explicitly-set company_id is preserved there.
            if (app()->bound('current_company')) {
                $model->company_id = app('current_company')->id;
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
