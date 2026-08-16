<?php

use App\Actions\Payroll\PullTimeEntriesIntoPayRun;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\SaveTimeEntry;
use App\Actions\Payroll\SetTimeEntryStatus;
use App\Actions\Portal\SaveOwnTimeEntry;
use App\Actions\Sales\CreateInvoiceFromTimeEntries;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Enums\PayBasis;
use App\Enums\TimeEntryStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\Item;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\PayRunPoster;
use Symfony\Component\HttpKernel\Exception\HttpException;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();

    $this->employee = Contact::create(['display_name' => 'Hourly Hank', 'email' => 'hank@emp.test', 'is_employee' => true, 'is_active' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Hourly->value,
        'hourly_rate_cents' => 3000, // $30/h
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'is_active' => true,
    ]);

    $this->customer = Contact::create(['display_name' => 'Bill Me Co', 'email' => 'bill@cust.test', 'is_customer' => true, 'is_active' => true]);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->item = Item::factory()->create(['income_account_id' => $income->id, 'default_price_cents' => 12000, 'is_active' => true]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** Build a Draft pay run with the hourly employee as a line. */
function draftRunForEmployee(): PayRun
{
    $bank = Account::query()->where('code', '1000')->firstOrFail();

    return app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => test()->employee->id]],
    ]);
}

/** Five 10-hour days in one ISO week (2025-06-02..06) = 50h. */
function logFiftyHourWeek(string $status = 'approved'): void
{
    foreach (['2025-06-02', '2025-06-03', '2025-06-04', '2025-06-05', '2025-06-06'] as $date) {
        TimeEntry::create([
            'contact_id' => test()->employee->id,
            'date_worked' => $date,
            'hours' => 10,
            'status' => $status,
        ]);
    }
}

// --- CRUD + approval -----------------------------------------------------

it('creates a staff time entry defaulting to approved', function () {
    $entry = app(SaveTimeEntry::class)->handle([
        'contact_id' => $this->employee->id,
        'date_worked' => '2025-06-02',
        'hours' => 8,
    ]);

    expect($entry->status)->toBe(TimeEntryStatus::Approved)
        ->and((float) $entry->hours)->toBe(8.0)
        ->and($entry->company_id)->toBe($this->company->id);
});

it('approves and rejects pending entries but locks consumed ones', function () {
    $pending = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-02', 'hours' => 8, 'status' => 'pending']);
    $consumed = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-03', 'hours' => 8, 'status' => 'pending', 'pay_run_id' => draftRunForEmployee()->id]);

    app(SetTimeEntryStatus::class)->handle([$pending->id, $consumed->id], TimeEntryStatus::Approved);

    expect($pending->fresh()->status)->toBe(TimeEntryStatus::Approved)
        ->and($consumed->fresh()->status)->toBe(TimeEntryStatus::Pending); // locked — already on a run
});

// --- Payroll pull + overtime split --------------------------------------

it('pulls regular hours and splits overtime past the weekly threshold', function () {
    $this->company->update(['payroll_overtime_weekly_threshold_hours' => 44]);
    logFiftyHourWeek();
    $run = draftRunForEmployee();

    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    $line = $run->lines()->firstOrFail();
    expect($summary['employees'])->toBe(1)
        ->and((float) $line->hours_worked)->toBe(44.0);

    $ot = $line->manualEarnings()->where('code', 'overtime')->firstOrFail();
    expect((float) $ot->hours)->toBe(6.0)
        ->and($ot->multiplier_bp)->toBe(15000);

    // All five entries are now stamped to the run.
    expect(TimeEntry::where('pay_run_id', $run->id)->count())->toBe(5);
});

it('prices the pulled regular + overtime hours when calculated', function () {
    $this->company->update(['payroll_overtime_weekly_threshold_hours' => 44]);
    logFiftyHourWeek();
    $run = draftRunForEmployee();
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    app(CalculatePayRun::class)->calculate($run->fresh());

    // 44h × $30 + 6h × $30 × 1.5 = 132000 + 27000 = 159000 cents.
    expect((int) $run->lines()->firstOrFail()->gross_cents)->toBe(159000);
});

it('pulls all hours as regular when no threshold is set', function () {
    logFiftyHourWeek();
    $run = draftRunForEmployee();

    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    $line = $run->lines()->firstOrFail();
    expect((float) $line->hours_worked)->toBe(50.0)
        ->and($line->manualEarnings()->where('code', 'overtime')->count())->toBe(0);
});

