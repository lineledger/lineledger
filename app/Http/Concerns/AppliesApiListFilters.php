<?php

namespace App\Http\Concerns;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Shared list-endpoint behavior: status / date-range / search filtering,
 * whitelisted sorting, and capped pagination. Configured per resource via the
 * `$config` array:
 *
 *   [
 *     'date_column'    => 'invoice_date',          // for ?from / ?to
 *     'status_column'  => 'status',                // for ?status
 *     'search'         => ['invoice_no', 'memo'],  // for ?search (LIKE)
 *     'sortable'       => ['invoice_date', 'invoice_no', 'total_cents'],
 *     'default_sort'   => ['id', 'desc'],
 *   ]
 */
trait AppliesApiListFilters
{
    /**
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $config
     * @return Builder<Model>
     */
    protected function applyApiListFilters(Builder $query, Request $request, array $config): Builder
    {
        if (($statusColumn = $config['status_column'] ?? 'status') && $request->filled('status')) {
            $query->where($statusColumn, $request->string('status'));
        }

        if (($dateColumn = $config['date_column'] ?? null)) {
            if ($request->filled('from')) {
                $query->whereDate($dateColumn, '>=', $request->date('from'));
            }
            if ($request->filled('to')) {
                $query->whereDate($dateColumn, '<=', $request->date('to'));
            }
        }

        $searchColumns = $config['search'] ?? [];
        if ($searchColumns !== [] && $request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function (Builder $q) use ($searchColumns, $term): void {
                foreach ($searchColumns as $column) {
                    $q->orWhere($column, 'like', $term);
                }
            });
        }

        $sortable = $config['sortable'] ?? [];
        $sort = $request->string('sort')->toString();
        if ($sort !== '' && in_array($sort, $sortable, true)) {
            $direction = strtolower($request->string('direction')->toString()) === 'asc' ? 'asc' : 'desc';
            $query->orderBy($sort, $direction);
        } else {
            [$column, $direction] = $config['default_sort'] ?? ['id', 'desc'];
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    /**
     * Paginate with a caller-controlled, capped page size.
     *
     * @param  Builder<Model>  $query
     * @return LengthAwarePaginator<int, Model>
     */
    protected function paginateApi(Builder $query, Request $request, int $default = 25, int $max = 100): LengthAwarePaginator
    {
        $perPage = (int) $request->integer('per_page', $default);
        $perPage = max(1, min($perPage, $max));

        return $query->paginate($perPage)->withQueryString();
    }
}
