<?php

use App\Enums\DeadlineDigestFrequency;
use App\Enums\DeadlineStatus;
use App\Enums\PredefinedRole;
use App\Jobs\SendDeadlineNotificationsForCompany;
use App\Models\Company;
use App\Models\Deadline;
use App\Models\DeadlineNotificationLog;
use App\Models\DeadlineNotificationSetting;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use App\Notifications\Deadlines\DeadlineDigestNotification;
use App\Notifications\Deadlines\DeadlineDueSoonNotification;
use App\Notifications\Deadlines\DeadlineOverdueNotification;
use App\Services\Deadlines\DeadlineNotifier;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Notification::fake();
});

/**
 * A company whose local date is $localDate (it's 09:00 there), with a one-off
 * deadline due $dueDate assigned to its owner.
 *
 * @return array{0: Company, 1: User, 2: Deadline}
 */
function deadlineNotificationSetup(string $localDate, string $dueDate, string $timezone = 'America/Vancouver', array $deadline = []): array
{
    test()->travelTo(CarbonImmutable::parse($localDate.' 09:00', $timezone));

    $company = Company::factory()->create(['timezone' => $timezone]);
    app()->instance('current_company', $company);
    $owner = actAsCompanyOwner($company);

    $model = Deadline::factory()->once($dueDate)->create(['assigned_user_id' => $owner->id, 'track_from' => '2020-01-01', ...$deadline]);
    app(DeadlineOccurrenceSync::class)->sync($model, $company->currentDateTime());

    // No digest unless a test asks for one.
    DeadlineNotificationSetting::query()->create(['user_id' => $owner->id, 'digest' => DeadlineDigestFrequency::Off]);

    return [$company, $owner, $model];
}

function runDeadlineNotifier(Company $company): array
{
    return app(DeadlineNotifier::class)->run($company);
}

it('emails the assignee on each lead day, once', function () {
    [$company, $owner] = deadlineNotificationSetup('2026-11-23', '2026-11-30');

    expect(runDeadlineNotifier($company)['due_soon'])->toBe(1)
        ->and(runDeadlineNotifier($company)['due_soon'])->toBe(0);

    Notification::assertSentToTimes($owner, DeadlineDueSoonNotification::class, 1);
    Notification::assertSentTo($owner, DeadlineDueSoonNotification::class, fn ($n) => $n->daysUntil === 7);

    // Between lead days: nothing new. The day before: the 1-day reminder.
    test()->travelTo(CarbonImmutable::parse('2026-11-26 09:00', 'America/Vancouver'));
    expect(runDeadlineNotifier($company)['due_soon'])->toBe(0);

    test()->travelTo(CarbonImmutable::parse('2026-11-29 09:00', 'America/Vancouver'));
    expect(runDeadlineNotifier($company)['due_soon'])->toBe(1);
    Notification::assertSentToTimes($owner, DeadlineDueSoonNotification::class, 2);
});

it('catches up with only the nearest reminder after a missed run', function () {
    // First run is the day before: the 7-day reminder is skipped, only the 1-day one goes.
    [$company, $owner] = deadlineNotificationSetup('2026-11-29', '2026-11-30');

    expect(runDeadlineNotifier($company)['due_soon'])->toBe(1);
    Notification::assertSentToTimes($owner, DeadlineDueSoonNotification::class, 1);
    expect(DeadlineNotificationLog::query()->sole()->dedupe_key)->toEndWith(':1');
});

it('sends overdue reminders the day after, then weekly, up to four times', function () {
    [$company, $owner] = deadlineNotificationSetup('2026-12-01', '2026-11-30');

    expect(runDeadlineNotifier($company)['overdue'])->toBe(1);

    foreach (['2026-12-05' => 0, '2026-12-08' => 1, '2026-12-15' => 1, '2026-12-22' => 1, '2026-12-29' => 0] as $date => $expected) {
        test()->travelTo(CarbonImmutable::parse($date.' 09:00', 'America/Vancouver'));
        expect(runDeadlineNotifier($company)['overdue'])->toBe($expected, "on {$date}");
    }

    Notification::assertSentToTimes($owner, DeadlineOverdueNotification::class, 4);
});

it('stops reminding once the deadline is done', function () {
    [$company, $owner, $deadline] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    $deadline->occurrences()->update(['status' => DeadlineStatus::Done->value]);

    expect(runDeadlineNotifier($company))->toBe(['due_soon' => 0, 'overdue' => 0, 'digest' => 0]);
});

it('counts days in the organization’s own calendar', function (string $timezone) {
    // 00:30 local on Nov 23: a week before Nov 30 there, whatever the UTC date is.
    test()->travelTo(CarbonImmutable::parse('2026-11-23 00:30', $timezone));
    $company = Company::factory()->create(['timezone' => $timezone]);
    app()->instance('current_company', $company);
    $owner = actAsCompanyOwner($company);
    $deadline = Deadline::factory()->once('2026-11-30')->create(['assigned_user_id' => $owner->id, 'track_from' => '2020-01-01']);
    app(DeadlineOccurrenceSync::class)->sync($deadline, $company->currentDateTime());
    DeadlineNotificationSetting::query()->create(['user_id' => $owner->id, 'digest' => DeadlineDigestFrequency::Off]);

    runDeadlineNotifier($company);

    Notification::assertSentTo($owner, DeadlineDueSoonNotification::class, fn ($n) => $n->daysUntil === 7);
})->with(['America/Vancouver', 'Europe/London', 'Asia/Tokyo']);

