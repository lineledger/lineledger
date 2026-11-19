<?php

use App\Actions\Deadlines\ArchiveDeadline;
use App\Actions\Deadlines\CompleteDeadlineOccurrence;
use App\Actions\Deadlines\SaveDeadline;
use App\Actions\Deadlines\UpdateDeadlineOccurrence;
use App\Enums\AuditAction;
use App\Enums\DeadlineStatus;
use App\Enums\PredefinedRole;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Deadline;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use App\Services\Deadlines\DeadlineFeed;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use App\Services\Security\AccessRevoker;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00', 'America/Vancouver'));
    $this->company = Company::factory()->create(['timezone' => 'America/Vancouver', 'fiscal_year_start_month' => 8]);
    app()->instance('current_company', $this->company);
    $this->owner = actAsCompanyOwner($this->company);
});

function saveQuarterlyDeadline(Company $company, array $overrides = []): Deadline
{
    return app(SaveDeadline::class)->handle($company, [
        'title' => 'GST/HST return',
        'category' => 'sales_tax',
        'kind' => 'file_and_pay',
        'frequency' => 'quarterly',
        'period_anchor' => '2026-08-01',
        'track_from' => '2026-08-01',
        'due_months_after' => 1,
        'due_day_rule' => 'last_day',
        'weekend_adjustment' => 'keep',
        'end_type' => 'never',
        'notify_days_before' => [7, 1],
        ...$overrides,
    ]);
}

it('materializes occurrences through the horizon, from the track-from date', function () {
    $deadline = saveQuarterlyDeadline($this->company);

    $dues = $deadline->occurrences()->orderBy('due_date')->pluck('due_date')->map->toDateString()->all();

    expect($dues[0])->toBe('2026-11-30')
        ->and(end($dues))->toBeLessThanOrEqual('2028-02-18')
        ->and(count($dues))->toBe(5);
});

it('keeps done and edited occurrences when the rule changes, and rewrites the rest', function () {
    $deadline = saveQuarterlyDeadline($this->company);
    [$first, $second] = $deadline->occurrences()->orderBy('due_date')->take(2)->get();

    app(CompleteDeadlineOccurrence::class)->handle($this->company, $first, ['completed_on' => '2026-11-20']);
    app(UpdateDeadlineOccurrence::class)->handle($this->company, $second, ['due_date' => '2027-03-05', 'notes' => 'Extension granted']);

    // Move every due date to the 15th.
    app(SaveDeadline::class)->handle($this->company, [
        ...$deadline->only(['title']),
        'category' => 'sales_tax', 'kind' => 'file_and_pay', 'frequency' => 'quarterly', 'period_anchor' => '2026-08-01',
        'due_months_after' => 1, 'due_day_rule' => 'day_of_month', 'due_day_of_month' => 15,
        'weekend_adjustment' => 'keep', 'end_type' => 'never',
    ], $deadline);

    expect($first->refresh()->status)->toBe(DeadlineStatus::Done)
        ->and($first->due_date->toDateString())->toBe('2026-11-30')
        ->and($second->refresh()->due_date->toDateString())->toBe('2027-03-05')
        ->and($deadline->occurrences()->where('status', 'open')->whereNull('customized_at')->pluck('due_date')->map->day->unique()->values()->all())->toBe([15]);
});

it('keeps track_from on an edit that leaves it blank', function () {
    $deadline = saveQuarterlyDeadline($this->company);

    app(SaveDeadline::class)->handle($this->company, [
        'title' => 'GST/HST return', 'category' => 'sales_tax', 'kind' => 'file_and_pay', 'frequency' => 'quarterly',
        'period_anchor' => '2026-08-01', 'due_months_after' => 1, 'due_day_rule' => 'last_day',
        'weekend_adjustment' => 'keep', 'end_type' => 'never', 'track_from' => '',
    ], $deadline);

    expect($deadline->refresh()->track_from->toDateString())->toBe('2026-08-01');
});

it('refuses a second completion', function () {
    $occurrence = saveQuarterlyDeadline($this->company)->occurrences()->first();

    app(CompleteDeadlineOccurrence::class)->handle($this->company, $occurrence, ['completed_on' => '2026-11-20']);

    expect(fn () => app(CompleteDeadlineOccurrence::class)->handle($this->company, $occurrence, ['completed_on' => '2026-11-21']))
        ->toThrow(ValidationException::class);
});

it('audits creating a deadline and completing an occurrence', function () {
    $deadline = saveQuarterlyDeadline($this->company);
    $occurrence = $deadline->occurrences()->first();
    app(CompleteDeadlineOccurrence::class)->handle($this->company, $occurrence, ['completed_on' => '2026-11-20', 'amount' => '1500']);

    $actions = AccountingAuditLog::query()->pluck('action')->map->value->all();

    expect($actions)->toContain(AuditAction::DeadlineCreated->value, AuditAction::DeadlineOccurrenceCompleted->value);
});

it('archives a deadline: untouched open dates go, history stays', function () {
    $deadline = saveQuarterlyDeadline($this->company);
    $done = $deadline->occurrences()->first();
    app(CompleteDeadlineOccurrence::class)->handle($this->company, $done, ['completed_on' => '2026-11-20']);

    app(ArchiveDeadline::class)->archive($this->company, $deadline);

    expect($deadline->occurrences()->pluck('id')->all())->toBe([$done->id])
        ->and(DeadlineFeed::for($this->company)->badge($this->owner)['count'])->toBe(0);
});

