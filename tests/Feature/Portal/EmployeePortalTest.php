<?php

use App\Actions\Payroll\FinalizeSlipFiling;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Portal\RequestEmployeePortalLoginLink;
use App\Actions\Portal\UpdateOwnEmployeeInfo;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Enums\SecurityEvent;
use App\Enums\SlipType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\PortalLoginLink;
use App\Models\SecurityLog;
use App\Notifications\Portal\EmployeePortalLoginLinkNotification;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Employee self-service portal ("my-pay")
|--------------------------------------------------------------------------
*/

function makeEmployee(string $name, string $email, int $scheduleId): Contact
{
    $contact = Contact::create([
        'display_name' => $name,
        'email' => $email,
        'is_employee' => true,
        'is_active' => true,
    ]);

    EmployeePayrollProfile::factory()->create([
        'contact_id' => $contact->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $scheduleId,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'is_active' => true,
    ]);

    return $contact->refresh();
}

function postRunFor(Contact $employee, int $scheduleId, string $start, string $end, string $pay): PayRun
{
    $bank = Account::query()->where('code', '1000')->firstOrFail();

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $scheduleId,
        'period_start_date' => $start,
        'period_end_date' => $end,
        'pay_date' => $pay,
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $employee->id]],
    ]);

    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    return $run->fresh();
}

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();

    $this->alice = makeEmployee('Alice Employee', 'alice@emp.test', $this->schedule->id);
    $this->bob = makeEmployee('Bob Employee', 'bob@emp.test', $this->schedule->id);

    $this->aliceRun = postRunFor($this->alice, $this->schedule->id, '2025-06-01', '2025-06-14', '2025-06-20');
    $this->bobRun = postRunFor($this->bob, $this->schedule->id, '2025-06-15', '2025-06-28', '2025-07-04');

    $this->aliceLine = $this->aliceRun->lines()->firstOrFail();
    $this->bobLine = $this->bobRun->lines()->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

// --- Eligibility scope ---------------------------------------------------

it('scopes eligibility to active employees with an email and an active profile', function () {
    // A customer (not an employee).
    Contact::create(['display_name' => 'Cust', 'email' => 'cust@x.test', 'is_customer' => true, 'is_employee' => false]);
    // An employee with no email.
    $noEmail = makeEmployee('No Email', 'placeholder@x.test', $this->schedule->id);
    $noEmail->forceFill(['email' => null])->save();
    // An employee whose profile is inactive.
    $inactiveProfile = makeEmployee('Inactive Profile', 'inact@emp.test', $this->schedule->id);
    $inactiveProfile->payrollProfile->forceFill(['is_active' => false])->save();
    // An employee with no profile at all.
    $noProfile = Contact::create(['display_name' => 'No Profile', 'email' => 'np@emp.test', 'is_employee' => true, 'is_active' => true]);

    $eligible = Contact::query()->employeePortalEligible()->pluck('id');

    expect($eligible)->toContain($this->alice->id)
        ->and($eligible)->toContain($this->bob->id)
        ->and($eligible)->not->toContain($noEmail->id)
        ->and($eligible)->not->toContain($inactiveProfile->id)
        ->and($eligible)->not->toContain($noProfile->id);
});

// --- Magic-link issuance -------------------------------------------------

it('emails a magic link to an eligible employee', function () {
    Notification::fake();

    app(RequestEmployeePortalLoginLink::class)->handle($this->company, 'alice@emp.test');

    expect(PortalLoginLink::where('contact_id', $this->alice->id)->count())->toBe(1);
    Notification::assertSentTo($this->alice, EmployeePortalLoginLinkNotification::class);
});

it('does not issue an employee link to a customer-only contact (enumeration-safe)', function () {
    Notification::fake();

    $cust = Contact::create(['display_name' => 'Cust', 'email' => 'cust@x.test', 'is_customer' => true, 'is_employee' => false]);

    app(RequestEmployeePortalLoginLink::class)->handle($this->company, 'cust@x.test');
    app(RequestEmployeePortalLoginLink::class)->handle($this->company, 'nobody@nowhere.test');

    expect(PortalLoginLink::where('contact_id', $cust->id)->count())->toBe(0);
    Notification::assertNothingSent();
});

