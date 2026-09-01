{{-- Shared heading + tab nav + apply-state banners for the Opening Balances
     workspace pages. Rendered inside each page component's scope: expects
     $company and $obState, and binds Retry to the host's retryApply(). --}}
<div class="mb-6">
    <flux:heading size="xl" level="1">{{ __('Opening balances') }}</flux:heading>
    <flux:text class="mt-1">
        {{ __('Bring your books over from your previous system as of :date. Everything here stays editable — the ledger follows every save.', ['date' => $obState->asOf()->format('F j, Y')]) }}
    </flux:text>

    @if ($obState->isFinalized())
        <flux:callout icon="lock-closed" class="mt-4" data-test="ob-finalized-banner">
            <flux:callout.text>
                {{ __('Opening balances are finalized and the books are locked through :date. Un-finalize on the Overview tab to edit again.', ['date' => $obState->asOf()->format('F j, Y')]) }}
            </flux:callout.text>
        </flux:callout>
    @elseif ($obState->apply_error)
        <flux:callout variant="danger" icon="exclamation-triangle" class="mt-4" data-test="ob-apply-error-banner">
            <flux:callout.heading>{{ __('The ledger could not be updated to match the draft') }}</flux:callout.heading>
            <flux:callout.text>{{ $obState->apply_error }}</flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="retryApply" data-test="ob-retry-apply">{{ __('Retry') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <flux:navbar class="mt-4 -mb-px border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar.item :href="route('opening-balances.index', ['company' => $company->slug])" :current="request()->routeIs('opening-balances.index')" wire:navigate data-test="ob-tab-overview">{{ __('Overview') }}</flux:navbar.item>
        <flux:navbar.item :href="route('opening-balances.trial-balance', ['company' => $company->slug])" :current="request()->routeIs('opening-balances.trial-balance')" wire:navigate data-test="ob-tab-trial-balance">{{ __('Trial balance') }}</flux:navbar.item>
        <flux:navbar.item :href="route('opening-balances.receivables', ['company' => $company->slug])" :current="request()->routeIs('opening-balances.receivables')" wire:navigate data-test="ob-tab-receivables">{{ __('Customers (AR)') }}</flux:navbar.item>
        <flux:navbar.item :href="route('opening-balances.payables', ['company' => $company->slug])" :current="request()->routeIs('opening-balances.payables')" wire:navigate data-test="ob-tab-payables">{{ __('Vendors (AP)') }}</flux:navbar.item>
        <flux:navbar.item :href="route('opening-balances.cheques', ['company' => $company->slug])" :current="request()->routeIs('opening-balances.cheques')" wire:navigate data-test="ob-tab-cheques">{{ __('Outstanding cheques') }}</flux:navbar.item>
        <flux:navbar.item :href="route('opening-balances.deposits', ['company' => $company->slug])" :current="request()->routeIs('opening-balances.deposits')" wire:navigate data-test="ob-tab-deposits">{{ __('Deposits in transit') }}</flux:navbar.item>
    </flux:navbar>
</div>
