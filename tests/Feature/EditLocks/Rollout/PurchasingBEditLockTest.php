<?php

use App\Actions\Payroll\SavePayRun;
use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveVendorCredit;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\PayRunStatus;
use App\Enums\VendorCreditStatus;
use App\Livewire\Attributes\GuardsEditLock;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EditLock;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\VendorCredit;
use App\Services\EditLocks\EditLockManager;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    app()->instance('current_company', $this->company);

    $this->jane = editLockMember($this->company, CompanyRole::Accountant);
    $this->bob = editLockMember($this->company, CompanyRole::Accountant);
    $this->locks = app(EditLockManager::class);

    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    // Records are built on demand, so each test only makes what it opens.
    $this->makeVendorCredit = fn (): VendorCredit => app(SaveVendorCredit::class)->handle([
        'contact_id' => Contact::query()->create(['display_name' => 'Edit Lock Vendor', 'is_vendor' => true])->id,
        'vendor_credit_no' => 'VC-LOCK-1',
        'vendor_credit_date' => '2026-06-01',
        'memo' => 'Original',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ]);

    $this->makeReimbursement = fn (): Bill => app(SaveBill::class)->handle([
        'contact_id' => Contact::query()->create(['display_name' => 'Edit Lock Employee', 'is_employee' => true])->id,
        'bill_type' => BillType::Reimbursement->value,
        'bill_no' => 'REIM-LOCK-1',
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-01',
        'memo' => 'Original',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 5000]],
    ]);

    $this->makeEnrolledEmployee = function (?PayrollSchedule $schedule = null): Contact {
        $employee = Contact::query()->create(['display_name' => 'Riley Runner', 'is_employee' => true]);

        EmployeePayrollProfile::factory()->create([
            'company_id' => $this->company->id,
            'contact_id' => $employee->id,
            'province_of_employment' => 'AB',
            'pay_basis' => PayBasis::Salary,
            'annual_salary_cents' => 6000000,
            'payroll_schedule_id' => $schedule?->id,
        ]);

        return $employee;
    };

    $this->makePayRun = function (): PayRun {
        $schedule = PayrollSchedule::factory()->create(['company_id' => $this->company->id]);
        $employee = ($this->makeEnrolledEmployee)($schedule);

        return app(SavePayRun::class)->handle([
            'payroll_schedule_id' => $schedule->id,
            'period_start_date' => '2025-06-01',
            'period_end_date' => '2025-06-14',
            'pay_date' => '2025-06-20',
            'bank_account_id' => null,
            'lines' => [['contact_id' => $employee->id, 'hours_worked' => null, 'manual_earnings' => []]],
        ]);
    };
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/*
 * Each full-page form: the component, the record it edits, and a real save
 * of one field — `changed` reports whether that save reached the database.
 */
$forms = [
    'vendor credit' => function () {
        $record = ($this->makeVendorCredit)();

        return [
            'component' => 'pages::vendor-credits.form',
            'params' => ['company' => $this->company, 'vendor_credit' => $record],
            'record' => $record,
            'noun' => 'vendor credit',
            'save' => fn (Testable $form) => $form->set('memo', 'Changed here')->call('saveDraft'),
            'changed' => fn (): bool => $record->fresh()->memo === 'Changed here',
        ];
    },
    'reimbursement' => function () {
        $record = ($this->makeReimbursement)();

        return [
            'component' => 'pages::reimbursements.form',
            'params' => ['company' => $this->company, 'bill' => $record],
            'record' => $record,
            'noun' => 'reimbursement',
            'save' => fn (Testable $form) => $form->set('memo', 'Changed here')->call('saveDraft'),
            'changed' => fn (): bool => $record->fresh()->memo === 'Changed here',
        ];
    },
    'employee payroll setup' => function () {
        $record = ($this->makeEnrolledEmployee)();

        return [
            'component' => 'pages::payroll.employees.form',
            'params' => ['company' => $this->company, 'contact' => $record],
            'record' => $record,
            'noun' => 'employee',
            'save' => fn (Testable $form) => $form->set('annual_salary', '99000.00')->call('save'),
            'changed' => fn (): bool => $record->fresh()->payrollProfile->annual_salary_cents === 9900000,
        ];
    },
    'pay run' => function () {
        $record = ($this->makePayRun)();

        return [
            'component' => 'pages::payroll.pay-runs.form',
            'params' => ['company' => $this->company, 'payRun' => $record],
            'record' => $record,
            'noun' => 'pay run',
            'save' => fn (Testable $form) => $form->set('pay_date', '2025-06-27')->call('saveDraft'),
            'changed' => fn (): bool => $record->fresh()->pay_date->toDateString() === '2025-06-27',
        ];
    },
];

