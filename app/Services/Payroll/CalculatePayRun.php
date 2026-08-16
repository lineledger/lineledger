<?php

namespace App\Services\Payroll;

use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Enums\TimeOffAccrualMethod;
use App\Enums\VacationPolicy;
use App\Models\Company;
use App\Models\EmployeePayrollProfile;
use App\Models\EmployeeRecurringItem;
use App\Models\EmployeeTimeOffPolicy;
use App\Models\PayRun;
use App\Models\PayRunLine;
use App\Models\PayRunLineManualEarning;
use App\Models\TimeOffPolicy;
use App\Services\Payroll\Data\EmployeePayrollContext;
use App\Support\Payroll\BankedOvertimeRules;
use App\Support\Payroll\EarningTypeCatalogue;
use App\Support\Payroll\TimeEntryPayCodeCatalogue;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Runs the deduction engine for every line of a draft pay run, building each
 * employee's earning/deduction rows from their profile + entered hours, then
 * writing the COMPUTED statutory amounts. Manual overrides (the *_override_cents
 * columns) are never touched, so a recalculation preserves them.
 */
class CalculatePayRun
{
    public function __construct(
        private EarningsAggregator $aggregator,
        private PayrollDeductionEngine $engine,
        private PayrollYtdService $ytd,
        private WorkersCompCalculator $workersComp,
    ) {}

    public function calculate(PayRun $payRun): PayRun
    {
        return DB::transaction(function () use ($payRun): PayRun {
            if (! $payRun->status->isEditable()) {
                throw new RuntimeException('Only draft pay runs can be calculated.');
            }

            $payRun->loadMissing('company', 'schedule', 'lines.profile.recurringItems', 'lines.profile.timeOffPolicies.policy', 'lines.manualEarnings');

            $periods = $payRun->schedule?->periods_per_year;

            if (! $periods) {
                throw new RuntimeException('A pay run needs a pay schedule before it can be calculated.');
            }

            $payDate = CarbonImmutable::parse($payRun->pay_date);

            // ALL active company policies, keyed by code — not just the ones
            // assigned to each employee — so a leave-coded earning can never
            // silently fall through to full-wage catalogue pricing (full pay on
            // top of a salary, no draw-down) when an assignment lags.
            $timeOffByCode = TimeOffPolicy::query()->where('is_active', true)->get()->keyBy('code')->all();

            foreach ($payRun->lines as $line) {
                $this->calculateLine($line, $periods, $payDate, $payRun->company, $timeOffByCode);
            }

            $payRun->refresh();
            $payRun->recalculateTotals();
            $payRun->forceFill(['status' => PayRunStatus::Calculated])->save();

            return $payRun->refresh();
        });
    }

