<?php

use App\Actions\Payroll\SaveTimeEntry;
use App\Actions\Payroll\SetTimeEntryStatus;
use App\Actions\Portal\SaveOwnTimeEntry;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Enums\TimeEntryStatus;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    app()->instance('current_company', $this->company);

    $this->employee = Contact::create(['display_name' => 'Audit Andy', 'email' => 'andy@emp.test', 'is_employee' => true, 'is_active' => true]);

    /** Latest audit row for a given action. */
    $this->latestLog = fn (AuditAction $action): ?AccountingAuditLog => AccountingAuditLog::query()
        ->where('action', $action->value)
        ->orderByDesc('sequence')
        ->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

// --- Staff create / edit -------------------------------------------------

it('writes a created audit row with a snapshot when staff log time', function () {
    actingAs($this->user);

    $entry = app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-02',
        'hours' => 8,
        'description' => 'Site visit',
    ]);

    $log = ($this->latestLog)(AuditAction::TimeEntryCreated);

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBe($this->user->id)
        ->and($log->auditable_type)->toBe((new TimeEntry)->getMorphClass())
        ->and($log->auditable_id)->toBe($entry->id);

    // Decoded-array comparison (MySQL reorders JSON keys).
    expect($log->payload['attributes'])->toEqual([
        'id' => $entry->id,
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-02',
        'hours' => 8.0,
        'pay_code' => 'regular',
        'description' => 'Site visit',
        'billable' => false,
        'customer_id' => null,
        'item_id' => null,
        'billable_rate_cents' => null,
        'class_id' => null,
        'location_id' => null,
        'status' => 'approved',
        'pay_run_id' => null,
        'invoice_id' => null,
        'time_off_request_id' => null,
    ]);

    // Staff actions carry no payload actor — the recorder captured the user.
    expect($log->payload)->not->toHaveKey('actor');
});

it('writes an updated audit row with a from/to map of only the dirty fields', function () {
    actingAs($this->user);

    $entry = app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-02',
        'hours' => 8,
        'description' => 'Site visit',
    ]);

    app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-03',
        'hours' => 6.5,
        'description' => 'Site visit',
    ], $entry->fresh());

    $log = ($this->latestLog)(AuditAction::TimeEntryUpdated);

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBe($this->user->id)
        ->and($log->auditable_id)->toBe($entry->id);

    expect($log->payload['changes'])->toEqual([
        'date_worked' => ['from' => '2026-06-02', 'to' => '2026-06-03'],
        'hours' => ['from' => 8.0, 'to' => 6.5],
    ]);
});

it('does not write an updated row when nothing changed', function () {
    actingAs($this->user);

    $entry = app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-02',
        'hours' => 8,
    ]);

    app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-02',
        'hours' => 8,
    ], $entry->fresh());

    expect(AccountingAuditLog::query()->where('action', AuditAction::TimeEntryUpdated->value)->count())->toBe(0);
});

// --- Approve / reject ----------------------------------------------------

it('writes an approved audit row with the status transition', function () {
    actingAs($this->user);

    $entry = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-02', 'hours' => 8, 'status' => 'pending']);

    app(SetTimeEntryStatus::class)->handle([$entry->id], TimeEntryStatus::Approved);

    $log = ($this->latestLog)(AuditAction::TimeEntryApproved);

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBe($this->user->id)
        ->and($log->auditable_id)->toBe($entry->id)
        ->and($log->payload)->toEqual(['from' => 'pending', 'to' => 'approved']);
});

it('writes a rejected audit row with the status transition', function () {
    actingAs($this->user);

    $entry = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-02', 'hours' => 8, 'status' => 'pending']);

    app(SetTimeEntryStatus::class)->handle([$entry->id], TimeEntryStatus::Rejected);

    $log = ($this->latestLog)(AuditAction::TimeEntryRejected);

    expect($log)->not->toBeNull()
        ->and($log->payload)->toEqual(['from' => 'pending', 'to' => 'rejected']);
});

it('audits each entry individually on a bulk status change and skips no-ops', function () {
    actingAs($this->user);

    $a = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-02', 'hours' => 8, 'status' => 'pending']);
    $b = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-03', 'hours' => 4, 'status' => 'pending']);
    $alreadyApproved = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-04', 'hours' => 2, 'status' => 'approved']);

    $count = app(SetTimeEntryStatus::class)->handle([$a->id, $b->id, $alreadyApproved->id], TimeEntryStatus::Approved);

    expect($count)->toBe(2)
        ->and($a->fresh()->status)->toBe(TimeEntryStatus::Approved)
        ->and($b->fresh()->status)->toBe(TimeEntryStatus::Approved);

    $logs = AccountingAuditLog::query()->where('action', AuditAction::TimeEntryApproved->value)->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('auditable_id')->sort()->values()->all())->toEqual(collect([$a->id, $b->id])->sort()->values()->all());
});

// --- Staff page bulk approve ---------------------------------------------

