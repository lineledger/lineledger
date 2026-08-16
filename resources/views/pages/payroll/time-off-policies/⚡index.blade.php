<?php

use App\Actions\Payroll\SaveTimeOffPolicy;
use App\Enums\TimeOffAccrualMethod;
use App\Enums\TimeOffCategory;
use App\Enums\TimeOffUnit;
use App\Models\Company;
use App\Models\TimeOffPolicy;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Time-off policies')] class extends Component {
    public Company $company;

    public ?int $editingId = null;

    public string $f_name = '';

    public string $f_code = '';

    public string $f_category = 'sick';

    public string $f_unit = 'hours';

    public string $f_accrual_method = 'per_pay_period';

    public string $f_rate = '';

    public string $f_annual_cap = '';

    public string $f_carryover = '';

    public bool $f_paid = true;

    public bool $f_is_default = false;

    public bool $f_is_active = true;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        Flux::modal('policy-form')->show();
    }

    public function openEdit(int $id): void
    {
        $policy = TimeOffPolicy::findOrFail($id);

        $this->editingId = $policy->id;
        $this->f_name = $policy->name;
        $this->f_code = $policy->code;
        $this->f_category = $policy->category->value;
        $this->f_unit = $policy->unit->value;
        $this->f_accrual_method = $policy->accrual_method->value;
        $this->f_paid = $policy->paid;
        $this->f_is_default = $policy->is_default;
        $this->f_is_active = $policy->is_active;

        $this->f_rate = $this->rateToInput($policy);
        $this->f_annual_cap = $policy->isDollarUnit()
            ? ($policy->annual_cap_cents !== null ? (string) ((int) $policy->annual_cap_cents / 100) : '')
            : ($policy->annual_cap_hours !== null ? (string) (float) $policy->annual_cap_hours : '');
        $this->f_carryover = $policy->isDollarUnit()
            ? ($policy->carryover_max_cents !== null ? (string) ((int) $policy->carryover_max_cents / 100) : '')
            : ($policy->carryover_max_hours !== null ? (string) (float) $policy->carryover_max_hours : '');

        Flux::modal('policy-form')->show();
    }

    public function save(SaveTimeOffPolicy $action): void
    {
        $validated = $this->validate([
            'f_name' => ['required', 'string', 'max:255'],
            'f_code' => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9_]*$/'],
            'f_category' => ['required', 'in:'.implode(',', array_column(TimeOffCategory::cases(), 'value'))],
            'f_unit' => ['required', 'in:'.implode(',', array_column(TimeOffUnit::cases(), 'value'))],
            'f_accrual_method' => ['required', 'in:'.implode(',', array_column(TimeOffAccrualMethod::cases(), 'value'))],
            'f_rate' => ['nullable', 'numeric', 'min:0'],
            'f_annual_cap' => ['nullable', 'numeric', 'min:0'],
            'f_carryover' => ['nullable', 'numeric', 'min:0'],
            'f_paid' => ['boolean'],
            'f_is_default' => ['boolean'],
            'f_is_active' => ['boolean'],
        ]);

        $isDollar = $validated['f_unit'] === 'dollars';
        $rate = (float) ($validated['f_rate'] ?: 0);

        // Resolve the single rate input into the stored hours / basis-point field.
        $rateHours = 0.0;
        $rateBp = 0;
        if ($isDollar) {
            $rateBp = (int) round($rate * 100);                                   // percent of earnings
        } elseif ($validated['f_accrual_method'] === 'per_hour_worked') {
            $rateBp = (int) round($rate * 10000);                                 // hours accrued per hour worked
        } else {
            $rateHours = $rate;                                                   // flat hours per period / per year
        }

        $cap = $validated['f_annual_cap'] !== null && $validated['f_annual_cap'] !== '' ? (float) $validated['f_annual_cap'] : null;
        $carry = $validated['f_carryover'] !== null && $validated['f_carryover'] !== '' ? (float) $validated['f_carryover'] : null;

        $action->handle([
            'name' => $validated['f_name'],
            'code' => $validated['f_code'] ?: null,
            'category' => $validated['f_category'],
            'unit' => $validated['f_unit'],
            'accrual_method' => $validated['f_accrual_method'],
            'rate_hours' => $rateHours,
            'rate_bp' => $rateBp,
            'annual_cap_hours' => $isDollar ? null : $cap,
            'annual_cap_cents' => $isDollar && $cap !== null ? (int) round($cap * 100) : null,
            'carryover_max_hours' => $isDollar ? null : $carry,
            'carryover_max_cents' => $isDollar && $carry !== null ? (int) round($carry * 100) : null,
            'paid' => $validated['f_paid'],
            'is_default' => $validated['f_is_default'],
            'is_active' => $validated['f_is_active'],
        ], $this->editingId ? TimeOffPolicy::findOrFail($this->editingId) : null);

        Flux::modal('policy-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Time-off policy saved.'));
    }

    #[Computed]
    public function policies()
    {
        return TimeOffPolicy::query()->orderBy('name')->get();
    }

    /** The contextual label for the single rate input, given the current unit/method. */
    #[Computed]
    public function rateLabel(): string
    {
        if ($this->f_accrual_method === 'manual') {
            return '';
        }

        return match (true) {
            $this->f_unit === 'dollars' => __('Percent of earnings (%)'),
            $this->f_accrual_method === 'per_hour_worked' => __('Hours accrued per hour worked'),
            $this->f_accrual_method === 'per_pay_period' => __('Hours per pay period'),
            default => __('Hours per year'),
        };
    }

    #[Computed]
    public function unitLabel(): string
    {
        return $this->f_unit === 'dollars' ? __('$') : __('hours');
    }

    private function rateToInput(TimeOffPolicy $policy): string
    {
        if ($policy->isDollarUnit()) {
            return (string) ((int) $policy->rate_bp / 100);
        }

        if ($policy->accrual_method === TimeOffAccrualMethod::PerHourWorked) {
            return (string) ((int) $policy->rate_bp / 10000);
        }

        return (string) (float) $policy->rate_hours;
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'f_name', 'f_code', 'f_rate', 'f_annual_cap', 'f_carryover']);
        $this->f_category = 'sick';
        $this->f_unit = 'hours';
        $this->f_accrual_method = 'per_pay_period';
        $this->f_paid = true;
        $this->f_is_default = false;
        $this->f_is_active = true;
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Time-off policies') }}</flux:heading>
            <flux:subheading>{{ __('Vacation, sick and personal-time presets — how each accrues, its annual cap and carryover. Assign them to employees on the employee setup page.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-policy-button">
            {{ __('New policy') }}
        </flux:button>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Category') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Accrual') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Paid') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->policies as $policy)
                    <tr data-test="policy-row" class="@if (! $policy->is_active) opacity-50 @endif">
                        <td class="px-4 py-2 font-medium">{{ $policy->name }}</td>
                        <td class="px-4 py-2">{{ $policy->category->label() }}</td>
                        <td class="px-4 py-2 text-muted-foreground">{{ $policy->accrual_method->label() }}</td>
                        <td class="px-4 py-2">{{ $policy->paid ? __('Yes') : __('No') }}</td>
                        <td class="px-4 py-2 text-right">
                            <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $policy->id }})" />
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-muted-foreground">{{ __('No time-off policies yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="policy-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit time-off policy') : __('New time-off policy') }}</flux:heading>

            <flux:input wire:model="f_name" :label="__('Name')" required data-test="policy-name" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="f_category" :label="__('Category')">
                    @foreach (\App\Enums\TimeOffCategory::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="f_unit" :label="__('Unit')">
                    @foreach (\App\Enums\TimeOffUnit::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <flux:select wire:model.live="f_accrual_method" :label="__('Accrual method')">
                @foreach (\App\Enums\TimeOffAccrualMethod::options() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($this->rateLabel !== '')
                <flux:input type="number" step="0.01" wire:model="f_rate" :label="$this->rateLabel" inputmode="decimal" />
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input type="number" step="0.01" wire:model="f_annual_cap" :label="__('Annual cap (:unit)', ['unit' => $this->unitLabel])" inputmode="decimal" :description="__('Max accrued per year. Blank = no cap.')" />
                <flux:input type="number" step="0.01" wire:model="f_carryover" :label="__('Carryover max (:unit)', ['unit' => $this->unitLabel])" inputmode="decimal" :description="__('Max carried into the next year.')" />
            </div>

            <flux:input wire:model="f_code" :label="__('Code')" :description="__('Used to match the balance and the time-off-taken earning. Auto-set from the name if blank.')" />

            <div class="flex flex-wrap gap-6">
                <flux:switch wire:model="f_paid" :label="__('Paid time off')" />
                <flux:switch wire:model="f_is_default" :label="__('Use for new employees')" />
                <flux:switch wire:model="f_is_active" :label="__('Active')" />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="policy-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
