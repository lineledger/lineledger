<div class="mb-5 flex flex-wrap items-center gap-1 border-b border-border pb-3">
    <flux:button
        size="sm"
        :variant="request()->routeIs('report-groups.balance-sheet') ? 'primary' : 'ghost'"
        :href="route('report-groups.balance-sheet', $reportGroup)"
        wire:navigate
    >{{ __('Balance Sheet') }}</flux:button>
    <flux:button
        size="sm"
        :variant="request()->routeIs('report-groups.income-statement') ? 'primary' : 'ghost'"
        :href="route('report-groups.income-statement', $reportGroup)"
        wire:navigate
    >{{ __('Income Statement') }}</flux:button>
    <flux:button
        size="sm"
        :variant="request()->routeIs('report-groups.cash-flow') ? 'primary' : 'ghost'"
        :href="route('report-groups.cash-flow', $reportGroup)"
        wire:navigate
    >{{ __('Cash Flow') }}</flux:button>
    <flux:button
        size="sm"
        :variant="request()->routeIs('report-groups.trial-balance') ? 'primary' : 'ghost'"
        :href="route('report-groups.trial-balance', $reportGroup)"
        wire:navigate
    >{{ __('Trial Balance') }}</flux:button>

    <flux:spacer />

    @can('update', $reportGroup)
        <flux:button size="sm" variant="ghost" icon="pencil" :href="route('report-groups.edit', $reportGroup)" wire:navigate>{{ __('Edit mapping') }}</flux:button>
    @endcan
</div>
