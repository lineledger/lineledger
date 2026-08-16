<?php

use App\Actions\Payroll\PullTimeEntriesIntoPayRun;
use App\Actions\Payroll\SavePayRun;
use App\Enums\AccountSubtype;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PayRun;
use App\Models\PayrollSchedule;
use App\Services\Payroll\CalculatePayRun;
use App\Support\Money;
use App\Support\Payroll\EarningTypeCatalogue;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Pay run')] class extends Component {
    public Company $company;

    public ?PayRun $payRun = null;

    public ?int $payroll_schedule_id = null;

    public string $period_start_date = '';

    public string $period_end_date = '';

    public string $pay_date = '';

    public ?int $bank_account_id = null;

    /**
     * Whether the period/pay dates are still schedule-derived. Flips false once
     * the operator hand-edits a date, so changing the schedule won't clobber it.
     */
    public bool $datesAutofilled = true;

    /** @var array<int, array{selected: bool, name: string, pay_basis: string, hours: string, manual_earnings: array<int, array{code: string, value: string}>}> */
    public array $rows = [];

    public function mount(Company $company, ?PayRun $payRun = null): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);

        $this->bank_account_id = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->value('id');
        $this->payroll_schedule_id = PayrollSchedule::query()->where('is_active', true)->value('id');

        $enrolled = $this->enrolledEmployees();

        if ($payRun && $payRun->exists) {
            abort_unless($payRun->status->isEditable(), 403, __('Posted pay runs cannot be edited.'));

            $payRun->load('lines.manualEarnings');
            $this->payRun = $payRun;
            $this->payroll_schedule_id = $payRun->payroll_schedule_id;
            $this->period_start_date = $payRun->period_start_date->toDateString();
            $this->period_end_date = $payRun->period_end_date->toDateString();
            $this->pay_date = $payRun->pay_date->toDateString();
            $this->bank_account_id = $payRun->bank_account_id;
            $this->datesAutofilled = false;
            $existing = $payRun->lines->keyBy('contact_id');

            // An employee already ON the run stays editable even if they were
            // terminated/deactivated since (final pay!) — without this, editing
            // the run would silently drop their line on save.
            $onRunButNotEnrolled = \App\Models\Contact::query()
                ->whereIn('id', $existing->keys()->diff($enrolled->pluck('id')))
                ->with('payrollProfile')
                ->get()
                ->filter(fn ($c) => $c->payrollProfile !== null);

            $enrolled = $enrolled->concat($onRunButNotEnrolled);

            foreach ($enrolled as $employee) {
                $line = $existing->get($employee->id);
                $this->rows[$employee->id] = [
                    'selected' => $line !== null,
                    'name' => $employee->display_name,
                    'pay_basis' => $employee->payrollProfile->pay_basis->value,
                    'hours' => $line?->hours_worked !== null ? (string) (float) $line->hours_worked : (string) ($employee->payrollProfile->default_hours_per_period ?? ''),
                    // Only operator-entered rows are editable here; rows the
                    // time-entry pull generated are owned by the pull (shown
                    // read-only below) and survive a save untouched.
                    'manual_earnings' => $line
                        ? $line->manualEarnings->whereNull('source')->map(fn ($me) => [
                            'code' => $me->code,
                            'value' => $me->calc_kind === 'hours'
                                ? (string) (float) $me->hours
                                : ($me->amount_cents !== null ? Money::fromCents($me->amount_cents)->toDecimalString() : ''),
                        ])->values()->all()
                        : [],
                    'pulled' => $line
                        ? $line->manualEarnings->where('source', \App\Models\PayRunLineManualEarning::SOURCE_TIME_ENTRIES)->map(fn ($me) => [
                            'name' => $me->name,
                            'hours' => (string) (float) $me->hours,
                        ])->values()->all()
                        : [],
                ];
            }
        } else {
            $this->applyScheduleDates();

            foreach ($enrolled as $employee) {
                $this->rows[$employee->id] = [
                    'selected' => true,
                    'name' => $employee->display_name,
                    'pay_basis' => $employee->payrollProfile->pay_basis->value,
                    'hours' => (string) ($employee->payrollProfile->default_hours_per_period ?? ''),
                    'manual_earnings' => [],
                    'pulled' => [],
                ];
            }
        }
    }

    private function enrolledEmployees()
    {
        return Contact::query()
            ->where('is_employee', true)
            ->where('is_active', true)
            ->whereHas('payrollProfile', fn ($q) => $q->where('is_active', true))
            ->with('payrollProfile')
            ->orderBy('display_name')
            ->get();
    }

    /**
     * Auto-fill the period + pay dates from the selected schedule (when the user
     * hasn't hand-edited them). Falls back to a sensible biweekly default if no
     * schedule is resolved yet.
     */
    private function applyScheduleDates(): void
    {
        $schedule = $this->payroll_schedule_id
            ? PayrollSchedule::find($this->payroll_schedule_id)
            : null;

        if ($schedule === null) {
            $today = $this->company->currentDateTime();
            $this->period_start_date = $today->subDays(13)->toDateString();
            $this->period_end_date = $today->toDateString();
            $this->pay_date = $today->toDateString();

            return;
        }

        $dates = $this->computePeriod($schedule);
        $this->period_start_date = $dates['period_start_date'];
        $this->period_end_date = $dates['period_end_date'];
        $this->pay_date = $dates['pay_date'];
    }

    /**
     * The next open period for a schedule: advance from the last non-void run on
     * it, or from the schedule's anchor (rolled forward to today) when there is
     * none. Pay date is the period end plus the schedule's offset days.
     *
     * @return array{period_start_date: string, period_end_date: string, pay_date: string}
     */
    private function computePeriod(PayrollSchedule $schedule): array
    {
        $frequency = $schedule->frequency;

        $last = PayRun::query()
            ->where('payroll_schedule_id', $schedule->id)
            ->where('status', '!=', PayRunStatus::Void->value)
            ->orderByDesc('period_end_date')
            ->orderByDesc('id')
            ->first();

        if ($last !== null) {
            $periodEnd = $frequency->nextPeriodEnd($last->period_end_date);
        } else {
            $periodEnd = CarbonImmutable::parse($schedule->anchor_period_end_date);
            $today = $this->company->currentDateTime();

            // Roll a stale anchor forward to the first period ending on/after today.
            for ($guard = 0; $periodEnd->lt($today) && $guard < 600; $guard++) {
                $periodEnd = $frequency->nextPeriodEnd($periodEnd);
            }
        }

        return [
            'period_start_date' => $frequency->periodStartFor($periodEnd)->toDateString(),
            'period_end_date' => $periodEnd->toDateString(),
            'pay_date' => $periodEnd->addDays((int) $schedule->default_pay_offset_days)->toDateString(),
        ];
    }

    public function updatedPayrollScheduleId(): void
    {
        // Only re-derive for a brand-new run whose dates the user hasn't touched.
        if (($this->payRun?->exists ?? false) || ! $this->datesAutofilled) {
            return;
        }

        $this->applyScheduleDates();
    }

    public function updatedPeriodStartDate(): void
    {
        $this->datesAutofilled = false;
    }

    public function updatedPeriodEndDate(): void
    {
        $this->datesAutofilled = false;
    }

    public function updatedPayDate(): void
    {
        $this->datesAutofilled = false;
    }

    public function resetDatesToSchedule(): void
    {
        $this->applyScheduleDates();
        $this->datesAutofilled = true;
    }

    #[Computed]
    public function schedules()
    {
        return PayrollSchedule::query()->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->get();
    }

    public function hourly(string $basis): bool
    {
        return $basis === PayBasis::Hourly->value;
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function earningTypeOptions(): array
    {
        return EarningTypeCatalogue::runTimeOptions();
    }

    public function addManualEarning(int $contactId): void
    {
        $this->rows[$contactId]['manual_earnings'][] = ['code' => 'bonus', 'value' => ''];
    }

    public function removeManualEarning(int $contactId, int $index): void
    {
        unset($this->rows[$contactId]['manual_earnings'][$index]);
        $this->rows[$contactId]['manual_earnings'] = array_values($this->rows[$contactId]['manual_earnings']);
    }

    /**
     * @return array<int, array{contact_id: int, hours_worked: ?string, manual_earnings: array<int, array<string, mixed>>}>
     */
    private function selectedLines(): array
    {
        $lines = [];

        foreach ($this->rows as $contactId => $row) {
            if (! ($row['selected'] ?? false)) {
                continue;
            }

            $manual = [];

            foreach ($row['manual_earnings'] ?? [] as $me) {
                $code = $me['code'] ?? '';

                if ($code === '') {
                    continue;
                }

                $flags = EarningTypeCatalogue::flags($code);
                $value = (string) ($me['value'] ?? '');

                $manual[] = [
                    'code' => $code,
                    'name' => $flags['name'],
                    'calc_kind' => $flags['hourly'] ? 'hours' : 'amount',
                    'amount_cents' => $flags['hourly'] ? null : ($value !== '' ? Money::fromString($value)->cents : 0),
                    'hours' => $flags['hourly'] ? (float) ($value !== '' ? $value : 0) : null,
                    'multiplier_bp' => $flags['multiplier_bp'],
                    // Wage earnings land in box 14 via gross; only special boxes are tagged.
                    't4_box' => $flags['t4_box'] === '14' ? null : $flags['t4_box'],
                ];
            }

            $lines[] = ['contact_id' => $contactId, 'hours_worked' => $row['hours'] ?? null, 'manual_earnings' => $manual];
        }

        return $lines;
    }

    private function persist(SavePayRun $action): ?PayRun
    {
        // Field validation throws a ValidationException Livewire surfaces inline.
        $validated = $this->validate([
            'payroll_schedule_id' => ['required', 'integer', 'exists:payroll_schedules,id'],
            'period_start_date' => ['required', 'date'],
            'period_end_date' => ['required', 'date', 'after_or_equal:period_start_date'],
            'pay_date' => ['required', 'date'],
            'bank_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        $lines = $this->selectedLines();

        if ($lines === []) {
            $this->addError('rows', __('Select at least one employee.'));

            return null;
        }

        return $action->handle([...$validated, 'lines' => $lines], $this->payRun);
    }

    public function saveDraft(SavePayRun $action): void
    {
        $run = $this->persist($action);

        if ($run === null) {
            return;
        }

        Flux::toast(variant: 'success', text: __('Draft pay run saved.'));
        $this->redirectRoute('pay-runs.show', ['company' => $this->company, 'payRun' => $run], navigate: true);
    }

    public function calculate(SavePayRun $save, CalculatePayRun $calculate): void
    {
        $run = $this->persist($save);

        if ($run === null) {
            return;
        }

        $calculate->calculate($run);

        Flux::toast(variant: 'success', text: __('Pay run calculated.'));
        $this->redirectRoute('pay-runs.show', ['company' => $this->company, 'payRun' => $run], navigate: true);
    }

    /**
     * Persist the draft, then pull each employee's Approved, unpaid time entries
     * for the period: regular hours fill hourly employees' hours_worked (with
     * the weekly overtime split), every other pay code (overtime, stat, sick,
     * vacation, …) becomes its own earning line for any pay basis. Reloads the
     * editor so the pulled hours + generated lines show.
     */
    public function pullTimeEntries(SavePayRun $save, PullTimeEntriesIntoPayRun $pull): void
    {
        $run = $this->persist($save);

        if ($run === null) {
            return;
        }

        $summary = $pull->handle($run);

        $outsideNote = $summary['outside_period'] > 0
            ? ' '.trans_choice('{1}:n approved entry is dated outside this period and was left alone.|[2,*]:n approved entries are dated outside this period and were left alone.', $summary['outside_period'], ['n' => $summary['outside_period']])
            : '';

        $salariedNote = $summary['salaried_regular'] > 0
            ? ' '.trans_choice('{1}:n regular-hours entry for a salaried employee was left alone (hours don’t drive salaried pay).|[2,*]:n regular-hours entries for salaried employees were left alone (hours don’t drive salaried pay).', $summary['salaried_regular'], ['n' => $summary['salaried_regular']])
            : '';

        if ($summary['entries'] === 0) {
            Flux::toast(variant: 'warning', text: __('No time pulled — no approved, unpaid time entries dated :start to :end.', ['start' => $this->period_start_date, 'end' => $this->period_end_date]).$outsideNote.$salariedNote);
        } else {
            $breakdown = collect($summary['by_code'])
                ->filter(fn (float $hours): bool => $hours > 0)
                ->map(fn (float $hours, string $code): string => \App\Support\Payroll\TimeEntryPayCodeCatalogue::label($code).' '.rtrim(rtrim(number_format($hours, 2), '0'), '.'))
                ->implode(', ');

            Flux::toast(variant: 'success', text: __('Pulled :hours hours from :entries time entries for :n employee(s).', ['hours' => rtrim(rtrim(number_format($summary['hours'], 2), '0'), '.'), 'entries' => $summary['entries'], 'n' => $summary['employees']]).($breakdown !== '' ? ' ('.$breakdown.')' : '').$outsideNote.$salariedNote);
        }

        $this->redirectRoute('pay-runs.edit', ['company' => $this->company, 'payRun' => $run], navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-4xl">
    <div class="mb-6">
        <flux:button variant="ghost" size="sm" icon="arrow-left" :href="route('pay-runs.index')" wire:navigate>
            {{ __('Back to pay runs') }}
        </flux:button>
        <flux:heading size="xl" level="1" class="mt-3">{{ $payRun?->exists ? __('Edit pay run :no', ['no' => $payRun->run_no]) : __('New pay run') }}</flux:heading>
    </div>

    <div class="space-y-8">
        <div class="rounded-lg border border-border p-5">
            <div class="mb-4 flex items-center justify-between gap-3">
                <flux:heading size="lg">{{ __('Pay period') }}</flux:heading>
                @unless ($payRun?->exists)
                    <flux:button variant="ghost" size="sm" icon="arrow-path" wire:click="resetDatesToSchedule">{{ __('Reset to schedule') }}</flux:button>
                @endunless
            </div>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <flux:select wire:model.live="payroll_schedule_id" :label="__('Pay schedule')">
                    @foreach ($this->schedules as $schedule)
                        <flux:select.option value="{{ $schedule->id }}">{{ $schedule->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="bank_account_id" :label="__('Pay from bank account')">
                    @foreach ($this->bankAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input type="date" wire:model="period_start_date" :label="__('Period start')" />
                <flux:input type="date" wire:model="period_end_date" :label="__('Period end')" />
                <flux:input type="date" wire:model="pay_date" :label="__('Pay date')" :description="__('The cheque/posting date.')" />
            </div>
            @unless ($payRun?->exists)
                <flux:text class="mt-3 text-xs text-muted-foreground">{{ __('Dates auto-fill from the selected schedule. Edit any date to override.') }}</flux:text>
            @endunless
        </div>

        <div class="rounded-lg border border-border p-5">
            <div class="mb-1 flex flex-wrap items-start justify-between gap-3">
                <flux:heading size="lg">{{ __('Employees') }}</flux:heading>
                <flux:button variant="ghost" size="sm" icon="clock" wire:click="pullTimeEntries" wire:confirm="{{ __('Pull approved time entries for this period? This overwrites hours and overtime for hourly employees.') }}" data-test="pull-time-entries">
                    {{ __('Pull hours from time entries') }}
                </flux:button>
            </div>
            <flux:text class="mb-4 text-sm text-muted-foreground">{{ __('Choose who to pay, enter hours for hourly staff, and add one-off earnings (overtime, bonus, commission).') }}</flux:text>

            @error('rows') <flux:callout variant="danger" class="mb-4">{{ $message }}</flux:callout> @enderror

            @forelse ($rows as $contactId => $row)
                <div class="border-b border-border py-3 last:border-0" wire:key="row-{{ $contactId }}">
                    <div class="flex items-center justify-between gap-4">
                        <flux:checkbox wire:model.live="rows.{{ $contactId }}.selected" :label="$row['name']" />
                        <div class="flex items-center gap-2">
                            @if ($this->hourly($row['pay_basis']))
                                <flux:input type="number" step="0.25" wire:model="rows.{{ $contactId }}.hours" :placeholder="__('Hours')" class="w-28" />
                                <span class="text-sm text-muted-foreground">{{ __('hrs') }}</span>
                            @elseif ($row['pay_basis'] === 'commission')
                                <flux:badge size="sm" color="sky">{{ __('Commission') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Salary') }}</flux:badge>
                            @endif
                        </div>
                    </div>

                    @if ($row['selected'] ?? false)
                        <div class="mt-3 space-y-2 pl-7">
                            @foreach ($row['pulled'] ?? [] as $pulled)
                                <div class="flex items-center gap-2 text-sm text-muted-foreground" data-test="pulled-earning">
                                    <flux:icon.clock class="size-4" />
                                    <span>{{ $pulled['name'] }} · {{ $pulled['hours'] }} {{ __('hrs') }}</span>
                                    <flux:badge size="sm" color="zinc">{{ __('from time entries') }}</flux:badge>
                                </div>
                            @endforeach
                            @foreach ($row['manual_earnings'] ?? [] as $mi => $me)
                                <div class="flex flex-wrap items-center gap-2" wire:key="me-{{ $contactId }}-{{ $mi }}">
                                    <flux:select wire:model.live="rows.{{ $contactId }}.manual_earnings.{{ $mi }}.code" class="max-w-[220px]">
                                        @foreach ($this->earningTypeOptions as $code => $label)
                                            <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    @if (\App\Support\Payroll\EarningTypeCatalogue::isHourly($me['code'] ?? 'bonus'))
                                        <flux:input type="number" step="0.25" wire:model="rows.{{ $contactId }}.manual_earnings.{{ $mi }}.value" :placeholder="__('Hours')" class="w-28" />
                                        <span class="text-sm text-muted-foreground">{{ __('hrs') }}</span>
                                    @else
                                        <flux:input wire:model="rows.{{ $contactId }}.manual_earnings.{{ $mi }}.value" :placeholder="__('Amount')" inputmode="decimal" class="w-32" />
                                    @endif
                                    <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeManualEarning({{ $contactId }}, {{ $mi }})" :aria-label="__('Remove earning')" />
                                </div>
                            @endforeach
                            <flux:button variant="ghost" size="xs" icon="plus" wire:click="addManualEarning({{ $contactId }})">{{ __('Add earning') }}</flux:button>
                        </div>
                    @endif
                </div>
            @empty
                <flux:callout variant="warning">
                    {{ __('No employees are enrolled in payroll yet.') }}
                    <flux:link :href="route('payroll.employees.index')" wire:navigate>{{ __('Set up employees') }}</flux:link>
                </flux:callout>
            @endforelse
        </div>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" wire:click="saveDraft" data-test="pay-run-save-draft">{{ __('Save draft') }}</flux:button>
            <flux:button variant="primary" icon="calculator" wire:click="calculate" data-test="pay-run-calculate">{{ __('Calculate') }}</flux:button>
        </div>
    </div>
</section>