    /**
     * @param  array<string, TimeOffPolicy>  $timeOffByCode
     */
    private function calculateLine(PayRunLine $line, int $periods, CarbonImmutable $payDate, Company $company, array $timeOffByCode): void
    {
        $profile = $line->profile;

        // 1. Base (regular) earning from the pay basis. Commission-only employees
        // have no base — their pay comes from recurring/run-time commission lines.
        $regular = match ($profile->pay_basis) {
            PayBasis::Salary => (int) round((int) $line->annual_salary_cents / $periods),
            PayBasis::Hourly => (int) round((int) ($line->hourly_rate_cents ?? 0) * (float) ($line->hours_worked ?? 0)),
            PayBasis::Commission => 0,
        };

        $earnings = [[
            'code' => 'regular',
            'name' => __('Regular pay'),
            'amount_cents' => $regular,
            'hours' => 0.0,
            'is_pensionable' => true,
            'is_insurable' => true,
            'is_taxable' => true,
            'expense_account_id' => $profile->wage_expense_account_id,
            't4_box' => '14',
        ]];

        // 2. Recurring earning templates (e.g. allowances), copied as snapshots.
        // Source-deduction treatment now comes from the item's own per-item flags.
        // A net-pay-only earning (reimbursement) is excluded from every base; a
        // subtract-from-salary earning reduces gross (negative amount).
        foreach ($profile->recurringItems->where('kind', 'earning')->where('is_active', true) as $item) {
            $netOnly = (bool) $item->add_to_net_pay_only;
            $basesOnly = (bool) $item->add_to_bases_only;
            $amount = $this->itemAmount($item, $regular);

            if ((bool) $item->subtract_from_salary) {
                $amount = -abs($amount);
            }

            $earnings[] = [
                'code' => $item->code,
                'name' => $item->name,
                'amount_cents' => $amount,
                'hours' => 0.0,
                'is_pensionable' => ! $netOnly && (bool) $item->cpp_qpp,
                'is_insurable' => ! $netOnly && (bool) $item->ei_insurable_earnings,
                // QPIP's base can diverge from EI's per the item flag.
                'is_qpip_insurable' => ! $netOnly && (bool) $item->qpip,
                'is_taxable' => ! $netOnly && ((bool) $item->taxable_federal || (bool) $item->taxable_provincial),
                // tax_as_bonus routes the item through the T4127 bonus method.
                'is_bonus_method' => ! $netOnly && (bool) $item->tax_as_bonus,
                'add_to_net_pay_only' => $netOnly,
                // An earning the user marks bases-only is a non-cash taxable benefit:
                // it feeds the source-deduction bases above but is paid no cash.
                'add_to_bases_only' => $basesOnly,
                'expense_account_id' => $item->expense_account_id,
                't4_box' => $item->t4_box,
            ];
        }

        // 2b. Run-time one-off earnings entered on the pay run (overtime, bonus,
        // commission, time off taken). Hours-based types multiply the hourly rate by
        // the catalogue multiplier (1.5× / 2× overtime); amount-based types take the
        // entered amount. A time-off "use" earning pays the employee's rate × hours
        // when the policy is paid (else $0) and carries its hours for draw-down.
        $bankedHours = 0.0;

        foreach ($line->manualEarnings as $manual) {
            $hours = $manual->calc_kind === 'hours' ? (float) ($manual->hours ?? 0) : 0.0;
            $timeOff = $timeOffByCode[$manual->code] ?? null;

            // Banked overtime: the hours land in the employee's banked-time
            // balance at their bank multiplier (province default or profile
            // override) and pay NOTHING now — deferred wages are taxed when
            // taken or paid out. A $0 display row keeps the stub honest. The
            // multiplier is derived here, never trusted from the stored row.
            if ($manual->code === TimeEntryPayCodeCatalogue::OVERTIME_BANKED) {
                if (! BankedOvertimeRules::canBank($profile)) {
                    throw new RuntimeException("Banked overtime is not enabled for {$line->contact->display_name} (profile toggle, permitted province, and written-agreement date required).");
                }

                $bankedHours += round($hours * BankedOvertimeRules::multiplierBpFor($profile) / 10000, 2);

                $earnings[] = [
                    'code' => $manual->code,
                    'name' => $manual->name,
                    'amount_cents' => 0,
                    'hours' => $hours,
                    'is_pensionable' => false,
                    'is_insurable' => false,
                    'is_taxable' => false,
                    'expense_account_id' => null,
                    't4_box' => null,
                ];

                continue;
            }

            if ($timeOff !== null) {
                // A PULLED leave day (from a time entry) is priced only for hourly
                // employees: a salaried/commission employee's base pay keeps paying
                // through leave, so their pulled earning carries the hours for
                // balance draw-down at $0 rather than double-paying the day. An
                // operator-entered row always pays when the policy is paid — that's
                // an explicit payout (e.g. paying out a sick balance). Hours-kind
                // rows price at rate × hours; an amount-kind row (a flat "$500
                // sick payout" from the run form) pays the entered amount.
                $paysCash = $timeOff->paid && ($profile->pay_basis === PayBasis::Hourly
                    || $manual->source !== PayRunLineManualEarning::SOURCE_TIME_ENTRIES);
                $amount = ! $paysCash ? 0 : ($manual->calc_kind === 'hours'
                    ? (int) round($this->effectiveHourlyRateCents($line, $company) * $hours)
                    : (int) ($manual->amount_cents ?? 0));

                $earnings[] = [
                    'code' => $manual->code,
                    'name' => $manual->name,
                    'amount_cents' => $amount,
                    'hours' => $hours,
                    'is_pensionable' => $paysCash,
                    'is_insurable' => $paysCash,
                    'is_taxable' => $paysCash,
                    'expense_account_id' => $manual->expense_account_id ?? $profile->wage_expense_account_id,
                    't4_box' => $paysCash ? '14' : null,
                ];

                continue;
            }

            $flags = EarningTypeCatalogue::flags($manual->code);

            // A PULLED stat-holiday day for a salaried employee: the salary
            // already pays the holiday, so the generated row records the hours
            // at $0 (an operator-entered stat row still pays — e.g. premium pay
            // for working the holiday).
            if ($manual->code === 'stat_holiday'
                && $profile->pay_basis !== PayBasis::Hourly
                && $manual->source === PayRunLineManualEarning::SOURCE_TIME_ENTRIES) {
                $earnings[] = [
                    'code' => $manual->code,
                    'name' => $manual->name,
                    'amount_cents' => 0,
                    'hours' => $hours,
                    'is_pensionable' => false,
                    'is_insurable' => false,
                    'is_taxable' => false,
                    'expense_account_id' => null,
                    't4_box' => null,
                ];

                continue;
            }

            $amount = $manual->calc_kind === 'hours'
                ? (int) round($this->effectiveHourlyRateCents($line, $company) * ((int) $manual->multiplier_bp / 10000) * $hours)
                : (int) ($manual->amount_cents ?? 0);

            $earnings[] = [
                'code' => $manual->code,
                'name' => $manual->name,
                'amount_cents' => $amount,
                'hours' => $hours,
                'is_pensionable' => $flags['pensionable'],
                'is_insurable' => $flags['insurable'],
                'is_taxable' => $flags['taxable'],
                // Bonus/retro lumps are taxed by the T4127 bonus method.
                'is_bonus_method' => $flags['bonus_method'],
                'expense_account_id' => $manual->expense_account_id ?? $profile->wage_expense_account_id,
                't4_box' => $manual->t4_box,
            ];
        }

        // Net-pay-only earnings (reimbursements) and bases-only earnings (taxable
        // benefits) are not cash wages, so they don't accrue vacation or form the
        // base for percent-of-gross deductions.
        $reportableEarnings = fn (array $rows): int => (int) array_sum(array_map(
            fn (array $e): int => (($e['add_to_net_pay_only'] ?? false) || ($e['add_to_bases_only'] ?? false)) ? 0 : (int) $e['amount_cents'],
            $rows,
        ));

        $vacationable = $reportableEarnings($earnings);

        // 3. Vacation: pay out each cheque, or accrue to the liability.
        $vacationAccrued = 0;
        $vacationPaid = 0;

        if ($profile->vacation_policy === VacationPolicy::PayEachCheque) {
            $vacationPaid = (int) round($vacationable * $profile->vacation_rate_bp / 10000);

            if ($vacationPaid > 0) {
                $earnings[] = [
                    'code' => 'vacation_pay',
                    'name' => __('Vacation pay'),
                    'amount_cents' => $vacationPaid,
                    'hours' => 0.0,
                    'is_pensionable' => true,
                    'is_insurable' => true,
                    'is_taxable' => true,
                    'expense_account_id' => $profile->wage_expense_account_id,
                    't4_box' => '14',
                ];
            }
        } else {
            $vacationAccrued = (int) round($vacationable * $profile->vacation_rate_bp / 10000);
        }

        $grossForPercent = $reportableEarnings($earnings);

        // 4. Recurring deduction templates (RRSP, benefits, garnishments).
        $deductions = [];

        foreach ($profile->recurringItems->where('kind', 'deduction')->where('is_active', true) as $item) {
            $deductions[] = [
                'code' => $item->code,
                'name' => $item->name,
                'amount_cents' => $this->cappedItemAmount($item, $this->itemAmount($item, $grossForPercent), (int) $line->contact_id, $payDate),
                // Pre-tax (federal or provincial) deductions reduce the taxable base.
                'reduces_taxable' => ((bool) $item->pre_tax_federal || (bool) $item->pre_tax_provincial),
                'liability_account_id' => $item->liability_account_id,
                't4_box' => $item->t4_box,
            ];
        }

        // 4b. Employer contributions (benefit/health, RPP match). Employer cost
        // only — computed here but kept out of the aggregator and net pay.
        $contributions = [];

        foreach ($profile->recurringItems->where('kind', 'contribution')->where('is_active', true) as $item) {
            $amount = $this->cappedItemAmount($item, $this->itemAmount($item, $grossForPercent), (int) $line->contact_id, $payDate);

            if ($amount <= 0) {
                continue;
            }

            $contributions[] = [
                'code' => $item->code,
                'name' => $item->name,
                'amount_cents' => $amount,
                'expense_account_id' => $item->expense_account_id,
                'liability_account_id' => $item->liability_account_id,
                't4_box' => $item->t4_box,
            ];

            // A taxable employer benefit must raise the employee's source-deduction
            // bases so the tax/CPP/EI on it is taken out of net pay — without being
            // paid as cash. Model that as a notional, non-cash "bases-only" earning
            // carrying the benefit's own tax-treatment flags. The contribution row
            // above still books the employer cost (DR expense / CR liability) and
            // carries the box-40 T4 amount; this notional row feeds box 14 only, so
            // its t4_box stays null to avoid double-counting box 40. (QPIP insurable
            // follows the EI flag — the aggregator uses a single insurable base.)
            $benefitIsTaxable = (bool) $item->taxable_federal || (bool) $item->taxable_provincial
                || (bool) $item->cpp_qpp || (bool) $item->ei_insurable_earnings;

            if ($benefitIsTaxable) {
                $earnings[] = [
                    'code' => $item->code,
                    'name' => $item->name,
                    'amount_cents' => $amount,
                    'hours' => 0.0,
                    'is_pensionable' => (bool) $item->cpp_qpp,
                    'is_insurable' => (bool) $item->ei_insurable_earnings,
                    'is_taxable' => ((bool) $item->taxable_federal || (bool) $item->taxable_provincial),
                    'add_to_bases_only' => true,
                    'expense_account_id' => null,
                    't4_box' => null,
                ];
            }
        }

        // 4c. Accruals (vacation/sick/banked time). Dollar accruals post to the GL;
        // hour accruals only move the employee balance (at post time).
        $accruals = [];

        // Banked overtime earned this run (already multiplied): an accrual into
        // the 'banked' balance. Hours-only by default (no GL until taken); in
        // the company's liability mode it also carries the dollar value —
        // banked hours pay out at the regular rate, so hours × rate is the
        // exact liability — and the poster posts DR wages / CR Banked Time
        // Payable (accounts resolved there).
        if ($bankedHours > 0.0) {
            $accruals[] = [
                'code' => 'banked',
                'name' => __('Banked time'),
                'calc_basis' => 'hours',
                'amount_cents' => $company->payroll_banked_overtime_liability
                    ? (int) round($this->effectiveHourlyRateCents($line, $company) * $bankedHours)
                    : 0,
                'hours' => $bankedHours,
                // The earn-side wage debit belongs where this employee's wages
                // go (per-department routing), not the generic default.
                'expense_account_id' => $profile->wage_expense_account_id,
                'liability_account_id' => null,
            ];
        }

        foreach ($profile->recurringItems->where('kind', 'accrual')->where('is_active', true) as $item) {
            [$accrualDollars, $accrualHours] = $this->accrualAmount($item, $grossForPercent, (float) ($line->hours_worked ?? 0));

            if ($accrualDollars === 0 && $accrualHours === 0.0) {
                continue;
            }

            $accruals[] = [
                'code' => $item->code,
                'name' => $item->name,
                'calc_basis' => (string) $item->calc_basis,
                'amount_cents' => $accrualDollars,
                'hours' => $accrualHours,
                'expense_account_id' => $item->expense_account_id,
                'liability_account_id' => $item->liability_account_id,
            ];
        }

        // 4d. Time-off policy accruals (per-period methods only). Beginning-of-year
        // and anniversary policies are granted as an annual lump by the
        // payroll:accrue-time-off command, not on each run.
        foreach ($profile->timeOffPolicies->where('is_active', true) as $assignment) {
            $policy = $assignment->policy;

            if ($policy === null || ! $policy->is_active || ! $policy->accrual_method->accruesPerRun()) {
                continue;
            }

            [$accrualDollars, $accrualHours] = $this->capPolicyAccrual(
                $policy,
                (int) $line->contact_id,
                $payDate,
                ...$this->policyAccrualAmount($policy, $assignment, $grossForPercent, (float) ($line->hours_worked ?? 0)),
            );

            if ($accrualDollars === 0 && $accrualHours === 0.0) {
                continue;
            }

            $accruals[] = [
                'code' => $policy->code,
                'name' => $policy->name,
                'calc_basis' => $policy->isDollarUnit() ? 'dollars' : 'hours',
                'amount_cents' => $accrualDollars,
                'hours' => $accrualHours,
                'expense_account_id' => $policy->expense_account_id,
                'liability_account_id' => $policy->liability_account_id,
            ];
        }

        // 5. Aggregate to the deduction bases and run the engine.
        $breakdown = $this->aggregator->aggregate(
            $earnings,
            array_map(fn (array $d) => ['amount_cents' => $d['amount_cents'], 'reduces_taxable' => $d['reduces_taxable']], $deductions),
        );

        $ytd = $this->ytd->priorYtd((int) $line->contact_id, $payDate, $profile);

        $context = $this->buildContext($profile, $line, $periods, $payDate);

        $voluntary = (int) array_sum(array_column($deductions, 'amount_cents'));

        $result = $this->engine->compute($context, $breakdown, $ytd, $voluntary);

        // 6. Persist earning + deduction snapshot rows.
        $line->earnings()->delete();

        foreach ($earnings as $i => $earning) {
            $line->earnings()->create([
                'code' => $earning['code'],
                'name' => $earning['name'],
                'amount_cents' => $earning['amount_cents'],
                'hours' => $earning['hours'],
                'is_pensionable' => $earning['is_pensionable'],
                'is_insurable' => $earning['is_insurable'],
                'is_taxable' => $earning['is_taxable'],
                'is_bonus_method' => $earning['is_bonus_method'] ?? false,
                'add_to_net_pay_only' => $earning['add_to_net_pay_only'] ?? false,
                'add_to_bases_only' => $earning['add_to_bases_only'] ?? false,
                'expense_account_id' => $earning['expense_account_id'] ?? null,
                't4_box' => $earning['t4_box'] ?? null,
                'class_id' => $profile->class_id,
                'location_id' => $profile->location_id,
                'line_order' => $i,
            ]);
        }

        $line->deductions()->delete();

        foreach (array_values($deductions) as $i => $deduction) {
            $line->deductions()->create([
                'code' => $deduction['code'],
                'name' => $deduction['name'],
                'amount_cents' => $deduction['amount_cents'],
                'reduces_taxable' => $deduction['reduces_taxable'],
                'liability_account_id' => $deduction['liability_account_id'],
                't4_box' => $deduction['t4_box'],
                'line_order' => $i,
            ]);
        }

        $line->contributions()->delete();

        foreach ($contributions as $i => $contribution) {
            $line->contributions()->create([
                'code' => $contribution['code'],
                'name' => $contribution['name'],
                'amount_cents' => $contribution['amount_cents'],
                'expense_account_id' => $contribution['expense_account_id'],
                'liability_account_id' => $contribution['liability_account_id'],
                't4_box' => $contribution['t4_box'],
                'line_order' => $i,
            ]);
        }

        $line->accruals()->delete();

        foreach ($accruals as $i => $accrual) {
            $line->accruals()->create([
                'code' => $accrual['code'],
                'name' => $accrual['name'],
                'calc_basis' => $accrual['calc_basis'],
                'amount_cents' => $accrual['amount_cents'],
                'hours' => $accrual['hours'],
                'expense_account_id' => $accrual['expense_account_id'],
                'liability_account_id' => $accrual['liability_account_id'],
                'line_order' => $i,
            ]);
        }

        // 7. Write computed statutory amounts and bases. Overrides are preserved.
        // For hourly employees, paid hours routed through manual earnings (paid
        // leave taken, overtime, stat pay) are insurable hours alongside the
        // worked hours — ROE Block 15A counts them; banked-overtime earn rows
        // ($0, deferred wages) and unpaid leave carry no insurable hours.
        // Salaried employees keep the per-period default (their leave hours are
        // already inside it).
        $manualInsurableHours = 0.0;

        if ($profile->pay_basis === PayBasis::Hourly) {
            foreach ($earnings as $earning) {
                if ($earning['is_insurable'] && (float) $earning['hours'] > 0 && (int) $earning['amount_cents'] > 0) {
                    $manualInsurableHours += (float) $earning['hours'];
                }
            }
        }

        $insurableHours = ($line->hours_worked !== null
            ? (float) $line->hours_worked
            : (float) ($profile->default_hours_per_period ?? 0)) + $manualInsurableHours;

        // Employer Quebec levies (depend on company settings, not the pure engine).
        // QHSF is on Quebec gross; CNESST on Quebec insurable earnings; both 0 for non-QC lines.
        $isQuebec = mb_strtoupper((string) $line->province_of_employment) === 'QC';
        $qhsf = $isQuebec ? (int) round($result->grossCents * (int) $company->qhsf_rate_bp / 10000) : 0;
        $cnesst = $isQuebec ? (int) round($result->insurableUsedCents * (int) $company->cnesst_rate_bp / 10000) : 0;

        // Rest-of-Canada workers' comp (WSIB/WCB): the province's rate on assessable
        // gross, capped per worker per year. 0 for Quebec (CNESST) and exempt workers.
        $wc = $this->workersComp->compute(
            $company,
            $profile,
            (string) $line->province_of_employment,
            $result->grossCents,
            $this->ytdGrossCents((int) $line->contact_id, $payDate),
        );

        $line->forceFill([
            'regular_earnings_cents' => $regular,
            'cpp_pensionable_cents' => $result->taxablePensionableUsedCents,
            'ei_insurable_cents' => $result->insurableUsedCents,
            'qpip_insurable_cents' => $result->qpipInsurableUsedCents,
            'insurable_hours' => $insurableHours,
            'cpp_employee_computed_cents' => $result->cppEmployeeCents,
            'cpp_employer_computed_cents' => $result->cppEmployerCents,
            'cpp2_employee_computed_cents' => $result->cpp2EmployeeCents,
            'cpp2_employer_computed_cents' => $result->cpp2EmployerCents,
            'ei_employee_computed_cents' => $result->eiEmployeeCents,
            'ei_employer_computed_cents' => $result->eiEmployerCents,
            'federal_tax_computed_cents' => $result->federalTaxCents,
            'provincial_tax_computed_cents' => $result->provincialTaxCents,
            'additional_tax_computed_cents' => $result->additionalTaxCents,
            // Quebec statutory (0 for the rest of Canada).
            'qpp_employee_computed_cents' => $result->qppEmployeeCents,
            'qpp_employer_computed_cents' => $result->qppEmployerCents,
            'qpp2_employee_computed_cents' => $result->qpp2EmployeeCents,
            'qpp2_employer_computed_cents' => $result->qpp2EmployerCents,
            'qpip_employee_computed_cents' => $result->qpipEmployeeCents,
            'qpip_employer_computed_cents' => $result->qpipEmployerCents,
            'quebec_tax_computed_cents' => $result->quebecTaxCents,
            'qhsf_employer_computed_cents' => $qhsf,
            'cnesst_employer_computed_cents' => $cnesst,
            'wc_employer_computed_cents' => $wc,
            'vacation_accrued_cents' => $vacationAccrued,
            'vacation_paid_cents' => $vacationPaid,
        ])->save();

        $line->refresh();
        $line->recalculateTotals();
    }

