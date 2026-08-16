<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('Documentation') }}">
            <flux:navlist.item :href="route('docs.getting-started')" :current="request()->routeIs('docs.getting-started')" wire:navigate>{{ __('Getting started') }}</flux:navlist.item>
            <flux:navlist.item :href="route('docs.creating-a-company')" :current="request()->routeIs('docs.creating-a-company')" wire:navigate>{{ __('Create an organization') }}</flux:navlist.item>
            <flux:navlist.item :href="route('docs.dashboard')" :current="request()->routeIs('docs.dashboard')" wire:navigate>{{ __('Dashboard') }}</flux:navlist.item>

            <flux:navlist.group :heading="__('Sales')" class="mt-4">
                <flux:navlist.item :href="route('docs.customers')" :current="request()->routeIs('docs.customers')" wire:navigate>{{ __('Customers') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.members')" :current="request()->routeIs('docs.members')" wire:navigate>{{ __('Members') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.estimates')" :current="request()->routeIs('docs.estimates')" wire:navigate>{{ __('Estimates') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.sales-orders')" :current="request()->routeIs('docs.sales-orders')" wire:navigate>{{ __('Sales orders') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.recurring')" :current="request()->routeIs('docs.recurring')" wire:navigate>{{ __('Recurring') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.sales-receipts')" :current="request()->routeIs('docs.sales-receipts')" wire:navigate>{{ __('Sales receipts') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.customer-portal')" :current="request()->routeIs('docs.customer-portal')" wire:navigate>{{ __('Customer portal') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.vendors')" :current="request()->routeIs('docs.vendors')" wire:navigate>{{ __('Vendors') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.purchase-orders')" :current="request()->routeIs('docs.purchase-orders')" wire:navigate>{{ __('Purchase orders') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.employees')" :current="request()->routeIs('docs.employees')" wire:navigate>{{ __('Employees') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.payroll')" :current="request()->routeIs('docs.payroll')" wire:navigate>{{ __('Payroll') }}</flux:navlist.item>
            </flux:navlist.group>

            <flux:navlist.group :heading="__('Books')" class="mt-4">
                <flux:navlist.item :href="route('docs.accounting')" :current="request()->routeIs('docs.accounting')" wire:navigate>{{ __('Accounting') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.banking')" :current="request()->routeIs('docs.banking')" wire:navigate>{{ __('Banking') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.inventory')" :current="request()->routeIs('docs.inventory')" wire:navigate>{{ __('Inventory') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.fixed-assets')" :current="request()->routeIs('docs.fixed-assets')" wire:navigate>{{ __('Fixed assets') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.multi-currency')" :current="request()->routeIs('docs.multi-currency')" wire:navigate>{{ __('Multi-currency') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.reports')" :current="request()->routeIs('docs.reports')" wire:navigate>{{ __('Reports') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.budgets')" :current="request()->routeIs('docs.budgets')" wire:navigate>{{ __('Budgets') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.fundraising')" :current="request()->routeIs('docs.fundraising')" wire:navigate>{{ __('Fundraising') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.tax-returns')" :current="request()->routeIs('docs.tax-returns')" wire:navigate>{{ __('Tax returns') }}</flux:navlist.item>
            </flux:navlist.group>

            <flux:navlist.group :heading="__('Files')" class="mt-4">
                <flux:navlist.item :href="route('docs.documents')" :current="request()->routeIs('docs.documents')" wire:navigate>{{ __('Documents') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.inbox')" :current="request()->routeIs('docs.inbox')" wire:navigate>{{ __('Inbox') }}</flux:navlist.item>
            </flux:navlist.group>

            <flux:navlist.group :heading="__('Admin')" class="mt-4">
                <flux:navlist.item :href="route('docs.lists')" :current="request()->routeIs('docs.lists')" wire:navigate>{{ __('Lists') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.settings')" :current="request()->routeIs('docs.settings')" wire:navigate>{{ __('Settings') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.migration')" :current="request()->routeIs('docs.migration')" wire:navigate>{{ __('Import from QuickBooks') }}</flux:navlist.item>
                <flux:navlist.item :href="route('docs.api')" :current="request()->routeIs('docs.api')" wire:navigate>{{ __('API') }}</flux:navlist.item>
            </flux:navlist.group>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading size="xl" level="1">{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <flux:separator class="my-6" variant="subtle" />

        <div class="prose prose-zinc dark:prose-invert max-w-3xl space-y-4">
            {{ $slot }}
        </div>

        <flux:separator class="mt-10 mb-4" variant="subtle" />
        <x-app-footer class="text-xs" />
    </div>
</div>
