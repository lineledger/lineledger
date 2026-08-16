<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts queries to rows belonging to the currently-bound company.
 * Only applies when `current_company` is bound in the container — this
 * means console/seeders/tests can opt out by simply not binding it.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound('current_company')) {
            $builder->where($model->qualifyColumn('company_id'), app('current_company')->id);
        }
    }
}
