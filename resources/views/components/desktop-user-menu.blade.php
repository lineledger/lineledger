@props(['showCompany' => true])

@php($supportUnread = auth()->user()->unreadSupportRepliesCount())

<flux:dropdown position="bottom" align="start">
    <button type="button" class="group flex w-full items-center rounded-lg p-1 hover:bg-muted" data-test="sidebar-menu-button">
        <div class="relative">
            <flux:avatar :initials="auth()->user()->initials()" size="sm" />
            @if ($supportUnread)
                <span class="absolute -end-0.5 -top-0.5 size-2.5 rounded-full bg-sky-500 ring-2 ring-sidebar" data-test="support-unread-indicator"></span>
            @endif
        </div>
        <div class="in-data-flux-sidebar-collapsed-desktop:hidden mx-2 grid flex-1 text-start text-sm leading-tight">
            <span class="truncate font-medium text-muted-foreground group-hover:text-foreground">{{ auth()->user()->name }}</span>
            @if($showCompany && auth()->user()->currentCompany)
                <span class="truncate text-xs text-muted-foreground">{{ auth()->user()->currentCompany->brandDisplayName() }}</span>
            @endif
        </div>
        <flux:icon name="chevrons-up-down" variant="micro" class="in-data-flux-sidebar-collapsed-desktop:hidden ms-auto size-4 text-muted-foreground group-hover:text-foreground" />
    </button>

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            <flux:menu.item :href="route('docs.getting-started')" icon="book-open" wire:navigate>
                {{ __('Documentation') }}
            </flux:menu.item>
            <flux:menu.item :href="route('support.index')" icon="lifebuoy" wire:navigate data-test="support-link">
                {{ __('Support') }}
                @if ($supportUnread)
                    <flux:badge size="sm" color="sky" class="ms-auto">{{ $supportUnread }}</flux:badge>
                @endif
            </flux:menu.item>
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('Settings') }}
            </flux:menu.item>
            @can('access-site-admin')
                <flux:menu.item :href="route('admin.dashboard')" icon="shield-check" wire:navigate data-test="site-admin-link">
                    {{ __('Site Admin') }}
                </flux:menu.item>
            @endcan
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('Log out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
