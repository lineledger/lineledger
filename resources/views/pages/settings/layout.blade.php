@props(['heading' => '', 'subheading' => '', 'contentClass' => 'max-w-lg'])

<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Settings') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('Profile') }}</flux:navlist.item>
            <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
            <flux:navlist.item :href="route('companies.index')" :current="request()->routeIs('companies.*')" wire:navigate>{{ __('Organizations') }}</flux:navlist.item>
            <flux:navlist.item :href="route('report-groups.index')" :current="request()->routeIs('report-groups.*')" wire:navigate>{{ __('Combined reports') }}</flux:navlist.item>
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('Appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('navigation.edit')" :current="request()->routeIs('navigation.*')" wire:navigate>{{ __('Sidebar') }}</flux:navlist.item>
            <flux:navlist.item :href="route('legal.edit')" :current="request()->routeIs('legal.edit')" wire:navigate>{{ __('Legal') }}</flux:navlist.item>

            @php($listsCompany = request()->route('company') ?: auth()->user()?->currentCompany)
            @if ($listsCompany)
                @php($listsInventory = (bool) ($listsCompany->features_inventory ?? true))
                @php($listsFixedAssets = (bool) ($listsCompany->features_fixed_assets ?? true))
                @php($listsClasses = (bool) ($listsCompany->features_classes ?? false))
                @php($listsLocations = (bool) ($listsCompany->features_locations ?? false))
                @php($listsMembership = (bool) ($listsCompany->features_membership ?? false))
                @php($listsIsOwner = auth()->user()?->companyRole($listsCompany) === \App\Enums\CompanyRole::Owner)
                @php($listsImportInProgress = \App\Models\DataMigrationRun::withoutGlobalScopes()
                    ->where('company_id', $listsCompany->id)
                    ->where('status', \App\Enums\DataMigrationStatus::InProgress->value)
                    ->exists())
                <flux:navlist.group :heading="__('Company')" class="mt-4">
                    <flux:navlist.item :href="route('settings.invoices', ['company' => $listsCompany])" :current="request()->routeIs('settings.invoices')" wire:navigate>{{ __('Invoices') }}</flux:navlist.item>
                    @if ($listsCompany?->supports(\App\Enums\JurisdictionCapability::CraTaxFiling))
                        <flux:navlist.item :href="route('settings.tax-and-filing', ['company' => $listsCompany])" :current="request()->routeIs('settings.tax-and-filing')" wire:navigate>{{ __('Tax & filing') }}</flux:navlist.item>
                    @endif
                    @if ($listsCompany?->usesPayroll())
                        <flux:navlist.item :href="route('settings.payroll', ['company' => $listsCompany])" :current="request()->routeIs('settings.payroll')" wire:navigate>{{ __('Payroll') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('payroll-schedules.index', ['company' => $listsCompany])" :current="request()->routeIs('payroll-schedules.*')" wire:navigate class="pl-6">{{ __('Pay schedules') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('time-off-policies.index', ['company' => $listsCompany])" :current="request()->routeIs('time-off-policies.*')" wire:navigate class="pl-6">{{ __('Time-off policies') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('payroll.reports.verification', ['company' => $listsCompany])" :current="request()->routeIs('payroll.reports.verification')" wire:navigate class="pl-6">{{ __('Calculation tips') }}</flux:navlist.item>
                    @endif
                    <flux:navlist.item :href="route('settings.currencies', ['company' => $listsCompany])" :current="request()->routeIs('settings.currencies')" wire:navigate>{{ __('Currencies') }}</flux:navlist.item>
                    @if ($listsInventory)
                        <flux:navlist.item :href="route('settings.inventory', ['company' => $listsCompany])" :current="request()->routeIs('settings.inventory')" wire:navigate>{{ __('Inventory') }}</flux:navlist.item>
                    @endif
                    @if ($listsIsOwner)
                        <flux:navlist.item :href="route('settings.backup-and-export', ['company' => $listsCompany])" :current="request()->routeIs('settings.backup-and-export')" wire:navigate>{{ __('Backup & Export') }}</flux:navlist.item>
                    @endif
                    @if ($listsImportInProgress)
                        <flux:navlist.item :href="route('migration.import', ['company' => $listsCompany])" :current="request()->routeIs('migration.import')" wire:navigate>{{ __('Import from QuickBooks') }}</flux:navlist.item>
                    @endif
                </flux:navlist.group>
                <flux:navlist.group :heading="__('Lists')" class="mt-4">
                    <flux:navlist.item :href="route('lists.index', ['company' => $listsCompany])" :current="request()->routeIs('lists.index')" wire:navigate>{{ __('All lists') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('lists.items', ['company' => $listsCompany])" :current="request()->routeIs('lists.items')" wire:navigate>{{ __('Items') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('lists.item-categories', ['company' => $listsCompany])" :current="request()->routeIs('lists.item-categories')" wire:navigate>{{ __('Item categories') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('lists.tax-codes', ['company' => $listsCompany])" :current="request()->routeIs('lists.tax-codes')" wire:navigate>{{ __('Tax codes') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('lists.payment-terms', ['company' => $listsCompany])" :current="request()->routeIs('lists.payment-terms')" wire:navigate>{{ __('Payment terms') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('lists.payment-methods', ['company' => $listsCompany])" :current="request()->routeIs('lists.payment-methods')" wire:navigate>{{ __('Payment methods') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('lists.other-names', ['company' => $listsCompany])" :current="request()->routeIs('lists.other-names')" wire:navigate>{{ __('Other names') }}</flux:navlist.item>
                    @if ($listsClasses)
                        <flux:navlist.item :href="route('lists.classifications', ['company' => $listsCompany])" :current="request()->routeIs('lists.classifications')" wire:navigate>{{ __('Classes') }}</flux:navlist.item>
                    @endif
                    @if ($listsLocations)
                        <flux:navlist.item :href="route('lists.locations', ['company' => $listsCompany])" :current="request()->routeIs('lists.locations')" wire:navigate>{{ __('Locations') }}</flux:navlist.item>
                    @endif
                    @if ($listsFixedAssets)
                        <flux:navlist.item :href="route('lists.asset-categories', ['company' => $listsCompany])" :current="request()->routeIs('lists.asset-categories')" wire:navigate>{{ __('Asset categories') }}</flux:navlist.item>
                    @endif
                    @if ($listsMembership)
                        <flux:navlist.item :href="route('lists.membership-levels', ['company' => $listsCompany])" :current="request()->routeIs('lists.membership-levels')" wire:navigate>{{ __('Membership levels') }}</flux:navlist.item>
                    @endif
                    <flux:navlist.item :href="route('lists.form-styles', ['company' => $listsCompany])" :current="request()->routeIs('lists.form-styles')" wire:navigate>{{ __('Form styles') }}</flux:navlist.item>
                </flux:navlist.group>
            @endif
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full {{ $contentClass }}">
            {{ $slot }}
        </div>
    </div>
</div>