it('is idempotent on re-pull (no duplicate overtime, single stamp)', function () {
    $this->company->update(['payroll_overtime_weekly_threshold_hours' => 44]);
    logFiftyHourWeek();
    $run = draftRunForEmployee();

    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    $line = $run->lines()->firstOrFail();
    expect((float) $line->hours_worked)->toBe(44.0)
        ->and($line->manualEarnings()->where('code', 'overtime')->count())->toBe(1)
        ->and(TimeEntry::where('pay_run_id', $run->id)->count())->toBe(5);
});

it('does not pull time for salaried employees', function () {
    $salaried = Contact::create(['display_name' => 'Salary Sam', 'is_employee' => true, 'is_active' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $salaried->id, 'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value, 'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id, 'is_active' => true,
    ]);
    $entry = TimeEntry::create(['contact_id' => $salaried->id, 'date_worked' => '2025-06-03', 'hours' => 10, 'status' => 'approved']);

    $bank = Account::query()->where('code', '1000')->firstOrFail();
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01', 'period_end_date' => '2025-06-14', 'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $salaried->id]],
    ]);

    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($entry->fresh()->pay_run_id)->toBeNull();
});

it('releases consumed time entries when the run is voided', function () {
    logFiftyHourWeek();
    $run = draftRunForEmployee();
    app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());
    expect(TimeEntry::whereNotNull('pay_run_id')->count())->toBe(5);

    app(CalculatePayRun::class)->calculate($run->fresh());
    app(PayRunPoster::class)->post($run->fresh());
    app(PayRunPoster::class)->void($run->fresh());

    expect(TimeEntry::whereNotNull('pay_run_id')->count())->toBe(0);
});

it('only pulls approved time (pending hours are ignored)', function () {
    logFiftyHourWeek('pending');
    $run = draftRunForEmployee();

    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($summary['entries'])->toBe(0)
        ->and((float) $run->lines()->firstOrFail()->hours_worked)->toBe(0.0);
});

it('reports a salaried-only run as having no hourly employees', function () {
    $salaried = Contact::create(['display_name' => 'Salary Sue', 'is_employee' => true, 'is_active' => true]);
    EmployeePayrollProfile::factory()->create([
        'contact_id' => $salaried->id, 'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value, 'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id, 'is_active' => true,
    ]);
    TimeEntry::create(['contact_id' => $salaried->id, 'date_worked' => '2025-06-03', 'hours' => 10, 'status' => 'approved']);

    $bank = Account::query()->where('code', '1000')->firstOrFail();
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01', 'period_end_date' => '2025-06-14', 'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $salaried->id]],
    ]);

    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($summary['hourly_employees'])->toBe(0)
        ->and($summary['entries'])->toBe(0)
        ->and($summary['outside_period'])->toBe(0);
});

it('counts approved hourly entries dated outside the period without consuming them', function () {
    // One entry before the period, one after, one inside.
    $before = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-05-20', 'hours' => 8, 'status' => 'approved']);
    $after = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-20', 'hours' => 8, 'status' => 'approved']);
    $inside = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-03', 'hours' => 7.5, 'status' => 'approved']);
    $run = draftRunForEmployee();

    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($summary['employees'])->toBe(1)
        ->and($summary['entries'])->toBe(1)
        ->and($summary['hours'])->toBe(7.5)
        ->and($summary['hourly_employees'])->toBe(1)
        ->and($summary['outside_period'])->toBe(2)
        ->and($inside->fresh()->pay_run_id)->toBe($run->id)
        ->and($before->fresh()->pay_run_id)->toBeNull()
        ->and($after->fresh()->pay_run_id)->toBeNull();
});

it('reports total pulled hours including the overtime split', function () {
    $this->company->update(['payroll_overtime_weekly_threshold_hours' => 44]);
    logFiftyHourWeek();
    $run = draftRunForEmployee();

    $summary = app(PullTimeEntriesIntoPayRun::class)->handle($run->fresh());

    expect($summary['hours'])->toBe(50.0)
        ->and($summary['entries'])->toBe(5);
});

// --- Invoicing feed ------------------------------------------------------

