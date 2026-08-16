<?php

use App\Actions\MasterData\SaveLocation;
use App\Models\Company;
use App\Models\Location;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Locations')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_name', 'f_is_active']);
        $this->f_is_active = true;
        Flux::modal('locations-form')->show();
    }

    public function openEdit(int $id): void
    {
        $l = Location::findOrFail($id);
        $this->editingId = $l->id;
        $this->f_name = $l->name;
        $this->f_is_active = $l->is_active;
        Flux::modal('locations-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? Location::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveLocation::class)->handle([
            'name' => $validated['f_name'],
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('locations-form')->close();
        Flux::toast(variant: 'success', text: __('Location saved.'));
    }

    #[Computed]
    public function locations()
    {
        return Location::query()->orderBy('name')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Locations')" :subheading="__('A reporting dimension you can tag transaction lines with, then filter reports by. QuickBooks calls these Locations.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-location-button">{{ __('New location') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->locations as $l)
                        <tr data-test="location-row" class="@if(! $l->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $l->name }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $l->id }})" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="2" class="px-4 py-6 text-center text-muted-foreground">{{ __('No locations yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="locations-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit location') : __('New location') }}</flux:heading>
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="location-name" />
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="location-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
