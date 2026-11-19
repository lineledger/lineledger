<?php

namespace App\Services\Deadlines;

use App\Enums\DeadlineDigestFrequency;
use App\Enums\DeadlineNotificationKind;
use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\DeadlineNotificationLog;
use App\Models\DeadlineNotificationSetting;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use App\Notifications\Deadlines\DeadlineDigestNotification;
use App\Notifications\Deadlines\DeadlineDueSoonNotification;
use App\Notifications\Deadlines\DeadlineOverdueNotification;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sends one organization's deadline emails for its `$today`: the assignee's
 * "due soon" reminder on each lead day, "overdue" the day after and then
 * weekly, and each member's daily or weekly digest.
 *
 * Every email is claimed in `deadline_notification_logs` (a unique key) before
 * it is sent, so reruns — the scheduler runs hourly — and overlapping workers
 * never send one twice. A run that was missed catches up with only the most
 * recent reminder, never a burst. Recipients are re-checked every time: still a
 * member, account active, still allowed to open Deadlines, and opted in.
 *
 * Expects the organization to be bound as the current company.
 */
final class DeadlineNotifier
{
    /** Overdue reminders: the day after, then every this many days… */
    public const int OVERDUE_EVERY_DAYS = 7;

    /** …at most this many times. */
    public const int OVERDUE_MAX = 4;

    public function __construct(private readonly DeadlineOccurrenceSync $sync) {}

    /**
     * @return array{due_soon: int, overdue: int, digest: int}
     */
    public function run(Company $company, ?CarbonImmutable $today = null): array
    {
        // Midnight in the app timezone, like the stored dates, so day counts are exact.
        $today = CarbonImmutable::createFromFormat('!Y-m-d', ($today ?? $company->currentDateTime())->toDateString());
        $this->sync->extend($company);

        $members = $company->members()->get()->keyBy('id');
        $settings = DeadlineNotificationSetting::query()->get()->keyBy('user_id');

        /** @var array<int, bool> $canView */
        $canView = [];
        $viewer = function (int $userId) use ($members, $company, &$canView): ?User {
            $user = $members->get($userId);

            if (! $user instanceof User) {
                return null;
            }

            $canView[$userId] ??= DeadlineAssignees::canView($user, $company);

            return $canView[$userId] ? $user : null;
        };

        $sent = ['due_soon' => 0, 'overdue' => 0, 'digest' => 0];

        foreach ($this->assignedOpen($today) as $occurrence) {
            $user = $viewer((int) $occurrence->assigned_user_id);

            if ($user === null || ! ($settings->get($user->id)->email_reminders ?? true)) {
                continue;
            }

            $daysUntil = (int) $today->diffInDays($occurrence->due_date, false);

            if ($daysUntil >= 0) {
                $lead = collect($occurrence->deadline->leadDays())->filter(fn (int $n) => $daysUntil <= $n)->min();

                if ($lead !== null && $this->claim($company, $occurrence, $user, DeadlineNotificationKind::DueSoon, "due_soon:{$occurrence->id}:{$user->id}:{$lead}", $today,
                    new DeadlineDueSoonNotification($occurrence, $company, $daysUntil))) {
                    $sent['due_soon']++;
                }

                continue;
            }

            $daysOverdue = -$daysUntil;
            $step = intdiv($daysOverdue - 1, self::OVERDUE_EVERY_DAYS);

            if ($step < self::OVERDUE_MAX && $this->claim($company, $occurrence, $user, DeadlineNotificationKind::Overdue, "overdue:{$occurrence->id}:{$user->id}:{$step}", $today,
                new DeadlineOverdueNotification($occurrence, $company, $daysOverdue))) {
                $sent['overdue']++;
            }
        }

        $feed = DeadlineFeed::for($company);

        foreach ($members as $member) {
            $digest = $settings->get($member->id)->digest ?? DeadlineDigestFrequency::Weekly;

            if ($digest === DeadlineDigestFrequency::Off
                || ($digest === DeadlineDigestFrequency::Weekly && ! $today->isMonday())
                || $viewer($member->id) === null) {
                continue;
            }

            $occurrences = $feed->needingAttention($member, $digest->lookaheadDays(), $feed->mayComplete($member));

            if ($occurrences->isEmpty()) {
                continue;
            }

            if ($this->claim($company, null, $member, DeadlineNotificationKind::Digest, "digest:{$member->id}:{$today->toDateString()}", $today,
                new DeadlineDigestNotification($occurrences, $company, $today, $digest->lookaheadDays()))) {
                $sent['digest']++;
            }
        }

        return $sent;
    }

    /**
     * Open, assigned occurrences of current deadlines due within the longest
     * lead time, or overdue within the overdue window.
     *
     * @return Collection<int, DeadlineOccurrence>
     */
    private function assignedOpen(CarbonImmutable $today): Collection
    {
        $overdueWindow = self::OVERDUE_EVERY_DAYS * self::OVERDUE_MAX;

        return DeadlineOccurrence::query()
            ->ofCurrentDeadlines()
            ->with('deadline')
            ->where('status', DeadlineStatus::Open->value)
            ->whereNotNull('assigned_user_id')
            ->whereBetween('due_date', [$today->subDays($overdueWindow)->toDateString(), $today->addDays(90)->toDateString()])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Claim the dedupe key, then send. False when it was already sent (or the
     * claim lost a race). A failed send releases the claim so a rerun retries.
     */
    private function claim(
        Company $company,
        ?DeadlineOccurrence $occurrence,
        User $user,
        DeadlineNotificationKind $kind,
        string $key,
        CarbonImmutable $today,
        Notification $notification,
    ): bool {
        if (DeadlineNotificationLog::query()->where('dedupe_key', $key)->exists()) {
            return false;
        }

        try {
            $log = new DeadlineNotificationLog([
                'deadline_occurrence_id' => $occurrence?->id,
                'user_id' => $user->id,
                'kind' => $kind,
                'dedupe_key' => $key,
                'sent_on' => $today->toDateString(),
            ]);
            $log->company_id = $company->id;
            $log->save();
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            $log->delete();

            throw $e;
        }

        return true;
    }
}