    /**
     * Assemble the per-employee tax context from the profile + line. Extracted so
     * each new payroll input (CPP age rules, federal-tax-exempt, authorized annual
     * deductions) adds exactly one argument here, in one place.
     */
    private function buildContext(EmployeePayrollProfile $profile, PayRunLine $line, int $periods, CarbonImmutable $payDate): EmployeePayrollContext
    {
        return new EmployeePayrollContext(
            province: $line->province_of_employment,
            payPeriodsPerYear: $periods,
            payDate: $payDate,
            federalClaimCents: (int) $profile->td1_federal_claim_cents,
            provincialClaimCents: (int) $profile->td1_provincial_claim_cents,
            cppExempt: (bool) $profile->cpp_exempt,
            eiExempt: (bool) $profile->ei_exempt,
            additionalTaxPerPeriodCents: (int) $profile->additional_tax_per_period_cents,
            annualDeductionsCents: (int) $profile->authorized_annual_deductions_cents,
            qpipExempt: (bool) $profile->qpip_exempt,
            incomeTaxExempt: (bool) $profile->income_tax_exempt,
            dateOfBirth: $profile->date_of_birth,
            cpt30ElectionDate: $profile->cpt30_election_date,
        );
    }

    /**
     * Standard full-time annual hours (52 weeks × 40h) used to derive a salaried
     * employee's hourly rate when the company hasn't overridden it in settings.
     */
    private const STANDARD_ANNUAL_HOURS = 2080;

