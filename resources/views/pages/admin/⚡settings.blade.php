<?php

use App\Enums\Section;
use App\Support\SiteSettings;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Site Admin — Feature toggles')] class extends Component {
    public bool $registrationsEnabled = true;

    public bool $maintenanceMode = false;

    /** @var array<string, bool> section value => enabled */
    public array $sectionEnabled = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->site_admin, 404);

        $this->registrationsEnabled = SiteSettings::registrationsEnabled();
        $this->maintenanceMode = SiteSettings::maintenanceMode();

        foreach ($this->toggleableSections() as $section) {
            $this->sectionEnabled[$section->value] = SiteSettings::sectionEnabled($section);
        }
    }

    public function updatedRegistrationsEnabled(bool $value): void
    {
        SiteSettings::set('registrations_enabled', $value);
        $this->saved();
    }

    public function updatedMaintenanceMode(bool $value): void
    {
        SiteSettings::set('maintenance_mode', $value);
        $this->saved();
    }

    public function updatedSectionEnabled(): void
    {
        $disabled = [];

        foreach ($this->sectionEnabled as $value => $enabled) {
            if (! $enabled) {
                $disabled[] = $value;
            }
        }

        SiteSettings::set('disabled_sections', $disabled);
        $this->saved();
    }

    /**
     * Every section the admin may switch off platform-wide. Settings is omitted
     * deliberately — disabling it would lock everyone out of their own settings.
     *
     * @return list<Section>
     */
    public function toggleableSections(): array
    {
        return array_values(array_filter(
            Section::cases(),
            fn (Section $section): bool => $section !== Section::Settings,
        ));
    }

    private function saved(): void
    {
        Flux::toast(variant: 'success', text: __('Saved.'));
    }
}; ?>

<x-pages::admin.layout
    :heading="__('Feature toggles')"
    :subheading="__('Site-wide kill switches. Changes take effect immediately for everyone.')"
>
    <div class="space-y-8">
        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Access') }}</flux:heading>

            <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-4">
                <div class="min-w-0">
                    <flux:text class="font-medium">{{ __('New registrations') }}</flux:text>
                    <flux:text class="mt-1 text-sm text-muted-foreground">{{ __('Allow new users to create an account. When off, the sign-up page is closed.') }}</flux:text>
                </div>
                <flux:switch wire:model.live="registrationsEnabled" data-test="toggle-registrations" />
            </div>

            <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-4">
                <div class="min-w-0">
                    <flux:text class="font-medium">{{ __('Maintenance mode') }}</flux:text>
                    <flux:text class="mt-1 text-sm text-muted-foreground">{{ __('Show a maintenance page to everyone except site admins. Use to take the app offline safely.') }}</flux:text>
                </div>
                <flux:switch wire:model.live="maintenanceMode" data-test="toggle-maintenance" />
            </div>
        </div>

        <flux:separator variant="subtle" />

        <div class="space-y-3">
            <flux:heading size="sm">{{ __('Main sections') }}</flux:heading>
            <flux:text class="text-sm text-muted-foreground">
                {{ __('Turn a section off to hide it from the sidebar and block its pages across every company.') }}
            </flux:text>

            @foreach ($this->toggleableSections() as $section)
                <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border p-4" wire:key="section-{{ $section->value }}">
                    <div class="min-w-0">
                        <flux:text class="font-medium">{{ $section->label() }}</flux:text>
                        <flux:text class="mt-1 text-sm text-muted-foreground">{{ $section->description() }}</flux:text>
                    </div>
                    <flux:switch
                        wire:model.live="sectionEnabled.{{ $section->value }}"
                        data-test="toggle-section-{{ $section->value }}"
                    />
                </div>
            @endforeach
        </div>
    </div>
</x-pages::admin.layout>
