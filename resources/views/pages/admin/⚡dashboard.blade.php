<?php

use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use App\Support\SiteSettings;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Site Admin')] class extends Component {
    public function mount(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'users' => User::query()->count(),
            'site_admins' => User::query()->where('site_admin', true)->count(),
            'companies' => Company::query()->count(),
            'deleted_companies' => Company::onlyTrashed()->count(),
        ];
    }

    #[Computed]
    public function registrationsEnabled(): bool
    {
        return SiteSettings::registrationsEnabled();
    }

    #[Computed]
    public function maintenanceMode(): bool
    {
        return SiteSettings::maintenanceMode();
    }

    #[Computed]
    public function disabledSections(): array
    {
        return collect(SiteSettings::disabledSections())
            ->map(fn (string $value) => Section::tryFrom($value)?->label())
            ->filter()
            ->values()
            ->all();
    }
}; ?>

<x-pages::admin.layout
    :heading="__('Overview')"
    :subheading="__('A snapshot of the platform.')"
>
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
        @foreach ([
            ['label' => __('Users'), 'value' => $this->stats['users']],
            ['label' => __('Site admins'), 'value' => $this->stats['site_admins']],
            ['label' => __('Companies'), 'value' => $this->stats['companies']],
            ['label' => __('Deleted companies'), 'value' => $this->stats['deleted_companies']],
        ] as $card)
            <div class="rounded-lg border border-border p-4">
                <flux:text class="text-xs text-muted-foreground">{{ $card['label'] }}</flux:text>
                <div class="mt-1 text-2xl font-semibold">{{ number_format($card['value']) }}</div>
            </div>
        @endforeach
    </div>

    <div class="mt-6 space-y-3">
        <flux:heading size="sm">{{ __('Site status') }}</flux:heading>

        <div class="flex flex-wrap items-center gap-2">
            <flux:badge :color="$this->maintenanceMode ? 'red' : 'green'">
                {{ $this->maintenanceMode ? __('Maintenance mode ON') : __('Live') }}
            </flux:badge>
            <flux:badge :color="$this->registrationsEnabled ? 'green' : 'zinc'">
                {{ $this->registrationsEnabled ? __('Registrations open') : __('Registrations closed') }}
            </flux:badge>
            @forelse ($this->disabledSections as $label)
                <flux:badge color="amber">{{ __(':section disabled', ['section' => $label]) }}</flux:badge>
            @empty
                <flux:badge color="zinc">{{ __('All sections enabled') }}</flux:badge>
            @endforelse
        </div>

        <flux:button variant="primary" icon="adjustments-horizontal" :href="route('admin.settings')" wire:navigate>
            {{ __('Manage feature toggles') }}
        </flux:button>
    </div>
</x-pages::admin.layout>
