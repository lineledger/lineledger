<?php

use App\Models\Company;
use App\Models\NavPreference;
use App\Support\Navigation\SidebarNavCatalog;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sidebar settings')] class extends Component
{
    public ?Company $company = null;

    /**
     * Checkbox state keyed by a dot-free form of each catalog key (dots are
     * swapped for "__" so Livewire doesn't treat them as nested array access).
     * true = the link/group is shown.
     *
     * @var array<string, bool>
     */
    public array $visible = [];

    public function mount(): void
    {
        $this->company = app()->bound('current_company')
            ? app('current_company')
            : Auth::user()?->currentCompany;

        if ($this->company === null) {
            return;
        }

        $hidden = $this->hiddenKeys();

        foreach (array_keys(SidebarNavCatalog::flattenKeys($this->company, Auth::user())) as $key) {
            $this->visible[self::safe($key)] = ! in_array($key, $hidden, true);
        }
    }

    /**
     * The navigation groups available to the user, for rendering the toggles.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function groups(): array
    {
        if ($this->company === null) {
            return [];
        }

        return SidebarNavCatalog::forUser($this->company, Auth::user());
    }

    public function save(): void
    {
        if ($this->company === null) {
            return;
        }

        $catalogKeys = array_keys(SidebarNavCatalog::flattenKeys($this->company, Auth::user()));

        $hidden = array_values(array_filter(
            $catalogKeys,
            fn (string $key): bool => ($this->visible[self::safe($key)] ?? true) === false,
        ));

        // Drop preferences for keys the user has re-enabled, but only within the
        // set of currently-available keys so prefs for hidden features survive.
        NavPreference::query()
            ->where('company_id', $this->company->id)
            ->where('user_id', Auth::id())
            ->whereIn('item_key', $catalogKeys)
            ->whereNotIn('item_key', $hidden ?: ['__none__'])
            ->delete();

        $existing = NavPreference::query()
            ->where('company_id', $this->company->id)
            ->where('user_id', Auth::id())
            ->whereIn('item_key', $hidden ?: ['__none__'])
            ->pluck('item_key')
            ->all();

        foreach (array_diff($hidden, $existing) as $key) {
            NavPreference::create([
                'company_id' => $this->company->id,
                'user_id' => Auth::id(),
                'item_key' => $key,
            ]);
        }

        $this->dispatch('sidebar-nav-updated');

        Flux::toast(variant: 'success', text: __('Sidebar updated.'));
    }

    public function resetToDefaults(): void
    {
        if ($this->company === null) {
            return;
        }

        NavPreference::query()
            ->where('company_id', $this->company->id)
            ->where('user_id', Auth::id())
            ->delete();

        foreach (array_keys($this->visible) as $safeKey) {
            $this->visible[$safeKey] = true;
        }

        $this->dispatch('sidebar-nav-updated');

        Flux::toast(variant: 'success', text: __('Sidebar reset to defaults.'));
    }

    /**
     * The user's hidden catalog keys for the current company.
     *
     * @return list<string>
     */
    protected function hiddenKeys(): array
    {
        return NavPreference::query()
            ->where('company_id', $this->company->id)
            ->where('user_id', Auth::id())
            ->pluck('item_key')
            ->all();
    }

    /**
     * Dot-free form of a catalog key for safe use as a Livewire model path.
     */
    protected static function safe(string $key): string
    {
        return str_replace('.', '__', $key);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Sidebar settings') }}</flux:heading>

    <x-pages::settings.layout
        :heading="__('Sidebar')"
        :subheading="__('Choose which links and sections appear in your sidebar. Only affects you.')"
        contentClass="max-w-xl"
    >
        @if ($this->company === null)
            <flux:text class="my-6">{{ __('Select a company to customize its sidebar.') }}</flux:text>
        @else
            <form wire:submit="save" class="my-6 w-full space-y-8" data-test="sidebar-settings-form">
                @foreach ($this->groups as $group)
                    <div class="space-y-3" wire:key="navgroup-{{ $group['key'] }}">
                        <flux:switch
                            wire:model="visible.{{ str_replace('.', '__', $group['key']) }}"
                            :label="$group['label']"
                            data-test="nav-group-{{ $group['key'] }}"
                        />

                        <div class="ms-6 space-y-2 border-s border-border ps-4">
                            @foreach ($group['items'] as $item)
                                <flux:switch
                                    wire:model="visible.{{ str_replace('.', '__', $item['key']) }}"
                                    :label="$item['label']"
                                    wire:key="navitem-{{ $item['key'] }}"
                                    data-test="nav-item-{{ $item['key'] }}"
                                />
                            @endforeach
                        </div>
                    </div>
                @endforeach

                <flux:text class="text-sm">
                    {{ __('Turning off a whole section hides it and all its links, even if individual links are still on.') }}
                </flux:text>

                <div class="flex items-center gap-4">
                    <flux:button variant="primary" type="submit" data-test="save-sidebar-button">
                        {{ __('Save') }}
                    </flux:button>

                    <flux:button
                        variant="ghost"
                        type="button"
                        wire:click="resetToDefaults"
                        wire:confirm="{{ __('Show all sidebar links again?') }}"
                        data-test="reset-sidebar-button"
                    >
                        {{ __('Reset to defaults') }}
                    </flux:button>
                </div>
            </form>
        @endif
    </x-pages::settings.layout>
</section>
