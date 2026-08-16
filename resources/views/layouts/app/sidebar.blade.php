<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
<body class="min-h-screen bg-background">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-sidebar-border bg-sidebar">
            <flux:sidebar.header>
                <livewire:company-switcher />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <livewire:global-search />

            <flux:sidebar.nav>
                <flux:sidebar.group class="grid">
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>
                </flux:sidebar.group>
                <livewire:sidebar-nav />
            </flux:sidebar.nav>

            <script nonce="{{ Vite::cspNonce() }}">
                (function () {
                    if (window._sidebarGroupsPersistBound) {
                        return;
                    }

                    window._sidebarGroupsPersistBound = true;

                    const cookieName = 'sidebar_groups';
                    const maxAge = 60 * 60 * 24 * 365;

                    function readCookie() {
                        const match = document.cookie
                            .split('; ')
                            .find((row) => row.startsWith(cookieName + '='));

                        if (! match) {
                            return new Set();
                        }

                        const raw = decodeURIComponent(match.slice(cookieName.length + 1));

                        return new Set(raw.split(',').filter(Boolean));
                    }

                    function writeCookie(set) {
                        const value = [...set].join(',');
                        document.cookie = cookieName + '=' + encodeURIComponent(value)
                            + '; path=/; max-age=' + maxAge + '; SameSite=Lax';
                    }

                    document.addEventListener('click', (event) => {
                        const disclosure = event.target.closest('ui-disclosure[data-sidebar-group]');

                        if (! disclosure) {
                            return;
                        }

                        const button = event.target.closest('button');

                        if (! button || button.parentElement !== disclosure) {
                            return;
                        }

                        const key = disclosure.dataset.sidebarGroup;
                        const opened = readCookie();
                        const isCurrentlyOpen = disclosure.hasAttribute('open');

                        if (isCurrentlyOpen) {
                            opened.delete(key);
                        } else {
                            opened.add(key);
                        }

                        writeCookie(opened);
                    }, true);
                })();
            </script>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" :show-company="false" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <a href="{{ route('dashboard') }}" wire:navigate class="ms-1 flex items-center">
                <x-app-logo />
            </a>

            <flux:spacer />

            @php($supportUnread = auth()->user()->unreadSupportRepliesCount())
            <flux:dropdown position="top" align="end">
                <div class="relative">
                    <flux:profile
                        :initials="auth()->user()->initials()"
                        icon-trailing="chevron-down"
                    />
                    @if ($supportUnread)
                        <span class="absolute end-6 top-1 size-2.5 rounded-full bg-sky-500 ring-2 ring-background" data-test="support-unread-indicator-mobile"></span>
                    @endif
                </div>

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
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
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('docs.getting-started')" icon="book-open" wire:navigate>
                            {{ __('Documentation') }}
                        </flux:menu.item>
                        <flux:menu.item :href="route('support.index')" icon="lifebuoy" wire:navigate data-test="support-link-mobile">
                            {{ __('Support') }}
                            @if ($supportUnread)
                                <flux:badge size="sm" color="sky" class="ms-auto">{{ $supportUnread }}</flux:badge>
                            @endif
                        </flux:menu.item>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                        @can('access-site-admin')
                            <flux:menu.item :href="route('admin.dashboard')" icon="shield-check" wire:navigate>
                                {{ __('Site Admin') }}
                            </flux:menu.item>
                        @endcan
                    </flux:menu.radio.group>

                    <flux:menu.separator />

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
                </flux:menu>
            </flux:dropdown>
        </flux:header>


        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts(['nonce' => Vite::cspNonce()])
    </body>
</html>
