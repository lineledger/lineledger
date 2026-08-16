<?php

use App\Actions\Charity\SaveDonationReceipt;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\DonationReceiptStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Charity\DonationReceiptIssuer;
use App\Services\Charity\DonationReceiptPdfRenderer;
use App\Services\Charity\T3010Calculator;
use App\Services\Reporting\ReportCalculator;
use App\Support\Reporting\ReportCatalog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'organization_type' => 'charity',
        'charity_registration_number' => '123456789RR0001',
        'fiscal_year_start_month' => 1,
    ]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function donationReceiptData(array $overrides = []): array
{
    return array_merge([
        'donor_name' => 'Jane Donor',
        'gift_type' => 'cash',
        'gift_date' => '2026-03-01',
        'amount_cents' => 10000,
        'advantage_cents' => 0,
    ], $overrides);
}

function charityEntry(string $date, array $lines): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $order = 0;
    foreach ($lines as [$accountId, $debit, $credit]) {
        $entry->lines()->create(['account_id' => $accountId, 'debit_cents' => $debit, 'credit_cents' => $credit, 'line_order' => $order++]);
    }
}

test('saving a receipt assigns a serial number and freezes the eligible amount', function () {
    $first = app(SaveDonationReceipt::class)->handle(donationReceiptData(['amount_cents' => 10000, 'advantage_cents' => 2500]));
    $second = app(SaveDonationReceipt::class)->handle(donationReceiptData());

    expect($first->receipt_no)->toBe('DR-000001');
    expect($second->receipt_no)->toBe('DR-000002');
    expect($first->eligible_amount_cents)->toBe(7500);
});

test('issuing a cash receipt posts no journal entry and does not double-count revenue', function () {
    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData());
    app(DonationReceiptIssuer::class)->issue($receipt);

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Issued);
    expect($receipt->fresh()->journal_entry_id)->toBeNull();
    expect(app(ReportCalculator::class)->balanceAsOf($this->income->fresh(), CarbonImmutable::parse('2026-12-31')))->toBe(0);
});

test('issuing an in-kind receipt posts DR asset / CR donations at fair market value', function () {
    $asset = Account::query()->where('type', AccountType::Asset->value)->where('subtype', AccountSubtype::FixedAsset->value)->orderBy('code')->first()
        ?? Account::query()->where('type', AccountType::Asset->value)->orderBy('code')->first();

    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData([
        'gift_type' => 'in_kind',
        'amount_cents' => 80000,
        'in_kind_description' => 'Donated laptop',
        'debit_account_id' => $asset->id,
        'revenue_account_id' => $this->income->id,
    ]));
    app(DonationReceiptIssuer::class)->issue($receipt);

    expect($receipt->fresh()->journal_entry_id)->not->toBeNull();

    $calc = app(ReportCalculator::class);
    expect($calc->balanceAsOf($this->income->fresh(), CarbonImmutable::parse('2026-12-31')))->toBe(80000);
    expect($calc->balanceAsOf($asset->fresh(), CarbonImmutable::parse('2026-12-31')))->toBe(80000);
});

test('voiding a receipt retains its serial and a new receipt does not reuse the number', function () {
    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData());
    app(DonationReceiptIssuer::class)->issue($receipt);

    app(DonationReceiptIssuer::class)->void($receipt->fresh(), 'Donor request');

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void);
    expect($receipt->fresh()->receipt_no)->toBe('DR-000001');

    $next = app(SaveDonationReceipt::class)->handle(donationReceiptData());
    expect($next->receipt_no)->toBe('DR-000002');
});

test('reissuing voids the original and links a fresh draft', function () {
    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData());
    app(DonationReceiptIssuer::class)->issue($receipt);

    $reissued = app(DonationReceiptIssuer::class)->reissue($receipt->fresh());

    expect($receipt->fresh()->status)->toBe(DonationReceiptStatus::Void);
    expect($reissued->status)->toBe(DonationReceiptStatus::Draft);
    expect($reissued->reissued_from_id)->toBe($receipt->id);
    expect($reissued->receipt_no)->not->toBe($receipt->receipt_no);
});

test('a receipt cannot be issued when the advantage equals the gift', function () {
    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData([
        'amount_cents' => 10000,
        'advantage_cents' => 10000,
        'advantage_description' => 'Gala dinner',
    ]));
    app(DonationReceiptIssuer::class)->issue($receipt);
})->throws(InvalidArgumentException::class);

test('an issued receipt cannot be edited', function () {
    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData());
    app(DonationReceiptIssuer::class)->issue($receipt);
    app(SaveDonationReceipt::class)->handle(donationReceiptData(['amount_cents' => 999]), $receipt->fresh());
})->throws(InvalidArgumentException::class);

test('the T3010 figures tie to issued receipts and the general ledger', function () {
    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData(['amount_cents' => 20000]));
    app(DonationReceiptIssuer::class)->issue($receipt);

    $program = $this->company->accounts()->create([
        'code' => '6300', 'name' => 'Program Expenses',
        'type' => AccountType::Expense->value, 'subtype' => AccountSubtype::Expense->value,
        'normal_balance' => AccountType::Expense->normalBalance()->value, 'is_active' => true,
    ]);
    charityEntry('2026-05-01', [[$program->id, 15000, 0], [$this->bank->id, 0, 15000]]);

    $summary = app(T3010Calculator::class)->summary($this->company, CarbonImmutable::create(2026, 1, 1), CarbonImmutable::create(2026, 12, 31));

    expect($summary['total_eligible_receipted_cents'])->toBe(20000);
    expect($summary['charitable_program_cents'])->toBe(15000);
    expect($summary['total_expenditures_cents'])->toBe(15000);
});

test('the donor flag has a factory state and is cast to a boolean', function () {
    $donor = Contact::factory()->donor()->create();
    expect($donor->is_donor)->toBeTrue();
});

test('the official receipt PDF view contains all CRA-mandated fields', function () {
    $receipt = app(SaveDonationReceipt::class)->handle(donationReceiptData([
        'amount_cents' => 25000,
        'advantage_cents' => 5000,
        'advantage_description' => 'Dinner',
        'donor_line1' => '1 King St',
        'donor_city' => 'Halifax',
    ]));
    app(DonationReceiptIssuer::class)->issue($receipt);

    $data = app(DonationReceiptPdfRenderer::class)->data($this->company, $receipt->fresh());
    $html = view('pdf.donations.receipt', $data)->render();

    expect($html)
        ->toContain('Official receipt for income tax purposes')
        ->toContain('123456789RR0001')
        ->toContain('DR-000001')
        ->toContain('Jane Donor')
        ->toContain('Eligible amount of gift for tax purposes')
        ->toContain('canada.ca/charities-giving')
        ->toContain('200.00'); // eligible = $250 FMV − $50 advantage
});

test('the T3010 report is gated to registered charities', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    expect(array_keys(ReportCatalog::flatten($this->company, $user)))->toContain('reports.t3010');
    Livewire::test('pages::reports.t3010', ['company' => $this->company])->assertOk();

    $forProfit = Company::factory()->create(['organization_type' => 'corporation', 'address_country' => 'CA']);
    $forProfit->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    expect(array_keys(ReportCatalog::flatten($forProfit, $user)))->not->toContain('reports.t3010');
    Livewire::test('pages::reports.t3010', ['company' => $forProfit])->assertStatus(403);
});
