<?php

use App\Actions\MasterData\SavePaymentTerm;
use App\Models\Company;
use App\Models\PaymentTerm;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payment terms')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public string $f_name = '';

    public int $f_days = 30;

    public bool $f_is_active = true;

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_name', 'f_days']);
        $this->f_days = 30;
        $this->f_is_active = true;
        Flux::modal('terms-form')->show();
    }

    public function openEdit(int $id): void
    {
        $t = PaymentTerm::findOrFail($id);
        $this->editingId = $t->id;
        $this->f_name = $t->name;
        $this->f_days = (int) $t->days;
        $this->f_is_active = $t->is_active;
        Flux::modal('terms-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_days' => ['required', 'integer', 'min:0', 'max:365'],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? PaymentTerm::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SavePaymentTerm::class)->handle([
            'name' => $validated['f_name'],
            'days' => $validated['f_days'],
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('terms-form')->close();
        Flux::toast(variant: 'success', text: __('Term saved.'));
    }

    #[Computed]
    public function terms()
    {
        return PaymentTerm::query()->orderBy('days')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Payment terms')" :subheading="__('Used to compute invoice and bill due dates.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-term-button">{{ __('New term') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Days') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($this->terms as $t)
                        <tr data-test="term-row" class="@if(! $t->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">{{ $t->name }}</td>
                            <td class="px-4 py-2 text-right">{{ $t->days }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $t->id }})" />
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="terms-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit term') : __('New term') }}</flux:heading>
            <x-api-id-hint :id="$editingId" field="terms_id" />
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="term-name" />
            <flux:input type="number" wire:model="f_days" :label="__('Days')" min="0" max="365" required data-test="term-days" />
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="term-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