it('takes the lock when a member opens the record for editing', function (array $form) {
    $this->actingAs($this->jane);

    $mounted = Livewire::test($form['component'], $form['params'])
        ->assertSet('editLockBlocked', false);

    $lock = EditLock::query()->sole();

    expect($mounted->get('editLockToken'))->toHaveLength(40)
        ->and($lock->user_id)->toBe($this->jane->id)
        ->and($lock->lockable_type)->toBe($form['record']::class)
        ->and($lock->lockable_id)->toBe($form['record']->getKey());
})->with($forms);

it('lets the member holding the lock save', function (array $form) {
    $this->actingAs($this->jane);

    ($form['save'])(Livewire::test($form['component'], $form['params']))
        ->assertHasNoErrors();

    expect(($form['changed'])())->toBeTrue();
})->with($forms);

it('shows a second member who is editing instead of the form, and refuses a crafted save', function (array $form) {
    $this->actingAs($this->jane);
    Livewire::test($form['component'], $form['params']);

    $this->actingAs($this->bob);
    $bobs = Livewire::test($form['component'], $form['params'])
        ->assertSet('editLockBlocked', true)
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => $form['noun']]));

    ($form['save'])($bobs)->assertDispatched('toast-show');

    expect(($form['changed'])())->toBeFalse()
        ->and(EditLock::query()->sole()->user_id)->toBe($this->jane->id);
})->with($forms);

it('guards exactly the show page methods that change the record', function (string $component, array $methods) {
    $class = app('livewire.factory')->resolveComponentClass($component);

    $guarded = collect((new ReflectionClass($class))->getMethods())
        ->filter(fn (ReflectionMethod $m) => $m->getAttributes(GuardsEditLock::class) !== [])
        ->map->getName()
        ->sort()->values()->all();

    expect($guarded)->toBe($methods);
})->with([
    'vendor credit' => ['pages::vendor-credits.show', ['confirmDelete', 'post', 'void']],
    'reimbursement' => ['pages::reimbursements.show', ['confirmDelete', 'void']],
    'pay run' => ['pages::payroll.pay-runs.show', ['post', 'recalculate', 'resetAdjust', 'saveAdjust', 'voidCheque', 'voidRun', 'writeCheques']],
]);

it('refuses a show page action while another member is editing', function (array $page) {
    $this->locks->acquire($page['record'], $this->jane);

    $this->actingAs($this->bob);
    Livewire::test($page['component'], $page['params'])
        ->assertSee(__(':name is editing this :noun', ['name' => $this->jane->name, 'noun' => $page['noun']]))
        ->call($page['action'])
        ->assertDispatched('toast-show');

    expect(($page['untouched'])())->toBeTrue();
})->with([
    'vendor credit: post' => function () {
        $record = ($this->makeVendorCredit)();

        return [
            'component' => 'pages::vendor-credits.show',
            'params' => ['company' => $this->company, 'vendor_credit' => $record],
            'record' => $record,
            'noun' => 'vendor credit',
            'action' => 'post',
            'untouched' => fn (): bool => $record->fresh()->status === VendorCreditStatus::Draft,
        ];
    },
    'reimbursement: confirmDelete' => function () {
        $record = ($this->makeReimbursement)();

        return [
            'component' => 'pages::reimbursements.show',
            'params' => ['company' => $this->company, 'bill' => $record],
            'record' => $record,
            'noun' => 'reimbursement',
            'action' => 'confirmDelete',
            'untouched' => fn (): bool => Bill::query()->whereKey($record->id)->exists(),
        ];
    },
    'pay run: recalculate' => function () {
        $record = ($this->makePayRun)();

        return [
            'component' => 'pages::payroll.pay-runs.show',
            'params' => ['company' => $this->company, 'payRun' => $record],
            'record' => $record,
            'noun' => 'pay run',
            'action' => 'recalculate',
            'untouched' => fn (): bool => $record->fresh()->status === PayRunStatus::Draft,
        ];
    },
]);

it('lets the member holding the lock post a vendor credit from its show page, replacing the version', function () {
    $credit = ($this->makeVendorCredit)();
    $held = $this->locks->acquire($credit, $this->jane);

    $this->actingAs($this->jane);
    Livewire::test('pages::vendor-credits.show', ['company' => $this->company, 'vendor_credit' => $credit])
        ->call('post');

    expect($credit->fresh()->status)->toBe(VendorCreditStatus::Posted)
        ->and($this->locks->currentVersion($credit))->not->toBe($held->version);
});
