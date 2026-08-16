@props(['heading' => '', 'subheading' => '', 'contentClass' => 'max-w-4xl'])

@php($openTicketCount = \App\Models\SupportTicket::query()->where('status', \App\Enums\SupportTicketStatus::Open)->count())

<section class="w-full">
    <div class="relative mb-6 w-full">
        <flux:heading size="xl" level="1">{{ __('Site Admin') }}</flux:heading>
        <flux:subheading size="lg" class="mb-6">{{ __('Platform-wide controls and directory. Visible only to site admins.') }}</flux:subheading>
        <flux:separator variant="subtle" />
    </div>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[220px]">
            <flux:navlist aria-label="{{ __('Site admin') }}">
                <flux:navlist.item icon="home" :href="route('admin.dashboard')" :current="request()->routeIs('admin.dashboard')" wire:navigate>{{ __('Overview') }}</flux:navlist.item>
                <flux:navlist.item icon="shield-exclamation" :href="route('admin.security')" :current="request()->routeIs('admin.security')" wire:navigate>{{ __('Security') }}</flux:navlist.item>
                <flux:navlist.item icon="adjustments-horizontal" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>{{ __('Feature toggles') }}</flux:navlist.item>
                <flux:navlist.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                <flux:navlist.item icon="lifebuoy" :href="route('admin.support')" :current="request()->routeIs('admin.support*')" :badge="$openTicketCount > 0 ? $openTicketCount : null" badge-color="amber" wire:navigate>{{ __('Support') }}</flux:navlist.item>
                <flux:navlist.item icon="building-office-2" :href="route('admin.companies')" :current="request()->routeIs('admin.companies')" wire:navigate>{{ __('Companies') }}</flux:navlist.item>
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
</section>
