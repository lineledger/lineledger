<?php

use App\Actions\Payroll\SavePayrollSchedule;
use App\Enums\PayFrequency;
use App\Models\Company;
use App\Models\PayrollSchedule;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pay schedules')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public string $f_frequency = 'biweekly';

    public string $f_anchor_period_end_date = '';

    public int $f_default_pay_offset_days = 5;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);

        $this->f_anchor_period_end_date = $company->currentDateTime()->toDateString();
    }

    public function openCreate(): void
    {
        $this->resetForm();
        Flux::modal('schedule-form')->show();
    }

    public function openEdit(int $id): void
    {
        $schedule = PayrollSchedule::findOrFail($id);

        $this->editingId = $schedule->id;
        $this->f_name = $schedule->name;
        $this->f_frequency = $schedule->frequency->value;
        $this->f_anchor_period_end_date = $schedule->anchor_period_end_date->toDateString();
        $this->f_default_pay_offset_days = $schedule->default_pay_offset_days;
        $this->f_is_active = $schedule->is_active;

        Flux::modal('schedule-form')->show();
    }

    public function save(SavePayrollSchedule $action): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_frequency' => ['required', 'in:'.implode(',', array_column(PayFrequency::cases(), 'value'))],
            'f_anchor_period_end_date' => ['required', 'date'],
            'f_default_pay_offset_days' => ['integer', 'min:0', 'max:60'],
            'f_is_active' => ['boolean'],
        ]);

        $schedule = $this->editingId ? PayrollSchedule::findOrFail($this->editingId) : null;

        $action->handle([
            'name' => $validated['f_name'],
            'frequency' => $validated['f_frequency'],
            'anchor_period_end_date' => $validated['f_anchor_period_end_date'],
            'default_pay_offset_days' => $validated['f_default_pay_offset_days'],
            'is_active' => $validated['f_is_active'],
        ], $schedule);

        Flux::modal('schedule-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Pay schedule saved.'));
    }

    #[Computed]
    public function schedules()
    {
        return PayrollSchedule::query()->orderBy('name')->get();
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function frequencyOptions(): array
    {
        return collect(PayFrequency::cases())
            ->mapWithKeys(fn (PayFrequency $f) => [$f->value => $f->label().' ('.$f->periodsPerYear().'/yr)'])
            ->all();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'f_name']);
        $this->f_frequency = 'biweekly';
        $this->f_default_pay_offset_days = 5;
        $this->f_is_active = true;
        $this->f_anchor_period_end_date = $this->company->currentDateTime()->toDateString();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Pay schedules') }}</flux:heading>
            <flux:subheading>{{ __('How often you pay employees. Drives the tax annualization for each pay run.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-schedule-button">
            {{ __('New schedule') }}
        </flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Frequency') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Periods / yr') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Anchor period end') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->schedules as $schedule)
                    <tr data-test="schedule-row" class="@if (! $schedule->is_active) opacity-50 @endif">
                        <td class="px-4 py-2 font-medium">{{ $schedule->name }}</td>
                        <td class="px-4 py-2">{{ $schedule->frequency->label() }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ $schedule->periods_per_year }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $schedule->anchor_period_end_date->toDateString() }}</td>
                        <td class="px-4 py-2 text-right">
                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $schedule->id }})" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No pay schedules yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="schedule-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit pay schedule') : __('New pay schedule') }}</flux:heading>

            <flux:input wire:model="f_name" :label="__('Name')" required data-test="schedule-name" />

            <flux:select wire:model="f_frequency" :label="__('Frequency')">
                @foreach ($this->frequencyOptions as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input type="date" wire:model="f_anchor_period_end_date" :label="__('Anchor period end date')" :description="__('The end date of a reference pay period; future periods are projected from here.')" required />

            <flux:input type="number" wire:model="f_default_pay_offset_days" :label="__('Pay date offset (days after period end)')" min="0" max="60" />

            <flux:switch wire:model="f_is_active" :label="__('Active')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="schedule-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
