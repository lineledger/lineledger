<?php

use App\Actions\Payroll\SaveTimeEntry;
use App\Actions\Portal\SaveOwnTimeEntry;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Membership;
use App\Models\TimeEntry;
use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Employee portal "My time" calendar
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);

    $this->employee = Contact::create([
        'display_name' => 'Cal Worker',
        'email' => 'cal@emp.test',
        'is_employee' => true,
        'is_active' => true,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('defaults to the calendar view on the current company month', function () {
    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->assertSet('view', 'calendar')
        ->assertSet('month', $this->company->currentDateTime()->format('Y-m'))
        ->assertSee('data-test="my-time-calendar"', false);
});

it('shows correct daily totals and the month total for the visible month', function () {
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-09', 'hours' => 3, 'status' => 'pending']);
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-09', 'hours' => 5, 'status' => 'approved']);
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-15', 'hours' => 2, 'status' => 'pending']);

    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->set('month', '2026-06')
        ->assertSee('June 2026')
        ->assertSee('8.00h')
        ->assertSee('2.00h')
        ->assertSee('10.00 h this month');
});

it('includes leading days of the adjacent month in the grid but not in the month total', function () {
    // June 2026 starts on a Monday, so the Sun→Sat grid leads with 2026-05-31.
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-05-31', 'hours' => 4, 'status' => 'pending']);

    actingAs($this->employee, 'customer');

    $page = Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->set('month', '2026-06')
        ->assertSee('4.00h') // visible in the leading cell of the June grid
        ->assertSee('0.00 h this month'); // but not counted for June

    $days = $page->instance()->days;

    expect($days[0]['date'])->toBe('2026-05-31')
        ->and($days[0]['inMonth'])->toBeFalse()
        ->and(end($days)['date'])->toBe('2026-07-04');
});

it('prefills the log-time form with the clicked day', function () {
    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->set('month', '2026-06')
        ->call('openCreateFor', '2026-06-15')
        ->assertSet('f_date_worked', '2026-06-15');
});

it('opens the day panel for a day with entries', function () {
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-09', 'hours' => 3, 'status' => 'pending']);

    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->set('month', '2026-06')
        ->call('openDay', '2026-06-09')
        ->assertSet('dayDate', '2026-06-09')
        ->assertSee('Tuesday, June 9, 2026');
});

it('navigates between months, changing the visible range', function () {
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-05-15', 'hours' => 4, 'status' => 'approved']);

    actingAs($this->employee, 'customer');

    $page = Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->set('month', '2026-06')
        ->assertSee('0.00 h this month');

    $page->call('previousMonth')
        ->assertSet('month', '2026-05')
        ->assertSee('May 2026')
        ->assertSee('4.00 h this month');

    $page->call('nextMonth')
        ->call('nextMonth')
        ->assertSet('month', '2026-07')
        ->assertSee('July 2026')
        ->assertSee('0.00 h this month');
});

it('can switch to the list view and back', function () {
    TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2026-06-09', 'hours' => 3, 'status' => 'pending']);

    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->call('setView', 'list')
        ->assertSet('view', 'list')
        ->assertSee('data-test="my-time-row"', false)
        ->call('setView', 'calendar')
        ->assertSet('view', 'calendar')
        ->assertSee('data-test="my-time-calendar"', false);
});

it('shows the entry’s edit history to the employee, including a staff edit', function () {
    $staff = User::factory()->create(['name' => 'Manager Mel']);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $staff->id, 'role' => CompanyRole::Owner]);

    // The employee logs the entry through the portal...
    actingAs($this->employee, 'customer');
    $entry = app(SaveOwnTimeEntry::class)->handle($this->employee, [
        'date_worked' => '2026-06-09',
        'hours' => 5,
    ]);

    // ...then staff trims the hours.
    actingAs($staff);
    app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2026-06-09',
        'hours' => 4,
    ], $entry->fresh());

    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.time', ['company' => $this->company])
        ->set('month', '2026-06')
        ->call('openEdit', $entry->id)
        ->assertSee('Edit history')
        ->assertSee('Manager Mel')   // staff actor rendered by user name
        ->assertSee('You')           // the employee’s own creation
        ->assertSee('hours: 5 → 4');
});
