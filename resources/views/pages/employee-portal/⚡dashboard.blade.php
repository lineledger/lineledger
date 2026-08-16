<?php

use App\Enums\PayRunStatus;
use App\Enums\SlipType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\PayrollSlipFiling;
use App\Models\PayRunLine;
use App\Services\Reporting\PayStatementYtdCalculator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.employee-portal')] #[Title('My pay')] class extends Component
{
    public Company $company;

    public Contact $employee;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->employee = auth('customer')->user();
    }

    /**
     * This employee's own pay-run lines from POSTED/Paid runs, newest pay-date
     * first. The contact_id filter is the ownership boundary; the download
     * endpoint re-checks it per request.
     *
     * @return Collection<int, PayRunLine>
     */
    #[Computed]
    public function payStatements(): Collection
    {
        return PayRunLine::query()
            ->where('contact_id', $this->employee->id)
            ->whereHas('payRun', fn ($q) => $q->whereIn('status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value]))
            ->with('payRun:id,run_no,pay_date,status')
            ->get()
            ->sortByDesc(fn (PayRunLine $line) => $line->payRun->pay_date?->timestamp ?? 0)
            ->values();
    }

    /**
     * Calendar years for which the employer has FINALIZED a slip filing of the
     * given type that includes this employee. Draft (un-finalized) years never
     * appear here — a slip only reaches the portal once it is issued.
     *
     * @return Collection<int, int>
     */
    private function finalizedSlipYears(SlipType $type): Collection
    {
        return PayrollSlipFiling::query()
            ->where('company_id', $this->company->id)
            ->where('slip_type', $type->value)
            ->whereHas('lines', fn ($q) => $q->where('contact_id', (int) auth('customer')->id()))
            ->orderByDesc('year')
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->values();
    }

    /**
     * Calendar years for which this employee has a finalized T4 slip.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function t4Years(): Collection
    {
        return $this->finalizedSlipYears(SlipType::T4);
    }

    /**
     * Calendar years for which this employee has a finalized RL-1 slip.
     *
     * @return Collection<int, int>
     */
    #[Computed]
    public function rl1Years(): Collection
    {
        return $this->finalizedSlipYears(SlipType::Rl1);
    }

    /**
     * Running time-off balances (vacation $ + each accrual code).
     *
     * @return Collection<int, EmployeeAccrualBalance>
     */
    #[Computed]
    public function accrualBalances(): Collection
    {
        $profile = $this->employee->payrollProfile;

        if ($profile === null) {
            return collect();
        }

        return EmployeeAccrualBalance::query()
            ->where('employee_payroll_profile_id', $profile->id)
            ->orderBy('name')
            ->get();
    }

    /**
     * Year-to-date totals as of the most recent posted statement (null until the
     * employee has at least one posted run).
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function ytd(): ?array
    {
        $latest = $this->payStatements->first();

        return $latest === null
            ? null
            : app(PayStatementYtdCalculator::class)->forLine($latest);
    }

    public function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading size="xl" level="1">{{ __('Hello, :name', ['name' => $employee->display_name]) }}</flux:heading>
                <flux:subheading>{{ __('Your pay statements, tax slips and balances.') }}</flux:subheading>
            </div>

            <div class="flex items-center gap-2">
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="clock"
                    :href="route('employee-portal.time', ['company' => $company->slug])"
                    wire:navigate
                    data-test="employee-portal-time"
                >
                    {{ __('My time') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="calendar-days"
                    :href="route('employee-portal.time-off', ['company' => $company->slug])"
                    wire:navigate
                    data-test="employee-portal-time-off"
                >
                    {{ __('Time off') }}
                </flux:button>
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="pencil-square"
                    :href="route('employee-portal.edit-info', ['company' => $company->slug])"
                    wire:navigate
                    data-test="employee-portal-edit-info"
                >
                    {{ __('Edit my info') }}
                </flux:button>
            </div>
        </div>
    </div>

    {{-- YTD + balances --}}
    <div class="grid gap-4 sm:grid-cols-2">
        <flux:card>
            <flux:subheading>{{ __('Year to date') }}</flux:subheading>
            @if ($this->ytd)
                <dl class="mt-2 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-muted-foreground">{{ __('Gross') }}</dt><dd class="font-mono" data-test="ytd-gross">{{ $this->money($this->ytd['gross_ytd']) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-muted-foreground">{{ __('Deductions') }}</dt><dd class="font-mono">{{ $this->money($this->ytd['deductions_ytd']) }}</dd></div>
                    <div class="flex justify-between border-t border-border pt-1"><dt>{{ __('Net pay') }}</dt><dd class="font-mono font-semibold">{{ $this->money($this->ytd['net_ytd']) }}</dd></div>
                </dl>
            @else
                <p class="mt-2 text-sm text-muted-foreground">{{ __('No posted pay yet.') }}</p>
            @endif
        </flux:card>

        <flux:card>
            <flux:subheading>{{ __('Balances') }}</flux:subheading>
            <dl class="mt-2 space-y-1 text-sm">
                @if ($employee->payrollProfile && $employee->payrollProfile->vacation_balance_cents)
                    <div class="flex justify-between"><dt class="text-muted-foreground">{{ __('Vacation pay') }}</dt><dd class="font-mono" data-test="vacation-balance">{{ $this->money((int) $employee->payrollProfile->vacation_balance_cents) }}</dd></div>
                @endif
                @forelse ($this->accrualBalances as $balance)
                    <div class="flex justify-between">
                        <dt class="text-muted-foreground">{{ $balance->name }}</dt>
                        <dd class="font-mono">{{ number_format((float) $balance->balance_hours, 2) }} {{ __('hrs') }}</dd>
                    </div>
                @empty
                    @if (! ($employee->payrollProfile && $employee->payrollProfile->vacation_balance_cents))
                        <p class="text-sm text-muted-foreground">{{ __('No balances to show.') }}</p>
                    @endif
                @endforelse
            </dl>
        </flux:card>
    </div>

    {{-- Pay statements --}}
    <div>
        <flux:heading size="lg" class="mb-2">{{ __('Pay statements') }}</flux:heading>
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Pay date') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Run') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Net pay') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->payStatements as $line)
                        <tr data-test="pay-statement-row">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $line->payRun->pay_date?->toDateString() }}</td>
                            <td class="px-4 py-2 font-mono">{{ $line->payRun->run_no }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ $this->money((int) $line->net_cents) }}</td>
                            <td class="px-4 py-2 text-right">
                                <flux:button
                                    size="xs"
                                    variant="ghost"
                                    icon="arrow-down-tray"
                                    :href="route('employee-portal.pay-stub.pdf', ['company' => $company->slug, 'payRunLine' => $line->id])"
                                >
                                    {{ __('PDF') }}
                                </flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-10 text-center text-muted-foreground">{{ __('You have no pay statements yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Tax slips (finalized filings only) --}}
    <div>
        <flux:heading size="lg" class="mb-2">{{ __('Tax slips') }}</flux:heading>

        @if ($this->t4Years->isEmpty() && $this->rl1Years->isEmpty())
            <p class="text-sm text-muted-foreground" data-test="tax-slips-empty">{{ __("Your employer hasn't issued your tax slips for this year yet.") }}</p>
        @else
            @if ($this->t4Years->isNotEmpty())
                <div class="mb-3">
                    <flux:subheading class="mb-2">{{ __('T4 slips') }}</flux:subheading>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->t4Years as $year)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-down-tray"
                                :href="route('employee-portal.t4.pdf', ['company' => $company->slug, 'year' => $year])"
                                data-test="t4-link"
                            >
                                {{ $year }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($this->rl1Years->isNotEmpty())
                <div>
                    <flux:subheading class="mb-2">{{ __('RL-1 slips') }}</flux:subheading>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->rl1Years as $year)
                            <flux:button
                                size="sm"
                                variant="ghost"
                                icon="arrow-down-tray"
                                :href="route('employee-portal.rl1.pdf', ['company' => $company->slug, 'year' => $year])"
                                data-test="rl1-link"
                            >
                                {{ $year }}
                            </flux:button>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif
    </div>
</div>