it('pauses a deadline: no future dates, overdue ones stay owed', function () {
    $deadline = saveQuarterlyDeadline($this->company, ['period_anchor' => '2026-05-01', 'track_from' => '2026-05-01']);

    app(ArchiveDeadline::class)->pause($this->company, $deadline);

    $dues = $deadline->occurrences()->pluck('due_date')->map->toDateString()->all();

    expect($dues)->toBe(['2026-08-31']);
});

it('rejects an assignee who can’t complete deadlines', function () {
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);

    expect(fn () => saveQuarterlyDeadline($this->company, ['assigned_user_id' => $sales->id]))
        ->toThrow(ValidationException::class);

    $outsider = User::factory()->create();

    expect(fn () => saveQuarterlyDeadline($this->company, ['assigned_user_id' => $outsider->id]))
        ->toThrow(ValidationException::class);
});

it('lets a payroll manager complete deadlines and keeps view-only members to reading', function () {
    $occurrence = saveQuarterlyDeadline($this->company)->occurrences()->first();

    $payroll = predefinedRoleMember($this->company, PredefinedRole::PayrollManager);
    Livewire::actingAs($payroll)->test('pages::deadlines.occurrence-dialog', ['company' => $this->company])
        ->call('open', $occurrence->id, 'complete')
        ->call('complete')
        ->assertHasNoErrors();

    expect($occurrence->refresh()->status)->toBe(DeadlineStatus::Done);

    $viewOnly = predefinedRoleMember($this->company, PredefinedRole::ViewOnly);
    $this->actingAs($viewOnly)->get(route('deadlines.index', ['company' => $this->company]))->assertOk();
    $this->actingAs($viewOnly)->get(route('deadlines.create', ['company' => $this->company]))->assertForbidden();

    Livewire::actingAs($viewOnly)->test('pages::deadlines.occurrence-dialog', ['company' => $this->company])
        ->call('open', $occurrence->id, 'complete')
        ->assertSet('mode', 'view')
        ->call('reopen')
        ->assertForbidden();
});

it('keeps Deadlines away from roles without the activity', function () {
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);

    $this->actingAs($sales)->get(route('deadlines.index', ['company' => $this->company]))->assertForbidden();
    $this->actingAs($sales)->get(route('dashboard', ['company' => $this->company]))
        ->assertOk()
        ->assertDontSee('data-test="sidebar-deadlines"', false);
});

it('can’t open another organization’s occurrence', function () {
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $foreign = Deadline::factory()->create();
    app(DeadlineOccurrenceSync::class)->sync($foreign);
    $foreignOccurrence = DeadlineOccurrence::query()->first();
    app()->instance('current_company', $this->company);

    Livewire::test('pages::deadlines.occurrence-dialog', ['company' => $this->company])
        ->call('open', $foreignOccurrence->id)
        ->assertSet('occurrenceId', null);

    $this->get(route('deadlines.show', ['company' => $this->company, 'deadline' => $foreign->id]))->assertNotFound();
});

it('unassigns a removed member’s open deadlines but keeps who completed past ones', function () {
    $bookkeeper = predefinedRoleMember($this->company, PredefinedRole::Bookkeeper);
    $deadline = saveQuarterlyDeadline($this->company, ['assigned_user_id' => $bookkeeper->id]);
    $done = $deadline->occurrences()->first();
    app(CompleteDeadlineOccurrence::class)->handle($this->company, $done, ['completed_on' => '2026-11-20', 'completed_by_user_id' => $bookkeeper->id]);

    app(AccessRevoker::class)->revokeForRemoval($bookkeeper, $this->company);

    expect($deadline->refresh()->assigned_user_id)->toBeNull()
        ->and($deadline->occurrences()->where('status', 'open')->whereNotNull('assigned_user_id')->exists())->toBeFalse()
        ->and($done->refresh()->completed_by_user_id)->toBe($bookkeeper->id);
});

it('counts the sidebar badge from my deadlines and the unassigned ones', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00', 'America/Vancouver'));
    saveQuarterlyDeadline($this->company, ['assigned_user_id' => $this->owner->id]);
    saveQuarterlyDeadline($this->company, ['title' => 'Unassigned one']);

    expect(DeadlineFeed::for($this->company)->badge($this->owner))->toBe(['count' => 2, 'overdue' => false]);

    $this->travelTo(CarbonImmutable::parse('2026-12-01 10:00', 'America/Vancouver'));
    expect(DeadlineFeed::for($this->company)->badge($this->owner))->toBe(['count' => 2, 'overdue' => true]);

    $this->get(route('dashboard', ['company' => $this->company]))
        ->assertOk()
        ->assertSee('data-test="sidebar-deadlines"', false)
        ->assertSee('data-test="upcoming-deadlines"', false)
        ->assertSee('GST/HST return');
});

it('shows a due date as due today, not overdue, in the organization’s timezone', function () {
    // 23:30 in Vancouver on Nov 30 is already Dec 1 in UTC.
    $this->travelTo(CarbonImmutable::parse('2026-11-30 23:30', 'America/Vancouver'));
    $occurrence = saveQuarterlyDeadline($this->company)->occurrences()->orderBy('due_date')->first();

    expect($occurrence->due_date->toDateString())->toBe('2026-11-30')
        ->and($occurrence->stateOn(DeadlineFeed::for($this->company)->today())->value)->toBe('due_today');
});
