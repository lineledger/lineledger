<?php

namespace App\Jobs;

use App\Enums\RecurrenceEndType;
use App\Enums\Section;
use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\ReportEmailSchedule;
use App\Models\User;
use App\Notifications\Reports\ReportEmailNotification;
use App\Services\Recurring\NextRunDateCalculator;
use App\Support\Reporting\RenderableReports;
use App\Support\Reporting\ReportCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Emails every due report schedule for one company. Isolated per company so a
 * slow or erroring tenant cannot block the others. "Today" is evaluated in the
 * company's own timezone so a schedule anchored to a calendar day fires on that
 * company's day, not UTC's.
 *
 * Missed runs are never caught up: a schedule whose next_run_date is far in the
 * past sends exactly once, then next_run_date is fast-forwarded past today —
 * resending stale copies of the same report is noise, unlike draft generation.
 */
class SendScheduledReportEmailsForCompany implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $companyId) {}

    public function handle(NextRunDateCalculator $calculator): void
    {
        $company = Company::query()->find($this->companyId);

        // Deleted between dispatch and execution: nothing to do, and not a
        // failure worth a failed_jobs row and an ops alert.
        if (! $company) {
            Log::info('Skipping scheduled report emails: company no longer exists.', ['company_id' => $this->companyId]);

            return;
        }

        $this->sendDue($company, $calculator);
    }

    /**
     * Send every due schedule once and advance it. Returns the number of
     * notifications dispatched (one per renderable target report).
     */
    public function sendDue(Company $company, NextRunDateCalculator $calculator): int
    {
        $today = $company->currentDateTime()->startOfDay();
        $sent = 0;

        ReportEmailSchedule::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->due($today->toDateString())
            ->orderBy('id')
            ->each(function (ReportEmailSchedule $schedule) use ($company, $calculator, $today, &$sent): void {
                $sent += $this->process($schedule, $company, $calculator, $today);
            });

        return $sent;
    }

    protected function process(
        ReportEmailSchedule $schedule,
        Company $company,
        NextRunDateCalculator $calculator,
        CarbonImmutable $today,
    ): int {
        // A schedule sends its owner's reports on their behalf, so it may only
        // send what they could still open right now: re-check the owner before
        // every run rather than trusting access they had when it was created.
        $user = $schedule->user;
        $problem = $this->ownerAccessProblem($user, $company);

        if ($problem !== null || $user === null) {
            $this->pause($schedule, $problem ?? __('The schedule’s owner no longer has an account.'));

            return 0;
        }

        $reports = $this->renderableTargets($schedule);

        if ($reports === []) {
            $this->pause($schedule, $schedule->memorized_report_id !== null
                ? __('The memorized report no longer exists or cannot be emailed.')
                : __('The group has no reports that can be emailed.'));

            return 0;
        }

        $refused = array_filter($reports, fn (MemorizedReport $report): bool => ! ReportCatalog::allows($company, $user, $report->report_key));

        if ($refused !== []) {
            $this->pause($schedule, __('This schedule includes a report its owner can no longer open.'));

            return 0;
        }

        foreach ($reports as $report) {
            $entry = RenderableReports::get($report->report_key);

            Notification::route('mail', $schedule->recipients)->notify(new ReportEmailNotification(
                company: $company,
                reportKey: $report->report_key,
                reportLabel: $entry['label'],
                settings: $report->settings ?? [],
                subjectLine: $schedule->subject,
                body: $schedule->body,
                attachXlsx: $schedule->attach_xlsx && RenderableReports::supports($report->report_key, 'xlsx'),
                resolvePresets: true,
                replyToAddress: $user->email,
                senderName: $user->name,
                senderUserId: $user->id,
            ));
        }

        $this->advance($schedule, $calculator, $today);

        return count($reports);
    }

    /**
     * Why the schedule's owner may no longer send this company's reports, or
     * null when they still may: the owner must have an enabled account, still
     * belong to the company, and be able to open its Reports section (enabled
     * by the site admin and granted to them).
     */
    protected function ownerAccessProblem(?User $user, Company $company): ?string
    {
        return match (true) {
            $user === null => __('The schedule’s owner no longer has an account.'),
            $user->isDisabled() => __('The schedule’s owner’s account is disabled.'),
            ! $user->belongsToCompany($company) => __('The schedule’s owner is no longer a member of this organization.'),
            ! $company->sectionEnabled(Section::Reports),
            ! $user->canAccessSection($company, Section::Reports) => __('The schedule’s owner no longer has access to Reports.'),
            default => null,
        };
    }

    /**
     * Stop the schedule and record why, for the owner (or whoever takes it
     * over) to see on the schedules list.
     */
    protected function pause(ReportEmailSchedule $schedule, string $reason): void
    {
        $schedule->is_active = false;
        $schedule->paused_reason = $reason;
        $schedule->save();

        Log::info('Paused a scheduled report email.', [
            'schedule_id' => $schedule->id,
            'company_id' => $schedule->company_id,
            'reason' => $reason,
        ]);
    }

    /**
     * The memorized reports this schedule should email, filtered to ones that
     * can actually render a PDF. Empty when the target was deleted or nothing
     * in it is renderable — the caller pauses the schedule instead of crashing.
     *
     * @return list<MemorizedReport>
     */
    protected function renderableTargets(ReportEmailSchedule $schedule): array
    {
        if ($schedule->memorized_report_id !== null) {
            $candidates = collect([$schedule->memorizedReport()->withoutGlobalScopes()->first()]);
        } elseif ($schedule->memorized_report_group_id !== null) {
            $group = $schedule->memorizedReportGroup()->withoutGlobalScopes()->first();
            $candidates = $group?->memorizedReports()->withoutGlobalScopes()->get() ?? collect();
        } else {
            $candidates = collect();
        }

        return $candidates
            ->filter(fn (?MemorizedReport $report): bool => $report !== null
                && RenderableReports::supports($report->report_key, 'pdf'))
            ->values()
            ->all();
    }

    /**
     * One send per run regardless of how many runs were missed: advance
     * next_run_date hop by hop until it lands after today, then apply the
     * schedule's end rule.
     */
    protected function advance(ReportEmailSchedule $schedule, NextRunDateCalculator $calculator, CarbonImmutable $today): void
    {
        $schedule->occurrences_generated = (int) $schedule->occurrences_generated + 1;
        $schedule->last_sent_at = now();

        $current = $schedule->next_run_date !== null
            ? CarbonImmutable::parse($schedule->next_run_date->toDateString())
            : $today;

        $next = $calculator->next($schedule, $current);
        while ($next->lessThanOrEqualTo($today)) {
            $next = $calculator->next($schedule, $next);
        }
        $schedule->next_run_date = $next;

        $this->applyEndRule($schedule);
        $schedule->save();
    }

    protected function applyEndRule(ReportEmailSchedule $schedule): void
    {
        switch ($schedule->end_type) {
            case RecurrenceEndType::OnDate:
                if ($schedule->end_date !== null
                    && $schedule->next_run_date !== null
                    && $schedule->next_run_date->greaterThan($schedule->end_date)) {
                    $schedule->is_active = false;
                    $schedule->next_run_date = null;
                }
                break;

            case RecurrenceEndType::AfterOccurrences:
                if ($schedule->max_occurrences !== null
                    && (int) $schedule->occurrences_generated >= (int) $schedule->max_occurrences) {
                    $schedule->is_active = false;
                    $schedule->next_run_date = null;
                }
                break;

            case RecurrenceEndType::Never:
            default:
                break;
        }
    }
}
