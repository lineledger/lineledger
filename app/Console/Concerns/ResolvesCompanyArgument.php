<?php

namespace App\Console\Concerns;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;

/**
 * The optional `{company?}` argument the per-company commands share.
 *
 * An all-digit value is an id and anything else is a slug — never `id OR slug`.
 * MySQL coerces `id = '1st-street-bakery'` to `id = 1` and `slug = 5` matches
 * '5abc', so the OR form could pull a second tenant into a run that named one.
 */
trait ResolvesCompanyArgument
{
    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    protected function whereCompanyArgument(Builder $query, string|int $arg): Builder
    {
        return ctype_digit((string) $arg)
            ? $query->whereKey((int) $arg)
            : $query->where('slug', (string) $arg);
    }
}