// --- Consume audience re-assertion --------------------------------------

it('consumes a valid employee link and signs the employee in', function () {
    $token = 'employeetoken123';
    PortalLoginLink::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->alice->id,
        'token_hash' => PortalLoginLink::hashToken($token),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
    ]);

    $this->get(route('employee-portal.login.consume', ['company' => $this->company->slug, 'token' => $token]))
        ->assertRedirect(route('employee-portal.dashboard', ['company' => $this->company->slug]));

    $this->assertAuthenticatedAs($this->alice, 'customer');
    expect(PortalLoginLink::first()->used_at)->not->toBeNull();
});

it('rejects an employee consume whose contact is not an employee', function () {
    $cust = Contact::create(['display_name' => 'Cust', 'email' => 'cust@x.test', 'is_customer' => true, 'is_employee' => false]);
    $token = 'customertoken';
    PortalLoginLink::create([
        'company_id' => $this->company->id,
        'contact_id' => $cust->id,
        'token_hash' => PortalLoginLink::hashToken($token),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
    ]);

    $this->get(route('employee-portal.login.consume', ['company' => $this->company->slug, 'token' => $token]))
        ->assertRedirect(route('employee-portal.login', ['company' => $this->company->slug]));

    $this->assertGuest('customer');
});

// --- Pay-statement ownership --------------------------------------------

