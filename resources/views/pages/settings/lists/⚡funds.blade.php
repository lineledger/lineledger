<?php

use App\Actions\MasterData\SaveFund;
use App\Enums\FundType;
use App\Models\Company;
use App\Models\Fund;
use Flux\Flux;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Funds')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public string $f_fund_type = 'restricted';

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'f_name', 'f_fund_type', 'f_is_active']);
        $this->f_fund_type = FundType::Restricted->value;
        $this->f_is_active = true;
        Flux::modal('funds-form')->show();
    }

    public function openEdit(int $id): void
    {
        $f = Fund::findOrFail($id);
        $this->editingId = $f->id;
        $this->f_name = $f->name;
        $this->f_fund_type = $f->fund_type->value;
        $this->f_is_active = $f->is_active;
        Flux::modal('funds-form')->show();
    }

    public function save(): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_fund_type' => ['required', Rule::enum(FundType::class)],
            'f_is_active' => ['boolean'],
        ]);

        $editing = $this->editingId ? Fund::findOrFail($this->editingId) : null;
        abort_if($editing !== null && $editing->company_id !== $this->company->id, 403);

        app(SaveFund::class)->handle([
            'name' => $validated['f_name'],
            'fund_type' => $validated['f_fund_type'],
            'is_active' => $validated['f_is_active'],
        ], $editing);

        Flux::modal('funds-form')->close();
        Flux::toast(variant: 'success', text: __('Fund saved.'));
    }

    #[Computed]
    public function funds()
    {
        return Fund::query()->orderByDesc('is_default')->orderBy('name')->get();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Funds')" :subheading="__('Track restricted contributions by fund (ASNPO restricted fund method). Tag transaction lines with a fund, then report per fund.')">
        <div class="mb-4 flex justify-end">
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-fund-button">{{ __('New fund') }}</flux:button>
        </div>

        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->funds as $f)
                        <tr data-test="fund-row" class="@if (! $f->is_active) opacity-50 @endif">
                            <td class="px-4 py-2">
                                {{ $f->name }}
                                @if ($f->is_default)
                                    <span class="ml-1 text-xs text-muted-foreground">({{ __('default') }})</span>
                                @endif
                            </td>
                            <td class="px-4 py-2">{{ $f->fund_type->label() }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $f->id }})" />
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-4 py-6 text-center text-muted-foreground">{{ __('No funds yet.') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-pages::settings.layout>

    <flux:modal name="funds-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit fund') : __('New fund') }}</flux:heading>
            <flux:input wire:model="f_name" :label="__('Name')" required data-test="fund-name" />
            <flux:select wire:model="f_fund_type" :label="__('Type')" data-test="fund-type">
                @foreach (FundType::options() as $option)
                    <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:switch wire:model="f_is_active" :label="__('Active')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="filled">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="fund-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