it('respects a member who turned reminders off', function () {
    [$company, $owner] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    DeadlineNotificationSetting::query()->where('user_id', $owner->id)->update(['email_reminders' => false]);

    expect(runDeadlineNotifier($company)['due_soon'])->toBe(0);
});

it('doesn’t email an assignee who left the organization, or whose account is disabled', function () {
    [$company, $owner, $deadline] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    $former = User::factory()->create();
    $deadline->forceFill(['assigned_user_id' => $former->id])->save();
    $deadline->occurrences()->update(['assigned_user_id' => $former->id]);

    expect(runDeadlineNotifier($company)['due_soon'])->toBe(0);

    $deadline->forceFill(['assigned_user_id' => $owner->id])->save();
    $deadline->occurrences()->update(['assigned_user_id' => $owner->id]);
    $owner->forceFill(['disabled_at' => now()])->save();

    expect(runDeadlineNotifier($company)['due_soon'])->toBe(0);
    Notification::assertNothingSent();
});

it('doesn’t email an assignee whose role can no longer open Deadlines', function () {
    [$company, , $deadline] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    $sales = predefinedRoleMember($company, PredefinedRole::Sales);
    $deadline->forceFill(['assigned_user_id' => $sales->id])->save();
    $deadline->occurrences()->update(['assigned_user_id' => $sales->id]);

    expect(runDeadlineNotifier($company)['due_soon'])->toBe(0);
});

it('sends the weekly digest on Mondays only, and skips an empty one', function () {
    // Nov 23 2026 is a Monday.
    [$company, $owner] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    DeadlineNotificationSetting::query()->where('user_id', $owner->id)->update(['digest' => DeadlineDigestFrequency::Weekly->value]);

    expect(runDeadlineNotifier($company)['digest'])->toBe(1)
        ->and(runDeadlineNotifier($company)['digest'])->toBe(0);

    test()->travelTo(CarbonImmutable::parse('2026-11-24 09:00', 'America/Vancouver'));
    expect(runDeadlineNotifier($company)['digest'])->toBe(0);

    Notification::assertSentTo($owner, DeadlineDigestNotification::class, fn ($n) => $n->occurrences->count() === 1);

    // A Monday with nothing coming up sends nothing.
    test()->travelTo(CarbonImmutable::parse('2027-03-01 09:00', 'America/Vancouver'));
    $company->refresh();
    DeadlineOccurrence::query()->update(['status' => DeadlineStatus::Done->value]);
    expect(runDeadlineNotifier($company)['digest'])->toBe(0);
});

it('puts unassigned deadlines in the digest of members who can complete them', function () {
    [$company, $owner, $deadline] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    $deadline->forceFill(['assigned_user_id' => null])->save();
    $deadline->occurrences()->update(['assigned_user_id' => null]);
    DeadlineNotificationSetting::query()->where('user_id', $owner->id)->update(['digest' => DeadlineDigestFrequency::Daily->value]);
    $viewOnly = predefinedRoleMember($company, PredefinedRole::ViewOnly);

    runDeadlineNotifier($company);

    Notification::assertSentTo($owner, DeadlineDigestNotification::class);
    Notification::assertNotSentTo($viewOnly, DeadlineDigestNotification::class);
});

it('renders the emails with the deadline and a link to it', function () {
    Notification::fake();
    [$company, $owner, $deadline] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    $occurrence = $deadline->occurrences()->first();

    $mail = (new DeadlineDueSoonNotification($occurrence, $company, 7))->toMail($owner);

    expect($mail->subject)->toContain($deadline->title)->toContain($company->name)
        ->and($mail->actionUrl)->toContain('/deadlines')->toContain('occurrence='.$occurrence->id);
});

it('renders in the recipient’s language', function () {
    [$company, $owner, $deadline] = deadlineNotificationSetup('2026-11-23', '2026-11-30');
    $owner->forceFill(['locale' => 'fr'])->save();

    app()->setLocale('fr');
    $mail = (new DeadlineOverdueNotification($deadline->occurrences()->first(), $company, 3))->toMail($owner);
    app()->setLocale('en');

    expect($mail->subject)->toStartWith('En retard');
});

it('runs from the command once per local day, from 07:00', function () {
    test()->travelTo(CarbonImmutable::parse('2026-11-23 06:30', 'America/Vancouver'));
    $company = Company::factory()->create(['timezone' => 'America/Vancouver']);
    Queue::fake();

    $this->artisan('deadlines:notify')->assertSuccessful();
    Queue::assertNothingPushed();

    test()->travelTo(CarbonImmutable::parse('2026-11-23 07:05', 'America/Vancouver'));
    $this->artisan('deadlines:notify')->assertSuccessful();
    $this->artisan('deadlines:notify')->assertSuccessful();

    Queue::assertPushed(SendDeadlineNotificationsForCompany::class, 1);
});
