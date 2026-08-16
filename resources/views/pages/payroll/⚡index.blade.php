<?php

use App\Models\Company;
use App\Models\Contact;
use App\Models\PayrollSchedule;
use App\Services\Payroll\BankedTimeAging;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Payroll')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);
    }

    #[Computed]
    public function employeeCount(): int
    {
        return Contact::query()->where('is_employee', true)->where('is_active', true)->count();
    }

    #[Computed]
    public function enrolledCount(): int
    {
        return Contact::query()->where('is_employee', true)->whereHas('payrollProfile')->count();
    }

    #[Computed]
    public function scheduleCount(): int
    {
        return PayrollSchedule::query()->where('is_active', true)->count();
    }

    public function hasPayRuns(): bool
    {
        return Route::has('pay-runs.index');
    }

    /**
     * Banked-overtime hours sitting past their province's take-or-pay-out
     * deadline (advisory — employment standards put a clock on banked time).
     *
     * @return list<array{contact_id: int, name: string, balance_hours: float, overdue_hours: float, oldest_date: ?string, deadline_days: int}>
     */
    #[Computed]
    public function bankedOverdue(): array
    {
        return app(BankedTimeAging::class)->overdue($this->company->currentDateTime()->toImmutable());
    }
}; ?>

<section class="w-full">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Payroll') }}</flux:heading>
        <flux:subheading>{{ __('Canadian payroll — pay employees, calculate CPP/EI/income tax, and prepare your PD7A remittance.') }}</flux:subheading>
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <a href="{{ route('payroll.employees.index') }}" wire:navigate class="rounded-lg border border-border p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <flux:icon.identification class="size-6 text-primary" />
                <flux:heading size="lg">{{ __('Employee setup') }}</flux:heading>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ __(':enrolled of :total employees enrolled in payroll.', ['enrolled' => $this->enrolledCount, 'total' => $this->employeeCount]) }}
            </p>
        </a>

        <a href="{{ route('payroll-schedules.index') }}" wire:navigate class="rounded-lg border border-border p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <flux:icon.clock class="size-6 text-primary" />
                <flux:heading size="lg">{{ __('Pay schedules') }}</flux:heading>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ trans_choice('{0}No schedules yet.|{1}:count active schedule.|[2,*]:count active schedules.', $this->scheduleCount, ['count' => $this->scheduleCount]) }}
            </p>
        </a>

        @if ($this->hasPayRuns())
            <a href="{{ route('pay-runs.index') }}" wire:navigate class="rounded-lg border border-border p-5 transition hover:border-primary">
                <div class="flex items-center gap-3">
                    <flux:icon.calendar-days class="size-6 text-primary" />
                    <flux:heading size="lg">{{ __('Pay runs') }}</flux:heading>
                </div>
                <p class="mt-2 text-sm text-muted-foreground">{{ __('Run payroll and write cheques.') }}</p>
            </a>
        @else
            <div class="rounded-lg border border-dashed border-border p-5 opacity-70">
                <div class="flex items-center gap-3">
                    <flux:icon.calendar-days class="size-6 text-muted-foreground" />
                    <flux:heading size="lg">{{ __('Pay runs') }}</flux:heading>
                </div>
                <p class="mt-2 text-sm text-muted-foreground">{{ __('Set up employees and a pay schedule to get started.') }}</p>
            </div>
        @endif

        <a href="{{ route('payroll.staff-calendar') }}" wire:navigate class="rounded-lg border border-border p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <flux:icon.calendar-days class="size-6 text-primary" />
                <flux:heading size="lg">{{ __('Staff calendar') }}</flux:heading>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">{{ __('View team availability and approved time off.') }}</p>
        </a>

        <a href="{{ route('payroll.reports.register') }}" wire:navigate class="rounded-lg border border-border p-5 transition hover:border-primary">
            <div class="flex items-center gap-3">
                <flux:icon.document-chart-bar class="size-6 text-primary" />
                <flux:heading size="lg">{{ __('Reports') }}</flux:heading>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">{{ __('Payroll register, PD7A remittance, T4/RL-1 and ROE.') }}</p>
        </a>
    </div>

    @if ($this->bankedOverdue !== [])
        <div class="mt-8 rounded-lg border border-amber-300 bg-amber-50 p-5 dark:border-amber-700 dark:bg-amber-950/40" data-test="banked-aging-card">
            <div class="flex items-center gap-3">
                <flux:icon.exclamation-triangle class="size-6 text-amber-600 dark:text-amber-400" />
                <flux:heading size="lg">{{ __('Banked time past its deadline') }}</flux:heading>
            </div>
            <p class="mt-2 text-sm text-muted-foreground">{{ __('Employment standards expect banked overtime to be taken or paid out within a deadline. These employees have banked hours older than their province allows — schedule the time off or add a banked payout on the next pay run.') }}</p>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($this->bankedOverdue as $row)
                    <li data-test="banked-overdue-row">
                        <span class="font-medium">{{ $row['name'] }}</span> —
                        {{ __(':overdue h overdue of :balance h banked (oldest from :date; :days-day limit)', [
                            'overdue' => number_format($row['overdue_hours'], 2),
                            'balance' => number_format($row['balance_hours'], 2),
                            'date' => $row['oldest_date'] ?? '—',
                            'days' => $row['deadline_days'],
                        ]) }}
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="mt-8 rounded-lg border border-border bg-muted/40 p-5">
        <flux:heading size="lg">{{ __('Getting started') }}</flux:heading>
        <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
            <li>{{ __('Create a pay schedule (how often you pay).') }}</li>
            <li>{{ __('Set up each employee: province, pay rate, TD1 claim amounts and vacation policy.') }}</li>
            <li>{{ __('Run payroll, review the calculated deductions, and write cheques.') }}</li>
            <li>{{ __('Use the PD7A report each remitting period to file with the CRA.') }}</li>
        </ol>
    </div>
</section>
