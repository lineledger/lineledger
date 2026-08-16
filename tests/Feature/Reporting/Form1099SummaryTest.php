<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\User;
use App\Services\Reporting\Form1099Calculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['address_country' => 'US']);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->year = 2025;
});

afterEach(fn () => app()->forgetInstance('current_company'));

function payVendor(Contact $vendor, Account $bank, int $cents, string $date): void
{
    BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_no' => 'PAY-'.uniqid(),
        'payment_date' => $date,
        'paid_from_account_id' => $bank->id,
        'amount_cents' => $cents,
        'status' => 'posted',
    ]);
}

function chequeToVendor(Contact $vendor, Account $bank, int $cents, string $date, ?int $creditMemoId = null): void
{
    Cheque::create([
        'bank_account_id' => $bank->id,
        'cheque_no' => 'CHQ-'.uniqid(),
        'cheque_date' => $date,
        'payee_contact_id' => $vendor->id,
        'payee_name' => $vendor->display_name,
        'credit_memo_id' => $creditMemoId,
        'amount_cents' => $cents,
        'status' => 'posted',
    ]);
}

it('sums posted bill payments and direct cheques to a flagged vendor in the year', function () {
    $vendor = Contact::create(['display_name' => 'Contractor Co', 'is_vendor' => true, 'track_1099' => true, 'tax_number' => '12-3456789']);

    payVendor($vendor, $this->bank, 40000, '2025-03-01');
    chequeToVendor($vendor, $this->bank, 25000, '2025-07-15');
    // Out of year and draft are ignored.
    payVendor($vendor, $this->bank, 99999, '2024-12-31');

    $rows = app(Form1099Calculator::class)->rows(
        $this->company,
        CarbonImmutable::create(2025, 1, 1),
        CarbonImmutable::create(2025, 12, 31)->endOfDay(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total_cents'])->toBe(65000)
        ->and($rows[0]['meets_threshold'])->toBeTrue()
        ->and($rows[0]['tax_number'])->toBe('12-3456789');
});

it('excludes customer-refund cheques (those tied to a credit memo)', function () {
    $vendor = Contact::create(['display_name' => 'Refund Co', 'is_vendor' => true, 'is_customer' => true, 'track_1099' => true]);

    $creditMemo = CreditMemo::create([
        'contact_id' => $vendor->id,
        'credit_memo_no' => 'CM-1',
        'credit_memo_date' => '2025-02-01',
    ]);

    payVendor($vendor, $this->bank, 70000, '2025-02-01');
    chequeToVendor($vendor, $this->bank, 5000, '2025-02-02', $creditMemo->id); // refund — excluded

    $rows = app(Form1099Calculator::class)->rows(
        $this->company,
        CarbonImmutable::create(2025, 1, 1),
        CarbonImmutable::create(2025, 12, 31)->endOfDay(),
    );

    expect($rows[0]['total_cents'])->toBe(70000);
});

it('flags vendors paid below the $600 threshold', function () {
    $vendor = Contact::create(['display_name' => 'Small Co', 'is_vendor' => true, 'track_1099' => true]);
    payVendor($vendor, $this->bank, 30000, '2025-05-01'); // $300

    $rows = app(Form1099Calculator::class)->rows(
        $this->company,
        CarbonImmutable::create(2025, 1, 1),
        CarbonImmutable::create(2025, 12, 31)->endOfDay(),
    );

    expect($rows[0]['meets_threshold'])->toBeFalse();
});

it('ignores vendors not flagged for 1099', function () {
    $vendor = Contact::create(['display_name' => 'Untracked Co', 'is_vendor' => true, 'track_1099' => false]);
    payVendor($vendor, $this->bank, 80000, '2025-05-01');

    $rows = app(Form1099Calculator::class)->rows(
        $this->company,
        CarbonImmutable::create(2025, 1, 1),
        CarbonImmutable::create(2025, 12, 31)->endOfDay(),
    );

    expect($rows)->toBeEmpty();
});

it('renders the 1099 report for a US company and hides below-threshold by default', function () {
    $big = Contact::create(['display_name' => 'Big Co', 'is_vendor' => true, 'track_1099' => true]);
    $small = Contact::create(['display_name' => 'Tiny Co', 'is_vendor' => true, 'track_1099' => true]);
    payVendor($big, $this->bank, 90000, '2025-04-01');
    payVendor($small, $this->bank, 10000, '2025-04-01');

    $rows = Livewire::actingAs($this->user)
        ->test('pages::reports.form-1099', ['company' => $this->company])
        ->set('year', 2025)
        ->instance()
        ->rows();

    $names = collect($rows)->pluck('name');
    expect($names)->toContain('Big Co')->not->toContain('Tiny Co');
});

it('forbids the 1099 report for a non-US company', function () {
    app()->forgetInstance('current_company');

    $ca = Company::factory()->create(['address_country' => 'CA']);
    $user = User::factory()->create();
    $ca->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user)
        ->get(route('reports.form-1099', ['company' => $ca->slug]))
        ->assertForbidden();
});
