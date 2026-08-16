<?php

use App\Actions\Portal\SetOwnPortalPassword;
use App\Enums\PayBasis;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\SecurityLog;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Employee self-service portal — optional password sign-in
|--------------------------------------------------------------------------
| Helper names are prefixed (passwordPortalEmployee) to avoid colliding with
| top-level helpers in other Pest files (makeEmployee, postRunFor).
*/

function passwordPortalEmployee(string $name, string $email, int $scheduleId): Contact
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
        'is_active' => true,
    ]);

    return $contact->refresh();
}

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->alice = passwordPortalEmployee('Alice Employee', 'alice@emp.test', $this->schedule->id);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('lets an employee set a first password (no current password) and logs a security event', function () {
    expect($this->alice->portal_password)->toBeNull();

    app(SetOwnPortalPassword::class)->handle($this->alice, [
        'password' => 'first-password-123',
        'password_confirmation' => 'first-password-123',
    ]);

    $this->alice->refresh();
    expect($this->alice->portal_password)->not->toBeNull()
        ->and(Hash::check('first-password-123', $this->alice->portal_password))->toBeTrue();

    $log = SecurityLog::query()->where('event', SecurityEvent::EmployeePortalPasswordChanged->value)->first();
    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBeNull()
        ->and($log->company_id)->toBe($this->company->id)
        ->and($log->metadata['contact_id'])->toBe($this->alice->id)
        ->and($log->metadata['first_time_setup'])->toBeTrue();
});

it('signs in with email and password and lands on the dashboard on the customer guard', function () {
    app(SetOwnPortalPassword::class)->handle($this->alice, [
        'password' => 'first-password-123',
        'password_confirmation' => 'first-password-123',
    ]);

    Livewire::test('pages::employee-portal.login', ['company' => $this->company])
        ->set('email', 'ALICE@emp.test') // case-insensitive
        ->set('password', 'first-password-123')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect(route('employee-portal.dashboard', ['company' => $this->company->slug]));

    $this->assertAuthenticatedAs($this->alice, 'customer');
});

it('requires the correct current password to change an existing password', function () {
    app(SetOwnPortalPassword::class)->handle($this->alice, [
        'password' => 'first-password-123',
        'password_confirmation' => 'first-password-123',
    ]);

    // Wrong current password is rejected.
    try {
        app(SetOwnPortalPassword::class)->handle($this->alice->refresh(), [
            'current_password' => 'not-the-password',
            'password' => 'second-password-456',
            'password_confirmation' => 'second-password-456',
        ]);
        $this->fail('Expected a ValidationException for the wrong current password.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('current_password');
    }

    expect(Hash::check('first-password-123', $this->alice->refresh()->portal_password))->toBeTrue();

    // Missing current password is rejected too.
    try {
        app(SetOwnPortalPassword::class)->handle($this->alice->refresh(), [
            'password' => 'second-password-456',
            'password_confirmation' => 'second-password-456',
        ]);
        $this->fail('Expected a ValidationException for the missing current password.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('current_password');
    }

    // Correct current password succeeds and logs a non-first-time event.
    app(SetOwnPortalPassword::class)->handle($this->alice->refresh(), [
        'current_password' => 'first-password-123',
        'password' => 'second-password-456',
        'password_confirmation' => 'second-password-456',
    ]);

    expect(Hash::check('second-password-456', $this->alice->refresh()->portal_password))->toBeTrue();

    $events = SecurityLog::query()
        ->where('event', SecurityEvent::EmployeePortalPasswordChanged->value)
        ->orderBy('id')
        ->get();
    expect($events)->toHaveCount(2)
        ->and($events->last()->metadata['first_time_setup'])->toBeFalse();
});

it('shows the same generic error for every failed sign-in and does not authenticate', function () {
    app(SetOwnPortalPassword::class)->handle($this->alice, [
        'password' => 'first-password-123',
        'password_confirmation' => 'first-password-123',
    ]);

    // A customer-only contact (not portal-eligible as an employee).
    Contact::create([
        'display_name' => 'Customer Only',
        'email' => 'customer@only.test',
        'is_customer' => true,
        'is_active' => true,
    ]);

    // An eligible employee who never set a password.
    passwordPortalEmployee('No Password', 'nopass@emp.test', $this->schedule->id);

    $generic = __('These credentials do not match our records.');

    $attempts = [
        ['alice@emp.test', 'wrong-password'],          // wrong password
        ['stranger@nowhere.test', 'whatever-123'],     // unknown email
        ['customer@only.test', 'whatever-123'],        // customer-only contact
        ['nopass@emp.test', 'whatever-123'],           // employee without a password
    ];

    foreach ($attempts as [$email, $password]) {
        $component = Livewire::test('pages::employee-portal.login', ['company' => $this->company])
            ->set('email', $email)
            ->set('password', $password)
            ->call('login')
            ->assertHasErrors('email');

        expect($component->errors()->first('email'))->toBe($generic);
        $this->assertGuest('customer');
    }
});

it('rate limits password sign-in after 5 failed attempts', function () {
    app(SetOwnPortalPassword::class)->handle($this->alice, [
        'password' => 'first-password-123',
        'password_confirmation' => 'first-password-123',
    ]);

    $component = Livewire::test('pages::employee-portal.login', ['company' => $this->company])
        ->set('email', 'alice@emp.test');

    foreach (range(1, 5) as $i) {
        $component->set('password', 'wrong-password-'.$i)->call('login')->assertHasErrors('email');
        expect($component->errors()->first('email'))->toBe(__('These credentials do not match our records.'));
    }

    // Sixth attempt is throttled — even with the CORRECT password.
    $component->set('password', 'first-password-123')->call('login')->assertHasErrors('email');
    expect($component->errors()->first('email'))->toContain('Too many attempts');
    $this->assertGuest('customer');
});

it('rejects a password sign-in against another company portal', function () {
    app(SetOwnPortalPassword::class)->handle($this->alice, [
        'password' => 'first-password-123',
        'password_confirmation' => 'first-password-123',
    ]);

    $other = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->forgetInstance('current_company');
    app()->instance('current_company', $other);

    $component = Livewire::test('pages::employee-portal.login', ['company' => $other])
        ->set('email', 'alice@emp.test')
        ->set('password', 'first-password-123')
        ->call('login')
        ->assertHasErrors('email');

    expect($component->errors()->first('email'))->toBe(__('These credentials do not match our records.'));
    $this->assertGuest('customer');
});
