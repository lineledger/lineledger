@if ($warnings['currency']->isNotEmpty())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-900/20 dark:text-red-100" data-test="currency-warning">
        {{ __('These companies use a different currency than the group — combined totals mix currencies:') }}
        {{ $warnings['currency']->pluck('name')->join(', ') }}
    </div>
@endif

@if ($warnings['fiscal'] ?? false)
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-200/10 dark:bg-amber-900/20 dark:text-amber-100" data-test="fiscal-warning">
        {{ __('Member companies have different fiscal year starts, so the combined net income (YTD) sums non-aligned periods.') }}
    </div>
@endif
