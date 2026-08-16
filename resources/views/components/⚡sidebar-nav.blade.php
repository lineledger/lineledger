<?php

use App\Models\Company;
use App\Models\NavPreference;
use App\Support\Navigation\SidebarNavCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component
{
    public ?Company $company = null;

    /**
     * A view-only toggle that reveals the groups/links the user has hidden,
     * without touching their saved NavPreference hide list. Seeded from (and
     * persisted to) a cookie so it survives wire:navigate page swaps.
     */
    public bool $showAll = false;

    public function mount(): void
    {
        $this->showAll = request()->cookie('sidebar_show_all') === '1';

        if (app()->bound('current_company')) {
            $this->company = app('current_company');

            return;
        }

        $this->company = Auth::user()?->currentCompany;
    }

    /**
     * Flip the reveal-everything toggle and persist it to a cookie (queued onto
     * the Livewire response so it sticks across navigation), mirroring the
     * `sidebar_groups` expand/collapse cookie's ~1-year lifetime.
     */
    public function toggleShowAll(): void
    {
        $this->showAll = ! $this->showAll;

        Cookie::queue('sidebar_show_all', $this->showAll ? '1' : '0', 60 * 24 * 365);
    }

    /**
     * Re-read the user's preferences when they change them on the Sidebar
     * settings page so the nav updates live, without a full page reload.
     */
    #[On('sidebar-nav-updated')]
    public function refresh(): void
    {
        unset($this->groups, $this->hidden);
    }

    /**
     * The navigation groups available to the user.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function groups(): array
    {
        if ($this->company === null || Auth::user() === null) {
            return [];
        }

        return SidebarNavCatalog::forUser($this->company, Auth::user());
    }

    /**
     * The keys the user has hidden for the current company.
     *
     * @return list<string>
     */
    #[Computed]
    public function hidden(): array
    {
        if ($this->company === null) {
            return [];
        }

        return NavPreference::query()
            ->where('company_id', $this->company->id)
            ->where('user_id', Auth::id())
            ->pluck('item_key')
            ->all();
    }

    /**
     * The groups expanded via the persisted cookie. A brand-new org (or a fresh
     * browser) has no cookie yet, so the everyday groups — Banking, Revenues/Sales
     * and Purchases — start open and everything else stays collapsed. Once the user
     * toggles any group the cookie is written and their choice takes over (an
     * explicitly-empty cookie collapses all groups).
     *
     * @return list<string>
     */
    #[Computed]
    public function openGroups(): array
    {
        $cookie = request()->cookie('sidebar_groups');

        if ($cookie === null) {
            return ['banking', 'customers', 'vendors'];
        }

        return array_values(array_filter(explode(',', (string) $cookie)));
    }
}; ?>

<div class="contents">
    @foreach ($this->groups as $group)
        @php
            $visibleItems = (! $this->showAll && in_array($group['key'], $this->hidden, true))
                ? []
                : array_values(array_filter(
                    $group['items'],
                    fn ($item) => $this->showAll
                        || ($item['section'] ?? false)
                        || ! in_array($item['key'], $this->hidden, true),
                ));
        @endphp
        @if ($visibleItems !== [])
            <flux:sidebar.group expandable :expanded="in_array($group['key'], $this->openGroups, true)" :heading="$group['label']" class="grid" data-sidebar-group="{{ $group['key'] }}">
                @foreach ($visibleItems as $item)
                    @if ($item['section'] ?? false)
                        <div class="px-2 pt-3 pb-0.5 text-xs font-medium tracking-wide text-zinc-400 dark:text-zinc-500">{{ $item['label'] }}</div>
                    @else
                        <flux:sidebar.item :icon="$item['icon']" :href="$item['href']" :current="request()->routeIs(...$item['current'])" wire:navigate>
                            {{ $item['label'] }}
                        </flux:sidebar.item>
                    @endif
                @endforeach
                @if ($group['key'] === 'reports')
                    <livewire:report-favorites-nav />
                @endif
            </flux:sidebar.group>
        @endif
    @endforeach

    @if ($this->hidden !== [])
        <flux:sidebar.group class="grid">
            <flux:sidebar.item
                as="button"
                type="button"
                :icon="$this->showAll ? 'eye-slash' : 'eye'"
                wire:click="toggleShowAll"
                data-test="sidebar-show-all-toggle"
            >
                {{ $this->showAll ? __('Show fewer') : __('Show all sections') }}
            </flux:sidebar.item>
        </flux:sidebar.group>
    @endif
</div>
