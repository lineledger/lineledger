<?php

use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Jobs\SendScheduledReportEmailsForCompany;
use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\ReportEmailSchedule;
use App\Models\User;
use App\Notifications\Reports\ReportEmailNotification;
use App\Services\Recurring\NextRunDateCalculator;
use App\Services\Security\AccessRevoker;
use Illuminate\Support\Facades\Notification;

/**
 * A report email schedule sends its owner's reports on their behalf, so every
 * run re-checks that the owner may still open them — and deprovisioning pauses
 * the schedules straight away (R07, audit D9Reporting-03: emails kept going
 * after the owner was removed or lost Reports access).
 */
beforeEach(function () {
    Notification::fake();

    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->owner = User::factory()->create();
    $this->membership = $this->company->memberships()->create([
        'user_id' => $this->owner->id,
        'role' => CompanyRole::Accountant,
    ]);

    app()->instance('current_company', $this->company);

    $this->schedule = function (Company $company, User $user, string $reportKey = 'reports.income-statement'): ReportEmailSchedule {
        $report = MemorizedReport::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'report_key' => $reportKey,
            'name' => 'Monthly report',
            'settings' => ['preset' => 'last_month'],
        ]);
        $today = $company->currentDateTime()->startOfDay();

        return ReportEmailSchedule::create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'memorized_report_id' => $report->id,
            'recipients' => ['board@example.com'],
            'frequency' => 'monthly',
            'start_date' => $today->toDateString(),
            'day_of_month' => (int) $today->format('j'),
            'end_type' => 'never',
            'next_run_date' => $today->toDateString(),
            'is_active' => true,
        ]);
    };

    $this->run = fn (): int => (new SendScheduledReportEmailsForCompany($this->company->id))
        ->sendDue($this->company, app(NextRunDateCalculator::class));
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('sends while the owner can still open the report', function () {
    ($this->schedule)($this->company, $this->owner);

    expect(($this->run)())->toBe(1);
    Notification::assertSentTimes(ReportEmailNotification::class, 1);
});

it('pauses instead of sending when the owner has lost access', function (Closure $revoke, string $reason) {
    $schedule = ($this->schedule)($this->company, $this->owner);

    $revoke($this);

    expect(($this->run)())->toBe(0);
    Notification::assertNothingSent();

    $schedule->refresh();
    expect($schedule->is_active)->toBeFalse()
        ->and($schedule->paused_reason)->toContain($reason);
})->with([
    'removed from the company' => [fn ($test) => $test->membership->delete(), 'no longer a member'],
    'account disabled' => [fn ($test) => $test->owner->forceFill(['disabled_at' => now()])->save(), 'disabled'],
    'Reports taken away' => [fn ($test) => $test->membership->update([
        'role' => CompanyRole::Custom,
        'sections' => [Section::Customers->value],
    ]), 'access to Reports'],
]);

it('pauses when a report in the schedule is no longer one the owner can open', function () {
    // The membership roster only exists for companies that track membership.
    $schedule = ($this->schedule)($this->company, $this->owner, 'reports.membership-roster');

    expect(($this->run)())->toBe(0);
    expect($schedule->refresh()->is_active)->toBeFalse()
        ->and($schedule->paused_reason)->toContain('can no longer open');
});

it('pauses the removed member\'s schedules in that company only', function () {
    $other = Company::factory()->create();
    $other->memberships()->create(['user_id' => $this->owner->id, 'role' => CompanyRole::Accountant]);

    $here = ($this->schedule)($this->company, $this->owner);
    app()->instance('current_company', $other);
    $elsewhere = ($this->schedule)($other, $this->owner);

    $this->membership->delete();
    app(AccessRevoker::class)->revokeForRemoval($this->owner, $this->company);

    expect($here->refresh()->is_active)->toBeFalse()
        ->and($elsewhere->refresh()->is_active)->toBeTrue();
});

it('pauses schedules on a downgrade only when Reports is lost', function () {
    $schedule = ($this->schedule)($this->company, $this->owner);

    // Narrowed, but still holding Reports: the schedule keeps running.
    $this->membership->update(['role' => CompanyRole::Custom, 'sections' => [Section::Reports->value]]);
    app(AccessRevoker::class)->revokeForDowngrade($this->owner, $this->company);
    expect($schedule->refresh()->is_active)->toBeTrue();

    $this->membership->update(['sections' => [Section::Customers->value]]);
    app(AccessRevoker::class)->revokeForDowngrade($this->owner, $this->company);
    expect($schedule->refresh()->is_active)->toBeFalse()
        ->and($schedule->paused_reason)->toContain('access to Reports');
});

it('pauses the disabled user\'s schedules in every company', function () {
    $other = Company::factory()->create();
    $other->memberships()->create(['user_id' => $this->owner->id, 'role' => CompanyRole::Accountant]);

    $here = ($this->schedule)($this->company, $this->owner);
    app()->instance('current_company', $other);
    $elsewhere = ($this->schedule)($other, $this->owner);

    app(AccessRevoker::class)->revokeForAccountDisabled($this->owner);

    expect($here->refresh()->is_active)->toBeFalse()
        ->and($elsewhere->refresh()->is_active)->toBeFalse();
});

it('moves a removed member\'s current company off the company they left', function () {
    $this->owner->forceFill(['current_company_id' => $this->company->id])->save();
    $personal = $this->owner->personalCompany();

    $this->membership->delete();
    app(AccessRevoker::class)->revokeForRemoval($this->owner, $this->company);

    expect($this->owner->fresh()->current_company_id)->toBe($personal?->id);
});
