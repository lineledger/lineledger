<?php

use App\Actions\Accounting\RecognizeDeferredContribution;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\ContributionMethod;
use App\Enums\FundType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Fund;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Reporting\ReportCalculator;
use App\Support\Reporting\ReportCatalog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'fiscal_year_start_month' => 1,
        'address_country' => 'CA',
        'organization_type' => 'charity',
        'contribution_method' => 'deferral',
        'charity_registration_number' => '123456789RR0001',
    ]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('type', AccountType::Asset->value)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @param  array<int, array{0: int, 1: int, 2: int}>  $lines  [accountId, debitCents, creditCents]
 */
function npoEntry(string $date, array $lines): void
{
    $entry = JournalEntry::create(['entry_no' => uniqid('JE-'), 'entry_date' => $date, 'is_posted' => true]);
    $order = 0;
    foreach ($lines as [$accountId, $debit, $credit]) {
        $entry->lines()->create(['account_id' => $accountId, 'debit_cents' => $debit, 'credit_cents' => $credit, 'line_order' => $order++]);
    }
}

function npoAccount(Company $company, string $code, string $name, AccountType $type, AccountSubtype $subtype): Account
{
    return $company->accounts()->create([
        'code' => $code,
        'name' => $name,
        'type' => $type->value,
        'subtype' => $subtype->value,
        'normal_balance' => $type->normalBalance()->value,
        'is_active' => true,
    ]);
}

test('the statement of changes in net assets reconciles and breaks down by class', function () {
    $restricted = npoAccount($this->company, '3250', 'Building Fund', AccountType::Equity, AccountSubtype::RestrictedNetAssets);
    $endowment = npoAccount($this->company, '3350', 'Endowment', AccountType::Equity, AccountSubtype::EndowmentNetAssets);

    npoEntry('2026-03-01', [[$this->bank->id, 50000, 0], [$this->income->id, 0, 50000]]);   // revenue
    npoEntry('2026-04-01', [[$this->expense->id, 20000, 0], [$this->bank->id, 0, 20000]]);   // expense
    npoEntry('2026-05-01', [[$this->bank->id, 30000, 0], [$restricted->id, 0, 30000]]);      // restricted contribution
    npoEntry('2026-06-01', [[$this->bank->id, 10000, 0], [$endowment->id, 0, 10000]]);       // endowment contribution

    $report = app(ReportCalculator::class)->netAssetRollForward(
        $this->company,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-12-31'),
    );

    expect($report['reconciles'])->toBeTrue();
    expect($report['classes']['unrestricted']['excess'])->toBe(30000);   // 50000 − 20000
    expect($report['classes']['unrestricted']['closing'])->toBe(30000);
    expect($report['classes']['restricted']['closing'])->toBe(30000);
    expect($report['classes']['restricted']['other'])->toBe(30000);
    expect($report['classes']['endowment']['closing'])->toBe(10000);
    expect($report['total']['closing'])->toBe(70000);
});