it('lets an employee download their own pay statement', function () {
    actingAs($this->alice, 'customer');

    $this->get(route('employee-portal.pay-stub.pdf', ['company' => $this->company->slug, 'payRunLine' => $this->aliceLine->id]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('blocks an employee from downloading another employee’s pay statement', function () {
    actingAs($this->alice, 'customer');

    $this->get(route('employee-portal.pay-stub.pdf', ['company' => $this->company->slug, 'payRunLine' => $this->bobLine->id]))
        ->assertNotFound();
});

it('404s a pay statement for a draft run', function () {
    $draft = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-08-01',
        'period_end_date' => '2025-08-14',
        'pay_date' => '2025-08-20',
        'bank_account_id' => Account::query()->where('code', '1000')->value('id'),
        'lines' => [['contact_id' => $this->alice->id]],
    ]);
    $draftLine = $draft->lines()->firstOrFail();

    expect($draft->status)->toBe(PayRunStatus::Draft);

    actingAs($this->alice, 'customer');

    $this->get(route('employee-portal.pay-stub.pdf', ['company' => $this->company->slug, 'payRunLine' => $draftLine->id]))
        ->assertNotFound();
});

// --- T4 ownership --------------------------------------------------------

it('lets an employee download their own T4 once the year is finalized', function () {
    app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);

    actingAs($this->alice, 'customer');

    $this->get(route('employee-portal.t4.pdf', ['company' => $this->company->slug, 'year' => 2025]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

it('404s a T4 for a year the employer has not finalized, even with posted pay', function () {
    actingAs($this->alice, 'customer');

    $this->get(route('employee-portal.t4.pdf', ['company' => $this->company->slug, 'year' => 2025]))
        ->assertNotFound();
});

it('404s a T4 for a year the employee has no slip', function () {
    app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);

    actingAs($this->alice, 'customer');

    $this->get(route('employee-portal.t4.pdf', ['company' => $this->company->slug, 'year' => 2099]))
        ->assertNotFound();
});

// --- Audience middleware -------------------------------------------------

it('blocks a customer-only contact from the employee portal', function () {
    $cust = Contact::create(['display_name' => 'Cust', 'email' => 'c@x.test', 'is_customer' => true, 'is_employee' => false, 'is_active' => true]);

    actingAs($cust, 'customer');

    $this->get(route('employee-portal.dashboard', ['company' => $this->company->slug]))
        ->assertForbidden();
});

it('404s the employee portal for a US company even with payroll enabled', function () {
    $us = Company::factory()->create(['address_country' => 'US', 'features_payroll' => true]);
    app()->instance('current_company', $us);

    $emp = Contact::create(['display_name' => 'US Emp', 'email' => 'us@emp.test', 'is_employee' => true, 'is_active' => true]);

    actingAs($emp, 'customer');

    $this->get(route('employee-portal.dashboard', ['company' => $us->slug]))
        ->assertNotFound();
});

// --- Dashboard own-data only --------------------------------------------

it('shows only the signed-in employee’s own pay statements', function () {
    actingAs($this->alice, 'customer');

    // Alice's own pay date is shown; Bob's (another employee's) is never rendered.
    Livewire::test('pages::employee-portal.dashboard', ['company' => $this->company])
        ->assertSee('2025-06-20')
        ->assertDontSee('2025-07-04');
});

// --- Self-service edit (whitelist + audit) ------------------------------

it('lets an employee update only their address and TD1, writing an audit row', function () {
    actingAs($this->alice, 'customer');

    $originalSalary = $this->alice->payrollProfile->annual_salary_cents;

    Livewire::test('pages::employee-portal.edit-info', ['company' => $this->company])
        ->set('billing_line1', '123 New Street')
        ->set('billing_city', 'Calgary')
        ->set('td1_federal_claim', '15705.00')
        ->call('save')
        ->assertHasNoErrors();

    $this->alice->refresh();

    expect($this->alice->billing_line1)->toBe('123 New Street')
        ->and($this->alice->billing_city)->toBe('Calgary')
        ->and($this->alice->payrollProfile->td1_federal_claim_cents)->toBe(1570500)
        ->and($this->alice->payrollProfile->annual_salary_cents)->toBe($originalSalary);

    expect(SecurityLog::where('event', SecurityEvent::EmployeePortalInfoUpdated->value)->count())->toBe(1);
});

it('ignores non-whitelisted fields in the self-edit action', function () {
    $profile = $this->alice->payrollProfile;
    $originalSalary = $profile->annual_salary_cents;
    $originalSinLast4 = $profile->sin_last4;

    app(UpdateOwnEmployeeInfo::class)->handle($this->alice, [
        'billing_line1' => 'Whitelisted St',
        'td1_federal_claim_cents' => 1570500,
        // None of these are whitelisted and must be ignored.
        'annual_salary_cents' => 1,
        'sin' => '999999999',
        'cpp_exempt' => true,
        'contact_id' => 999999,
    ]);

    $this->alice->refresh();

    expect($this->alice->billing_line1)->toBe('Whitelisted St')
        ->and($this->alice->payrollProfile->td1_federal_claim_cents)->toBe(1570500)
        ->and($this->alice->payrollProfile->annual_salary_cents)->toBe($originalSalary)
        ->and($this->alice->payrollProfile->cpp_exempt)->toBeFalse()
        ->and($this->alice->payrollProfile->sin_last4)->toBe($originalSinLast4);
});

// --- Cross-tenant document isolation ------------------------------------

it('blocks an employee from another company’s pay statement via the ownership guard', function () {
    // A second company with its own employee + posted line.
    $other = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $other);
    $otherSchedule = PayrollSchedule::factory()->create();
    $carol = makeEmployee('Carol', 'carol@other.test', $otherSchedule->id);
    $otherRun = postRunFor($carol, $otherSchedule->id, '2025-06-01', '2025-06-14', '2025-06-20');
    $otherLine = $otherRun->lines()->firstOrFail();

    // Alice (company A) authenticates, then requests company B's pay stub.
    actingAs($this->alice, 'customer');

    $this->get(route('employee-portal.pay-stub.pdf', ['company' => $other->slug, 'payRunLine' => $otherLine->id]))
        ->assertNotFound();

    app()->instance('current_company', $this->company);
});