    /**
     * The hourly rate to apply to hours-based earnings (overtime). Hourly
     * employees use their stated rate; salaried employees have it derived from
     * annual salary at the company's standard annual hours (default 2080) so
     * overtime isn't $0.
     */
    private function effectiveHourlyRateCents(PayRunLine $line, Company $company): int
    {
        if ($line->hourly_rate_cents) {
            return (int) $line->hourly_rate_cents;
        }

        if ($line->annual_salary_cents) {
            $standardHours = (int) ($company->payroll_standard_annual_hours ?: self::STANDARD_ANNUAL_HOURS);

            return $standardHours > 0
                ? (int) round((int) $line->annual_salary_cents / $standardHours)
                : 0;
        }

        return 0;
    }

    private function itemAmount(EmployeeRecurringItem $item, int $base): int
    {
        if ($item->calc_type === 'percent_of_gross') {
            return (int) round($base * (int) $item->percent_bp / 10000);
        }

        return (int) ($item->amount_cents ?? 0);
    }

    /**
     * Resolve an accrual to a [dollars, hours] pair from its calculation basis.
     * Dollar bases (percent-of-earnings, flat dollars, cents-per-hour) post to the
     * GL; hour bases (hours, percent-of-hours, units, miles) only move a balance.
     * For flat hour/unit/mile bases the rate is stored as quantity × 100 in
     * amount_cents (e.g. 4.00 hrs = 400).
     *
     * @return array{0: int, 1: float}
     */
    private function accrualAmount(EmployeeRecurringItem $item, int $grossForPercent, float $hoursWorked): array
    {
        return match ((string) $item->calc_basis) {
            'percent_of_earnings' => [(int) round($grossForPercent * (int) $item->percent_bp / 10000), 0.0],
            'dollars' => [(int) ($item->amount_cents ?? 0), 0.0],
            'cents_per_hour' => [(int) round($hoursWorked * (int) ($item->amount_cents ?? 0)), 0.0],
            'percent_of_hours' => [0, round($hoursWorked * (int) $item->percent_bp / 10000, 2)],
            default => [0, round((int) ($item->amount_cents ?? 0) / 100, 2)], // hours | units | miles
        };
    }

