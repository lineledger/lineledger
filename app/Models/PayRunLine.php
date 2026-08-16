<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PayBasis;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'pay_run_id', 'contact_id', 'employee_payroll_profile_id',
    'province_of_employment', 'pay_basis', 'hours_worked', 'insurable_hours',
    'hourly_rate_cents', 'annual_salary_cents', 'regular_earnings_cents', 'gross_cents',
    'cpp_pensionable_cents', 'ei_insurable_cents', 'qpip_insurable_cents',
    'cpp_employee_computed_cents', 'cpp_employee_override_cents',
    'cpp_employer_computed_cents', 'cpp_employer_override_cents',
    'cpp2_employee_computed_cents', 'cpp2_employee_override_cents',
    'cpp2_employer_computed_cents', 'cpp2_employer_override_cents',
    'ei_employee_computed_cents', 'ei_employee_override_cents',
    'ei_employer_computed_cents', 'ei_employer_override_cents',
    'federal_tax_computed_cents', 'federal_tax_override_cents',
    'provincial_tax_computed_cents', 'provincial_tax_override_cents',
    'additional_tax_computed_cents', 'additional_tax_override_cents',
    'qpp_employee_computed_cents', 'qpp_employee_override_cents',
    'qpp_employer_computed_cents', 'qpp_employer_override_cents',
    'qpp2_employee_computed_cents', 'qpp2_employee_override_cents',
    'qpp2_employer_computed_cents', 'qpp2_employer_override_cents',
    'qpip_employee_computed_cents', 'qpip_employee_override_cents',
    'qpip_employer_computed_cents', 'qpip_employer_override_cents',
    'quebec_tax_computed_cents', 'quebec_tax_override_cents',
    'qhsf_employer_computed_cents', 'cnesst_employer_computed_cents', 'wc_employer_computed_cents',
    'vacation_accrued_cents', 'vacation_paid_cents',
    'total_deductions_cents', 'net_cents',
    'ytd_pensionable_cents', 'ytd_insurable_cents', 'ytd_cpp_employee_cents',
    'ytd_cpp2_employee_cents', 'ytd_ei_employee_cents', 'ytd_gross_cents', 'ytd_tax_cents',
    'ytd_qpp_employee_cents', 'ytd_qpp2_employee_cents',
    'ytd_qpip_employee_cents', 'ytd_qpip_insurable_cents',
])]
class PayRunLine extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * The statutory components that carry a computed + override pair.
     */
    public const COMPONENTS = [
        'cpp_employee', 'cpp_employer', 'cpp2_employee', 'cpp2_employer',
        'ei_employee', 'ei_employer', 'federal_tax', 'provincial_tax', 'additional_tax',
        'qpp_employee', 'qpp_employer', 'qpp2_employee', 'qpp2_employer',
        'qpip_employee', 'qpip_employer', 'quebec_tax',
    ];

    /**
     * @return BelongsTo<PayRun, $this>
     */
    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<EmployeePayrollProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollProfile::class, 'employee_payroll_profile_id');
    }

    /**
     * @return HasMany<PayRunLineEarning, $this>
     */
    public function earnings(): HasMany
    {
        return $this->hasMany(PayRunLineEarning::class)->orderBy('line_order');
    }

    /**
     * @return HasMany<PayRunLineDeduction, $this>
     */
    public function deductions(): HasMany
    {
        return $this->hasMany(PayRunLineDeduction::class)->orderBy('line_order');
    }

    /**
     * Run-time, one-off earnings (overtime/bonus/commission) entered on the pay
     * run. Structured input that survives recalculation.
     *
     * @return HasMany<PayRunLineManualEarning, $this>
     */
    public function manualEarnings(): HasMany
    {
        return $this->hasMany(PayRunLineManualEarning::class)->orderBy('line_order');
    }

    /**
     * Employer-funded contributions (benefit/health, RPP match) for this line.
     * Employer cost only — never part of net pay or the deduction total.
     *
     * @return HasMany<PayRunLineContribution, $this>
     */
    public function contributions(): HasMany
    {
        return $this->hasMany(PayRunLineContribution::class)->orderBy('line_order');
    }

    /**
     * Accruals for this line (vacation/sick/banked time). Dollar accruals post to
     * the GL; hour accruals only move the employee balance.
     *
     * @return HasMany<PayRunLineAccrual, $this>
     */
    public function accruals(): HasMany
    {
        return $this->hasMany(PayRunLineAccrual::class)->orderBy('line_order');
    }

    /**
     * The effective value of a statutory component: the manual override if set,
     * otherwise the computed value.
     */
    public function effective(string $component): int
    {
        return $this->{$component.'_override_cents'} ?? (int) $this->{$component.'_computed_cents'};
    }

    public function cppEmployeeCents(): int
    {
        return $this->effective('cpp_employee');
    }

    public function cppEmployerCents(): int
    {
        return $this->effective('cpp_employer');
    }

    public function cpp2EmployeeCents(): int
    {
        return $this->effective('cpp2_employee');
    }

    public function cpp2EmployerCents(): int
    {
        return $this->effective('cpp2_employer');
    }

    public function eiEmployeeCents(): int
    {
        return $this->effective('ei_employee');
    }

    public function eiEmployerCents(): int
    {
        return $this->effective('ei_employer');
    }

    public function federalTaxCents(): int
    {
        return $this->effective('federal_tax');
    }

    public function provincialTaxCents(): int
    {
        return $this->effective('provincial_tax');
    }

    public function additionalTaxCents(): int
    {
        return $this->effective('additional_tax');
    }

    public function qppEmployeeCents(): int
    {
        return $this->effective('qpp_employee');
    }

    public function qppEmployerCents(): int
    {
        return $this->effective('qpp_employer');
    }

    public function qpp2EmployeeCents(): int
    {
        return $this->effective('qpp2_employee');
    }

    public function qpp2EmployerCents(): int
    {
        return $this->effective('qpp2_employer');
    }

    public function qpipEmployeeCents(): int
    {
        return $this->effective('qpip_employee');
    }

    public function qpipEmployerCents(): int
    {
        return $this->effective('qpip_employer');
    }

    public function quebecTaxCents(): int
    {
        return $this->effective('quebec_tax');
    }

    public function qhsfEmployerCents(): int
    {
        return (int) $this->qhsf_employer_computed_cents;
    }

    public function cnesstEmployerCents(): int
    {
        return (int) $this->cnesst_employer_computed_cents;
    }

    public function wcEmployerCents(): int
    {
        return (int) $this->wc_employer_computed_cents;
    }

    /**
     * Total income tax withheld: federal (already abated for QC) + provincial
     * (0 for QC) + Quebec (0 for the rest of Canada) + employee-requested extra.
     */
    public function incomeTaxCents(): int
    {
        return $this->federalTaxCents() + $this->provincialTaxCents() + $this->quebecTaxCents() + $this->additionalTaxCents();
    }

    public function voluntaryDeductionsCents(): int
    {
        $this->loadMissing('deductions');

        return (int) $this->deductions->sum('amount_cents');
    }

    /**
     * Total employer cost on top of gross pay. QPP/QPIP/QHSF/CNESST are 0 for the
     * rest of Canada; CPP/CPP2 are 0 for Quebec, so this is branch-free.
     */
    public function employerContributionsCents(): int
    {
        return $this->cppEmployerCents() + $this->cpp2EmployerCents() + $this->eiEmployerCents()
            + $this->qppEmployerCents() + $this->qpp2EmployerCents() + $this->qpipEmployerCents()
            + $this->qhsfEmployerCents() + $this->cnesstEmployerCents() + $this->wcEmployerCents();
    }

    /**
     * Total deductions withheld from the employee (statutory effective + voluntary).
     * The QPP/QPIP terms are 0 for the rest of Canada and CPP/CPP2 are 0 for Quebec.
     */
    public function totalEmployeeDeductionsCents(): int
    {
        return $this->cppEmployeeCents()
            + $this->cpp2EmployeeCents()
            + $this->eiEmployeeCents()
            + $this->qppEmployeeCents()
            + $this->qpp2EmployeeCents()
            + $this->qpipEmployeeCents()
            + $this->incomeTaxCents()
            + $this->voluntaryDeductionsCents();
    }

    /**
     * Recompute gross (from earning rows), total deductions and net for the line.
     */
    public function recalculateTotals(): void
    {
        $this->loadMissing('earnings', 'deductions');

        // Bases-only earnings (taxable employer benefits) inflate the CPP/EI/tax
        // bases — so the tax is taken out of net pay — but are never paid in cash:
        // excluded from gross AND net. Box 14 picks them up in T4SlipCalculator.
        $cashEarnings = (int) $this->earnings->reject(fn ($e) => (bool) $e->add_to_bases_only)->sum('amount_cents');

        // Net-pay-only earnings (reimbursements) are paid to the employee but are
        // not employment income: excluded from gross/box-14, included in net.
        $reportable = (int) $this->earnings
            ->reject(fn ($e) => (bool) $e->add_to_net_pay_only || (bool) $e->add_to_bases_only)
            ->sum('amount_cents');

        $deductions = $this->totalEmployeeDeductionsCents();

        $this->forceFill([
            'gross_cents' => $reportable,
            'total_deductions_cents' => $deductions,
            'net_cents' => $cashEarnings - $deductions,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        $casts = [
            'pay_basis' => PayBasis::class,
            'hours_worked' => 'decimal:2',
            'insurable_hours' => 'decimal:2',
        ];

        foreach ([
            'hourly_rate_cents', 'annual_salary_cents', 'regular_earnings_cents', 'gross_cents',
            'cpp_pensionable_cents', 'ei_insurable_cents', 'qpip_insurable_cents',
            'qhsf_employer_computed_cents', 'cnesst_employer_computed_cents', 'wc_employer_computed_cents',
            'vacation_accrued_cents', 'vacation_paid_cents',
            'total_deductions_cents', 'net_cents', 'ytd_pensionable_cents', 'ytd_insurable_cents',
            'ytd_cpp_employee_cents', 'ytd_cpp2_employee_cents', 'ytd_ei_employee_cents',
            'ytd_gross_cents', 'ytd_tax_cents',
            'ytd_qpp_employee_cents', 'ytd_qpp2_employee_cents',
            'ytd_qpip_employee_cents', 'ytd_qpip_insurable_cents',
        ] as $column) {
            $casts[$column] = 'integer';
        }

        foreach (self::COMPONENTS as $component) {
            $casts[$component.'_computed_cents'] = 'integer';
            $casts[$component.'_override_cents'] = 'integer';
        }

        return $casts;
    }
}