it('bulk-approves the selected pending entries from the staff page, one audit row each', function () {
    actingAs($this->user);

    $a = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-02', 'hours' => 8, 'status' => 'pending']);
    $b = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-03', 'hours' => 4, 'status' => 'pending']);
    $untouched = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-04', 'hours' => 2, 'status' => 'pending']);

    Livewire::test('pages::payroll.time-entries.index', ['company' => $this->company])
        ->set('selected', [(string) $a->id, (string) $b->id])
        ->call('approveSelected')
        ->assertSet('selected', [])
        ->assertSet('selectPage', false);

    expect($a->fresh()->status)->toBe(TimeEntryStatus::Approved)
        ->and($b->fresh()->status)->toBe(TimeEntryStatus::Approved)
        ->and($untouched->fresh()->status)->toBe(TimeEntryStatus::Pending);

    expect(AccountingAuditLog::query()->where('action', AuditAction::TimeEntryApproved->value)->count())->toBe(2);
});

it('selects only pending entries with the select-all checkbox', function () {
    actingAs($this->user);

    $pending = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-02', 'hours' => 8, 'status' => 'pending']);
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-03', 'hours' => 4, 'status' => 'approved']);

    Livewire::test('pages::payroll.time-entries.index', ['company' => $this->company])
        ->set('selectPage', true)
        ->assertSet('selected', [(string) $pending->id]);
});

it('shows the audit history in the staff edit modal', function () {
    actingAs($this->user);

    $entry = app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-02',
        'hours' => 8,
    ]);

    app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-02',
        'hours' => 7,
    ], $entry->fresh());

    Livewire::test('pages::payroll.time-entries.index', ['company' => $this->company])
        ->call('openEdit', $entry->id)
        ->assertSee('History')
        ->assertSee($this->user->name)
        ->assertSee('Updated')
        ->assertSee('hours: 8 → 7');
});

// --- Portal create / edit / delete ---------------------------------------

it('writes a created audit row with a payload actor for a portal self-entry', function () {
    actingAs($this->employee, 'customer');

    $entry = app(SaveOwnTimeEntry::class)->handle($this->employee, [
        'date_worked' => '2026-06-02',
        'hours' => 6,
        'description' => 'Morning shift',
    ]);

    $log = ($this->latestLog)(AuditAction::TimeEntryCreated);

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBeNull()
        ->and($log->auditable_id)->toBe($entry->id)
        ->and($log->payload['actor'])->toEqual([
            'type' => 'employee',
            'contact_id' => $this->employee->id,
            'name' => 'Audit Andy',
        ])
        ->and($log->payload['attributes']['status'])->toBe('pending')
        ->and($log->payload['attributes']['hours'])->toEqual(6.0);
});

it('writes an updated audit row with changes and a payload actor for a portal edit', function () {
    actingAs($this->employee, 'customer');

    $entry = app(SaveOwnTimeEntry::class)->handle($this->employee, [
        'date_worked' => '2026-06-02',
        'hours' => 6,
    ]);

    app(SaveOwnTimeEntry::class)->handle($this->employee, [
        'date_worked' => '2026-06-02',
        'hours' => 7.5,
        'description' => 'Stayed late',
    ], $entry->fresh());

    $log = ($this->latestLog)(AuditAction::TimeEntryUpdated);

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBeNull()
        ->and($log->payload['changes'])->toEqual([
            'hours' => ['from' => 6.0, 'to' => 7.5],
            'description' => ['from' => null, 'to' => 'Stayed late'],
        ])
        ->and($log->payload['actor']['contact_id'])->toBe($this->employee->id);
});

it('writes a deleted audit row with a snapshot before a portal delete', function () {
    actingAs($this->employee, 'customer');

    $entry = app(SaveOwnTimeEntry::class)->handle($this->employee, [
        'date_worked' => '2026-06-02',
        'hours' => 6,
        'description' => 'Oops, wrong day',
    ]);

    app(SaveOwnTimeEntry::class)->delete($this->employee, $entry->fresh());

    expect(TimeEntry::query()->find($entry->id))->toBeNull();

    $log = ($this->latestLog)(AuditAction::TimeEntryDeleted);

    expect($log)->not->toBeNull()
        ->and($log->actor_user_id)->toBeNull()
        ->and($log->auditable_id)->toBe($entry->id)
        ->and($log->payload['attributes'])->toEqual([
            'id' => $entry->id,
            'contact_id' => $this->employee->id,
            'date_worked' => '2026-06-02',
            'hours' => 6.0,
            'pay_code' => 'regular',
            'description' => 'Oops, wrong day',
            'billable' => false,
            'customer_id' => null,
            'item_id' => null,
            'billable_rate_cents' => null,
            'class_id' => null,
            'location_id' => null,
            'status' => 'pending',
            'pay_run_id' => null,
            'invoice_id' => null,
            'time_off_request_id' => null,
        ])
        ->and($log->payload['actor'])->toEqual([
            'type' => 'employee',
            'contact_id' => $this->employee->id,
            'name' => 'Audit Andy',
        ]);
});
