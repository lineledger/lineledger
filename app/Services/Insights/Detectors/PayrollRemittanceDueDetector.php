<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Enums\PayRunStatus;
use App\Enums\RemittanceAgency;
use App\Enums\RemittanceFrequency;
use App\Enums\RemittanceStatus;
use App\Models\Company;
use App\Models\PayrollRemittance;
use App\Models\PayRun;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Payroll\RemittancePeriodResolver;
use Carbon\CarbonImmutable;

/**
 * A CRA source-deduction due date is approaching (or just slipped) for a
 * remittance period that had posted payroll but no recorded remittance — the
 * PD7A page records one (agency CRA, status Paid) keyed on the period start,
 * so absence means outstanding. No dollar amount in v1; the PD7A report has
 * the figures. Marked urgent near/past the due date so it beats the
 * anti-repeat window.
 */
final class PayrollRemittanceDueDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const UPCOMING_WINDOW_DAYS = 7;

    private const OVERDUE_GRACE_DAYS = 5;

    public function __construct(private readonly RemittancePeriodResolver $resolver) {}

    public function key(): string
    {
        return 'payroll-remittance-due';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Deadline;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        if (! $company->usesPayroll()) {
            return [];
        }

        $frequency = $company->getAttribute('payroll_remittance_frequency');

        if (! $frequency instanceof RemittanceFrequency) {
            return []; // no remitter frequency configured yet
        }

        $todayStart = $today->startOfDay();

        $chosen = null;
        $chosenDays = 0;

        foreach ($this->resolver->periods($frequency, $today) as $period) {
            $days = (int) $todayStart->diffInDays($period['due']->startOfDay(), false);

            if ($days < -self::OVERDUE_GRACE_DAYS || $days > self::UPCOMING_WINDOW_DAYS) {
                continue;
            }

            // The most urgent qualifying period wins (earliest due date).
            if ($chosen !== null && $period['due']->gte($chosen['due'])) {
                continue;
            }

            if (! $this->hasPostedPayRunInside($company, $period['start'], $period['end'])) {
                continue;
            }

            if ($this->remittanceRecorded($company, $period['key'])) {
                continue;
            }

            $chosen = $period;
            $chosenDays = $days;
        }

        if ($chosen === null) {
            return [];
        }

        $overdue = $chosenDays < 0;
        $dueDisplay = $this->formatDay($chosen['due']);
        $frequencyLabel = $this->frequencyLabel($frequency);

        $headline = $overdue
            ? __('Payroll remittance was due :due', ['due' => $dueDisplay])
            : __('Payroll remittance due :due', ['due' => $dueDisplay]);

        $body = $overdue
            ? __("The :period source-deduction remittance hasn't been recorded yet. Check the PD7A report for the amounts.", ['period' => $chosen['label']])
            : __("Your :frequency source-deduction remittance for :period is due :due. The PD7A report has the figures when you're ready.", [
                'frequency' => $frequencyLabel,
                'period' => $chosen['label'],
                'due' => $dueDisplay,
            ]);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: $chosenDays <= 3 ? 95 : 90,
            facts: [
                'period_label' => $chosen['label'],
                'due_date' => $chosen['due']->toDateString(),
                'due_date_display' => $dueDisplay,
                'days_until_due' => $chosenDays,
                'frequency_label' => $frequencyLabel,
            ],
            headline: $headline,
            body: $body,
            urgent: $chosenDays <= 1,
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'payroll.reports.pd7a', 'label' => __('Open PD7A report')];
    }

    /**
     * Whether remuneration was actually paid inside the period — mirrors the
     * PD7A calculator: posted/paid runs by pay date.
     */
    private function hasPostedPayRunInside(Company $company, CarbonImmutable $start, CarbonImmutable $end): bool
    {
        return PayRun::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->whereIn('status', [PayRunStatus::Posted->value, PayRunStatus::Paid->value])
            ->whereBetween('pay_date', [$start->toDateString(), $end->toDateString()])
            ->exists();
    }

    /**
     * Whether the PD7A page already recorded this period's CRA remittance
     * (rows exist once paid; a voided row leaves the period outstanding).
     */
    private function remittanceRecorded(Company $company, string $periodKey): bool
    {
        return PayrollRemittance::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('agency', RemittanceAgency::Cra->value)
            ->where('status', RemittanceStatus::Paid->value)
            ->whereDate('period_start', $periodKey)
            ->exists();
    }

    /** Plain in-sentence wording, unlike the enum's form-input label(). */
    private function frequencyLabel(RemittanceFrequency $frequency): string
    {
        return match ($frequency) {
            RemittanceFrequency::Monthly => __('monthly'),
            RemittanceFrequency::Quarterly => __('quarterly'),
            RemittanceFrequency::Accelerated1 => __('twice-monthly'),
            RemittanceFrequency::Accelerated2 => __('accelerated'),
        };
    }
}