it('creates a draft invoice from billable time, one line per entry', function () {
    $a = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-02', 'hours' => 3, 'billable' => true, 'customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'status' => 'approved']);
    $b = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-03', 'hours' => 2, 'billable' => true, 'customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'status' => 'approved']);

    $invoice = app(CreateInvoiceFromTimeEntries::class)->handle($this->customer, collect([$a, $b]));

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->lines()->count())->toBe(2)
        ->and($invoice->contact_id)->toBe($this->customer->id);

    // 3h + 2h at the item's $120 rate = $600.
    expect((int) $invoice->subtotal_cents)->toBe(60000);

    // Both entries are stamped billed; a re-gather finds nothing eligible.
    expect($a->fresh()->invoice_id)->toBe($invoice->id)
        ->and($b->fresh()->invoice_id)->toBe($invoice->id);
});

it('skips already-billed and non-billable entries', function () {
    // Bill one entry for real, so it carries a valid invoice_id.
    $billed = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-02', 'hours' => 3, 'billable' => true, 'customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'status' => 'approved']);
    app(CreateInvoiceFromTimeEntries::class)->handle($this->customer, collect([$billed]));
    expect($billed->fresh()->invoice_id)->not->toBeNull();

    $nonBillable = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-03', 'hours' => 2, 'billable' => false, 'customer_id' => $this->customer->id, 'status' => 'approved']);

    // Only an already-billed + a non-billable entry remain → nothing eligible.
    expect(fn () => app(CreateInvoiceFromTimeEntries::class)->handle($this->customer, collect([$billed->fresh(), $nonBillable])))
        ->toThrow(RuntimeException::class);
});

it('posts a time-based invoice to a balanced journal entry', function () {
    $entry = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-02', 'hours' => 4, 'billable' => true, 'customer_id' => $this->customer->id, 'item_id' => $this->item->id, 'status' => 'approved']);
    $invoice = app(CreateInvoiceFromTimeEntries::class)->handle($this->customer, collect([$entry]));

    app(InvoicePoster::class)->post($invoice->fresh());
    $je = $invoice->fresh()->journalEntry;

    expect($je)->not->toBeNull()
        ->and($je->lines()->sum('debit_cents'))->toBe($je->lines()->sum('credit_cents'));
});

// --- Portal self-entry (whitelist) --------------------------------------

it('logs portal self-entry as pending and never sets a billable rate', function () {
    $entry = app(SaveOwnTimeEntry::class)->handle($this->employee, [
        'date_worked' => '2025-06-02',
        'hours' => 6,
        'billable' => true,
        'customer_id' => $this->customer->id,
        'item_id' => $this->item->id,
        // A crafted rate must be ignored.
        'billable_rate_cents' => 99999,
        // A crafted contact_id must be ignored (forced to the auth employee).
        'contact_id' => 999999,
        'status' => 'approved',
    ]);

    expect($entry->status)->toBe(TimeEntryStatus::Pending)
        ->and($entry->contact_id)->toBe($this->employee->id)
        ->and($entry->billable_rate_cents)->toBeNull();
});

it('forbids editing another employee’s entry or an approved one via the portal', function () {
    $other = Contact::create(['display_name' => 'Other', 'is_employee' => true, 'is_active' => true]);
    $othersEntry = TimeEntry::create(['contact_id' => $other->id, 'date_worked' => '2025-06-02', 'hours' => 5, 'status' => 'pending']);
    $approved = TimeEntry::create(['contact_id' => $this->employee->id, 'date_worked' => '2025-06-03', 'hours' => 5, 'status' => 'approved']);

    expect(fn () => app(SaveOwnTimeEntry::class)->handle($this->employee, ['date_worked' => '2025-06-02', 'hours' => 1], $othersEntry))
        ->toThrow(HttpException::class);

    expect(fn () => app(SaveOwnTimeEntry::class)->handle($this->employee, ['date_worked' => '2025-06-03', 'hours' => 1], $approved))
        ->toThrow(HttpException::class);
});

it('gates the my-pay time route to the employee audience', function () {
    $customerOnly = Contact::create(['display_name' => 'Cust Only', 'email' => 'co@x.test', 'is_customer' => true, 'is_employee' => false, 'is_active' => true]);

    actingAs($customerOnly, 'customer');

    $this->get(route('employee-portal.time', ['company' => $this->company->slug]))
        ->assertForbidden();
});
