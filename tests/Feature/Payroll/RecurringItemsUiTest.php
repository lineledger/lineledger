<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeRecurringItem;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = Contact::create(['display_name' => 'Dana Deduction', 'is_employee' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/** The setup form with the required base fields filled in. */
function recurringForm()
{
    return Livewire::test('pages::payroll.employees.form', ['company' => test()->company, 'contact' => test()->employee->fresh()])
        ->set('province_of_employment', 'AB')
        ->set('pay_basis', 'salary')
        ->set('annual_salary', '60000')
        ->set('payroll_schedule_id', test()->schedule->id)
        ->set('td1_federal_claim', '16129')
        ->set('td1_provincial_claim', '22323');
}

/** Seed a profile carrying one recurring item via the shared action. */
function seedProfileWithItem(): void
{
    app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => test()->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => 'salary',
        'annual_salary_cents' => 6000000,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
        'recurring_items' => [[
            'kind' => 'deduction', 'code' => 'union', 'name' => 'Union dues',
            'calc_type' => 'fixed', 'amount_cents' => 2500,
        ]],
    ]);
}

it('adds a recurring deduction through the form and persists it', function () {
    recurringForm()
        ->call('addRecurringItem', 'deduction')
        ->set('recurring_items.0.name', 'RRSP')
        ->set('recurring_items.0.code', 'rrsp')
        ->set('recurring_items.0.amount', '50')
        ->set('recurring_items.0.pre_tax_federal', true)
        ->set('recurring_items.0.pre_tax_provincial', true)
        ->call('save')
        ->assertHasNoErrors();

    $item = EmployeeRecurringItem::query()->firstOrFail();
    expect($item->kind)->toBe('deduction')
        ->and($item->code)->toBe('rrsp')
        ->and($item->amount_cents)->toBe(5000)
        ->and($item->pre_tax_federal)->toBeTrue()
        ->and($item->reduces_taxable)->toBeTrue() // derived from the pre-tax flags
        ->and($item->company_id)->toBe($this->company->id);
});

it('stores a percent-of-gross earning as basis points', function () {
    recurringForm()
        ->call('addRecurringItem', 'earning')
        ->set('recurring_items.0.name', 'Commission')
        ->set('recurring_items.0.code', 'commission')
        ->set('recurring_items.0.calc_type', 'percent_of_gross')
        ->set('recurring_items.0.percent', '5')
        ->call('save')
        ->assertHasNoErrors();

    $item = EmployeeRecurringItem::query()->firstOrFail();
    expect($item->kind)->toBe('earning')
        ->and($item->calc_type)->toBe('percent_of_gross')
        ->and($item->percent_bp)->toBe(500)
        ->and($item->amount_cents)->toBeNull();
});

it('adds an accrual item with a calculation basis', function () {
    recurringForm()
        ->call('addRecurringItem', 'accrual')
        ->set('recurring_items.0.name', 'Banked time')
        ->set('recurring_items.0.code', 'banked')
        ->set('recurring_items.0.calc_basis', 'percent_of_hours')
        ->set('recurring_items.0.percent', '100')
        ->call('save')
        ->assertHasNoErrors();

    $item = EmployeeRecurringItem::query()->firstOrFail();
    expect($item->kind)->toBe('accrual')
        ->and($item->calc_basis)->toBe('percent_of_hours')
        ->and($item->percent_bp)->toBe(10000);
});

it('flows a recurring earning into a calculated pay run', function () {
    recurringForm()
        ->call('addRecurringItem', 'earning')
        ->set('recurring_items.0.name', 'Car allowance')
        ->set('recurring_items.0.code', 'allowance')
        ->set('recurring_items.0.amount', '100')
        ->call('save')
        ->assertHasNoErrors();

    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    $line = $run->fresh()->lines->first();

    // $60,000/26 = $2,307.69 regular + $100 allowance = $2,407.69 gross.
    expect($line->gross_cents)->toBe(240769)
        ->and($line->earnings->firstWhere('code', 'allowance')?->amount_cents)->toBe(10000);
});

it('hydrates existing recurring items when editing', function () {
    seedProfileWithItem();

    Livewire::test('pages::payroll.employees.form', ['company' => $this->company, 'contact' => $this->employee->fresh()])
        ->assertSet('recurring_items.0.code', 'union')
        ->assertSet('recurring_items.0.kind', 'deduction');
});

it('removes a recurring item on save', function () {
    seedProfileWithItem();
    expect(EmployeeRecurringItem::query()->count())->toBe(1);

    recurringForm()
        ->call('removeRecurringItem', 0)
        ->call('save')
        ->assertHasNoErrors();

    expect(EmployeeRecurringItem::query()->count())->toBe(0);
});
