<?php

namespace App\Services\Reporting;

use App\Enums\PayRunStatus;
use App\Models\Company;
use App\Models\PayRunLine;
use Carbon\CarbonImmutable;

/**
 * The payroll register: one row per employee × posted pay run in a date range,
 * with earnings, statutory + voluntary deductions, employer cost and net. Built
 * entirely from the {@see PayRunLine} effective accessors (override ?? computed),
 * so it can never drift from what was actually posted. Quebec QPP/QPIP fold into
 * the CPP/EI columns; both are 0 on the other side, so the columns are branch-free.
 */
class PayrollRegisterCalculator
{
    private const POSTED = [PayRunStatus::Posted->value, PayRunStatus::Paid->value];

    /**
     * @return array<int, array{name: string, run_no: string, pay_date: string, gross_cents: int, cpp_cents: int, ei_cents: int, tax_cents: int, deductions_cents: int, employer_cents: int, net_cents: int}>
     */
    public function rows(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return PayRunLine::query()
            ->where('pay_run_lines.company_id', $company->id)
            ->whereHas('payRun', fn ($q) => $q
                ->whereIn('status', self::POSTED)
                ->whereBetween('pay_date', [$start->toDateString(), $end->toDateString()]))
            ->with(['contact', 'payRun', 'deductions'])
            ->get()
            ->sortBy(fn (PayRunLine $line) => [optional($line->payRun?->pay_date)->toDateString() ?? '', $line->contact->display_name ?? ''])
            ->values()
            ->map(fn (PayRunLine $line) => [
                'name' => (string) ($line->contact->display_name ?? ''),
                'run_no' => (string) ($line->payRun->run_no ?? ''),
                'pay_date' => optional($line->payRun?->pay_date)->toDateString() ?? '',
                'gross_cents' => (int) $line->gross_cents,
                'cpp_cents' => $line->cppEmployeeCents() + $line->cpp2EmployeeCents() + $line->qppEmployeeCents() + $line->qpp2EmployeeCents(),
                'ei_cents' => $line->eiEmployeeCents() + $line->qpipEmployeeCents(),
                'tax_cents' => $line->incomeTaxCents(),
                'deductions_cents' => $line->voluntaryDeductionsCents(),
                'employer_cents' => $line->employerContributionsCents(),
                'net_cents' => (int) $line->net_cents,
            ])
            ->all();
    }

    /**
     * Column totals across the range.
     *
     * @return array{line_count: int, gross_cents: int, cpp_cents: int, ei_cents: int, tax_cents: int, deductions_cents: int, employer_cents: int, net_cents: int}
     */
    public function summary(Company $company, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = $this->rows($company, $start, $end);

        $sum = fn (string $key): int => (int) array_sum(array_column($rows, $key));

        return [
            'line_count' => count($rows),
            'gross_cents' => $sum('gross_cents'),
            'cpp_cents' => $sum('cpp_cents'),
            'ei_cents' => $sum('ei_cents'),
            'tax_cents' => $sum('tax_cents'),
            'deductions_cents' => $sum('deductions_cents'),
            'employer_cents' => $sum('employer_cents'),
            'net_cents' => $sum('net_cents'),
        ];
    }
}