    /**
     * Clamp a deduction/contribution to its remaining annual room, if it carries
     * an annual maximum. The YTD lookback sums the same code's posted amounts
     * earlier in the calendar year (same discipline as the statutory caps).
     */
    private function cappedItemAmount(EmployeeRecurringItem $item, int $amount, int $contactId, CarbonImmutable $payDate): int
    {
        if ($item->annual_maximum_cents === null) {
            return $amount;
        }

        $room = max(0, (int) $item->annual_maximum_cents - $this->ytdItemTotalCents($contactId, $payDate, (string) $item->kind, (string) $item->code));

        return min($amount, $room);
    }

    /**
     * Sum of a recurring item's posted amounts (by code) earlier in the same
     * calendar year — from the deduction or contribution snapshot table.
     */
    private function ytdItemTotalCents(int $contactId, CarbonImmutable $payDate, string $kind, string $code): int
    {
        $table = $kind === 'contribution' ? 'pay_run_line_contributions' : 'pay_run_line_deductions';

        return (int) DB::table($table.' as x')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'x.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->whereIn('pr.status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value])
            ->whereYear('pr.pay_date', $payDate->year)
            ->whereDate('pr.pay_date', '<', $payDate->toDateString())
            ->where('x.code', $code)
            ->sum('x.amount_cents');
    }

    /**
     * This period's raw accrual for a time-off policy as [dollars, hours], from
     * its unit + method (honouring a per-employee rate override). Dollar policies
     * accrue a percent of earnings; hour policies accrue a flat amount per period
     * or a percent of hours worked.
     *
     * @return array{0: int, 1: float}
     */
    private function policyAccrualAmount(TimeOffPolicy $policy, EmployeeTimeOffPolicy $assignment, int $grossForPercent, float $hoursWorked): array
    {
        if ($policy->isDollarUnit()) {
            $bp = $assignment->rate_override_bp ?? (int) $policy->rate_bp;

            return [(int) round($grossForPercent * $bp / 10000), 0.0];
        }

        if ($policy->accrual_method === TimeOffAccrualMethod::PerHourWorked) {
            $bp = $assignment->rate_override_bp ?? (int) $policy->rate_bp;

            return [0, round($hoursWorked * $bp / 10000, 2)];
        }

        // Per pay period: a flat number of hours.
        $rate = $assignment->rate_override_hours ?? $policy->rate_hours;

        return [0, round((float) $rate, 2)];
    }

    /**
     * Sum of an employee's posted gross earlier in the same calendar year — the
     * assessable-earnings base for the workers'-comp annual maximum cap.
     */
    private function ytdGrossCents(int $contactId, CarbonImmutable $payDate): int
    {
        return (int) DB::table('pay_run_lines as prl')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->whereIn('pr.status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value])
            ->whereYear('pr.pay_date', $payDate->year)
            ->whereDate('pr.pay_date', '<', $payDate->toDateString())
            ->sum('prl.gross_cents');
    }

    /**
     * Clamp a policy's period accrual to its remaining annual room (cap − the
     * amount already accrued this calendar year for the same code), so accrual
     * stops once the annual maximum is reached.
     *
     * @return array{0: int, 1: float}
     */
    private function capPolicyAccrual(TimeOffPolicy $policy, int $contactId, CarbonImmutable $payDate, int $dollars, float $hours): array
    {
        if ($policy->isDollarUnit()) {
            if ($policy->annual_cap_cents === null) {
                return [$dollars, 0.0];
            }

            $room = max(0, (int) $policy->annual_cap_cents - $this->ytdAccrualByCode($contactId, $payDate, $policy->code)['cents']);

            return [min($dollars, $room), 0.0];
        }

        if ($policy->annual_cap_hours === null) {
            return [0, $hours];
        }

        $room = max(0.0, (float) $policy->annual_cap_hours - $this->ytdAccrualByCode($contactId, $payDate, $policy->code)['hours']);

        return [0, min($hours, $room)];
    }

    /**
     * Sum of a code's posted accruals earlier in the same calendar year, in both
     * hours and dollars — the time-off analogue of {@see ytdItemTotalCents()}.
     *
     * @return array{hours: float, cents: int}
     */
    private function ytdAccrualByCode(int $contactId, CarbonImmutable $payDate, string $code): array
    {
        $row = DB::table('pay_run_line_accruals as x')
            ->join('pay_run_lines as prl', 'prl.id', '=', 'x.pay_run_line_id')
            ->join('pay_runs as pr', 'pr.id', '=', 'prl.pay_run_id')
            ->where('prl.contact_id', $contactId)
            ->whereIn('pr.status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value])
            ->whereYear('pr.pay_date', $payDate->year)
            ->whereDate('pr.pay_date', '<', $payDate->toDateString())
            ->where('x.code', $code)
            ->selectRaw('COALESCE(SUM(x.hours), 0) AS hours, COALESCE(SUM(x.amount_cents), 0) AS cents')
            ->first();

        return ['hours' => (float) ($row->hours ?? 0), 'cents' => (int) ($row->cents ?? 0)];
    }
}