test('recognizing a deferred contribution moves it from the liability into revenue', function () {
    $deferred = npoAccount($this->company, '2500', 'Deferred / Restricted Grants', AccountType::Liability, AccountSubtype::CurrentLiability);
    $grantRevenue = npoAccount($this->company, '4150', 'Grant Revenue', AccountType::Income, AccountSubtype::Income);

    npoEntry('2026-01-01', [[$this->bank->id, 30000, 0], [$deferred->id, 0, 30000]]); // receive grant into deferred liability

    app(RecognizeDeferredContribution::class)->handle(
        company: $this->company,
        liabilityAccountId: $deferred->id,
        revenueAccountId: $grantRevenue->id,
        amountCents: 20000,
        date: '2026-02-01',
    );

    $calc = app(ReportCalculator::class);
    expect($calc->balanceAsOf($deferred->fresh(), CarbonImmutable::parse('2026-02-28')))->toBe(10000);
    expect($calc->periodChange($grantRevenue->fresh(), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31')))->toBe(20000);
});

test('recognizing more than the deferred balance is rejected', function () {
    $deferred = npoAccount($this->company, '2500', 'Deferred / Restricted Grants', AccountType::Liability, AccountSubtype::CurrentLiability);
    $grantRevenue = npoAccount($this->company, '4150', 'Grant Revenue', AccountType::Income, AccountSubtype::Income);
    npoEntry('2026-01-01', [[$this->bank->id, 30000, 0], [$deferred->id, 0, 30000]]);

    app(RecognizeDeferredContribution::class)->handle(
        company: $this->company,
        liabilityAccountId: $deferred->id,
        revenueAccountId: $grantRevenue->id,
        amountCents: 50000,
        date: '2026-02-01',
    );
})->throws(InvalidArgumentException::class);

test('the three ASNPO statements render for a non-profit', function () {
    npoEntry('2026-03-01', [[$this->bank->id, 50000, 0], [$this->income->id, 0, 50000]]);

    Livewire::test('pages::reports.statement-of-financial-position', ['company' => $this->company])->assertOk();
    Livewire::test('pages::reports.statement-of-operations', ['company' => $this->company])
        ->assertOk()
        ->assertSee('Recognize deferred contribution');
    Livewire::test('pages::reports.statement-of-changes-in-net-assets', ['company' => $this->company])->assertOk();
});

test('the deferral recognition modal posts through the statement of operations', function () {
    $deferred = npoAccount($this->company, '2500', 'Deferred / Restricted Grants', AccountType::Liability, AccountSubtype::CurrentLiability);
    $grantRevenue = npoAccount($this->company, '4150', 'Grant Revenue', AccountType::Income, AccountSubtype::Income);
    npoEntry('2026-01-01', [[$this->bank->id, 30000, 0], [$deferred->id, 0, 30000]]);

    Livewire::test('pages::reports.statement-of-operations', ['company' => $this->company])
        ->call('openRecognizeModal')
        ->set('recLiabilityAccountId', $deferred->id)
        ->set('recRevenueAccountId', $grantRevenue->id)
        ->set('recAmount', '150.00')
        ->set('recDate', '2026-02-01')
        ->call('recognizeDeferred')
        ->assertHasNoErrors();

    expect(app(ReportCalculator::class)->balanceAsOf($deferred->fresh(), CarbonImmutable::parse('2026-02-28')))->toBe(15000);
});

test('the non-profit statements are gated to non-profit companies in the report catalog', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $keys = array_keys(ReportCatalog::flatten($this->company, $user));
    expect($keys)->toContain('reports.statement-of-financial-position', 'reports.statement-of-operations', 'reports.statement-of-changes-in-net-assets');

    $forProfit = Company::factory()->create(['organization_type' => 'corporation', 'address_country' => 'CA']);
    $forProfit->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $fpKeys = array_keys(ReportCatalog::flatten($forProfit, $user));
    expect($fpKeys)->not->toContain('reports.statement-of-financial-position');
});

test('the statement of operations filters its figures and drill links by fund', function () {
    // Regression guard: the report exposed a fund filter but never passed it into
    // periodChange(), so the fund selector silently did nothing.
    $this->company->update([
        'contribution_method' => ContributionMethod::RestrictedFund,
        'features_funds' => true,
    ]);
    $fundA = Fund::create(['name' => 'Building Fund', 'fund_type' => FundType::Restricted, 'is_default' => false, 'is_active' => true]);
    $fundB = Fund::create(['name' => 'Program Fund', 'fund_type' => FundType::Restricted, 'is_default' => false, 'is_active' => true]);

    // Same income account, two funds: $123.00 to A, $45.00 to B.
    $a = JournalEntry::create(['entry_no' => 'JE-FA', 'entry_date' => '2026-03-01', 'is_posted' => true]);
    $a->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 12300, 'credit_cents' => 0, 'line_order' => 0, 'fund_id' => $fundA->id]);
    $a->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 12300, 'line_order' => 1, 'fund_id' => $fundA->id]);

    $b = JournalEntry::create(['entry_no' => 'JE-FB', 'entry_date' => '2026-03-01', 'is_posted' => true]);
    $b->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 4500, 'credit_cents' => 0, 'line_order' => 0, 'fund_id' => $fundB->id]);
    $b->lines()->create(['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 4500, 'line_order' => 1, 'fund_id' => $fundB->id]);

    Livewire::test('pages::reports.statement-of-operations', ['company' => $this->company])
        ->set('preset', 'this_fiscal_year')
        ->set('fundId', $fundA->id)
        ->assertSee('123.00')                 // fund A's figure only
        ->assertDontSee('168.00')             // the unfiltered A+B total must NOT appear
        ->assertSeeHtml('fund='.$fundA->id);  // drill link carries the fund filter
});
