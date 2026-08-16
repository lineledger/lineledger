<?php

use App\Models\Company;
use App\Models\Contact;
use App\Support\Money;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Employee payroll setup')] class extends Component {
    use WithPagination;
    public Company $company;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $onlyUnenrolled = false;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);
    }

    #[Computed]
    public function employees()
    {
        return Contact::query()
            ->where('is_employee', true)
            ->where('is_active', true)
            ->with('payrollProfile')
            ->when($this->onlyUnenrolled, fn ($q) => $q->whereDoesntHave('payrollProfile'))
            ->when($this->search !== '', fn ($q) => $q->where('display_name', 'like', '%'.$this->search.'%'))
            ->orderBy('display_name')
            ->paginate(25);
    }

    public function moneyString(?int $cents): string
    {
        return $cents === null ? '—' : Money::fromCents((int) $cents)->format();
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Employee payroll setup') }}</flux:heading>
            <flux:subheading>{{ __('Enrol employees in payroll: province, pay rate, TD1 claim amounts and vacation policy.') }}</flux:subheading>
        </div>
        <flux:button variant="ghost" icon="users" :href="route('employees.index')" wire:navigate>
            {{ __('Manage employees') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:gap-4">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search employees…') }}" icon="magnifying-glass" class="sm:max-w-md" />
        <flux:switch wire:model.live="onlyUnenrolled" :label="__('Only not enrolled')" />
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Name') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Province') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Pay basis') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Rate') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->employees as $employee)
                    @php($profile = $employee->payrollProfile)
                    <tr data-test="payroll-employee-row">
                        <td class="px-4 py-2 font-medium">{{ $employee->display_name }}</td>
                        <td class="px-4 py-2">
                            @if ($profile)
                                <flux:badge size="sm" color="emerald">{{ __('Enrolled') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Not enrolled') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $profile?->province_of_employment ?? '—' }}</td>
                        <td class="px-4 py-2">{{ $profile?->pay_basis?->label() ?? '—' }}</td>
                        <td class="px-4 py-2 text-right font-mono">
                            {{ $profile ? ($profile->pay_basis->value === 'hourly' ? $this->moneyString($profile->hourly_rate_cents).'/hr' : $this->moneyString($profile->annual_salary_cents).'/yr') : '—' }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <flux:button size="sm" :variant="$profile ? 'ghost' : 'primary'" :icon="$profile ? 'pencil' : 'plus'" :href="route('payroll.employees.setup', $employee)" wire:navigate>
                                {{ $profile ? __('Edit') : __('Set up') }}
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No employees found.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $this->employees->links() }}</div>
</section>
