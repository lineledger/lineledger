<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Actions\Funds\RecordInterfundTransfer;
use App\Actions\MasterData\EnsureDefaultFund;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Fund;
use App\Services\Posting\JournalPoster;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'organization_type' => 'charity',
        'contribution_method' => 'restricted_fund',
        'charity_registration_number' => '123456789RR0001',
        'features_funds' => true,
    ]);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

test('tracksFunds requires both the feature flag and the restricted fund method', function () {
    expect($this->company->tracksFunds())->toBeTrue();

    $this->company->update(['contribution_method' => 'deferral']);
    expect($this->company->fresh()->tracksFunds())->toBeFalse();

    $this->company->update(['contribution_method' => 'restricted_fund', 'features_funds' => false]);
    expect($this->company->fresh()->tracksFunds())->toBeFalse();
});

test('EnsureDefaultFund creates exactly one default General Fund and is idempotent', function () {
    $first = app(EnsureDefaultFund::class)->handle($this->company);
    $second = app(EnsureDefaultFund::class)->handle($this->company);

    expect($first->id)->toBe($second->id);
    expect($first->is_default)->toBeTrue();
    expect($first->name)->toBe('General Fund');
    expect(Fund::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->where('is_default', true)->count())->toBe(1);
});

test('a journal entry tags its lines with a fund and balances filter by fund', function () {
    $general = app(EnsureDefaultFund::class)->handle($this->company);
    $building = Fund::create(['name' => 'Building Fund', 'fund_type' => 'restricted']);

    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-03-01',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 50000, 'credit_cents' => 0, 'fund_id' => $building->id],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 50000, 'fund_id' => $building->id],
        ],
    ]);
    app(JournalPoster::class)->post($entry);

    $calc = app(ReportCalculator::class);
    // Revenue is attributed to the Building Fund, not the General Fund.
    expect($calc->balanceAsOf($this->income->fresh(), CarbonImmutable::parse('2026-12-31'), $building->id))->toBe(50000);
    expect($calc->balanceAsOf($this->income->fresh(), CarbonImmutable::parse('2026-12-31'), $general->id))->toBe(0);
    // Unfiltered, the full amount is present.
    expect($calc->balanceAsOf($this->income->fresh(), CarbonImmutable::parse('2026-12-31')))->toBe(50000);
});

test('an interfund transfer keeps each fund self-balancing and the transfer account at zero', function () {
    $general = Fund::create(['name' => 'General', 'fund_type' => 'general', 'is_default' => true]);
    $building = Fund::create(['name' => 'Building', 'fund_type' => 'restricted']);

    $entry = app(RecordInterfundTransfer::class)->handle(
        company: $this->company,
        fromFundId: $general->id,
        toFundId: $building->id,
        amountCents: 25000,
        date: '2026-04-01',
        cashAccountId: $this->bank->id,
    );

    // Within each fund, debits equal credits (the fund is its own balanced set).
    foreach ([$general->id, $building->id] as $fundId) {
        $lines = $entry->lines()->where('fund_id', $fundId)->get();
        expect($lines->sum('debit_cents'))->toBe($lines->sum('credit_cents'));
    }

    $calc = app(ReportCalculator::class);

    // The interfund transfer account nets to zero company-wide.
    $interfund = Account::query()->where('code', '3950')->firstOrFail();
    expect($calc->balanceAsOf($interfund->fresh(), CarbonImmutable::parse('2026-12-31')))->toBe(0);

    // Cash shifted from the General Fund to the Building Fund.
    expect($calc->balanceAsOf($this->bank->fresh(), CarbonImmutable::parse('2026-12-31'), $general->id))->toBe(-25000);
    expect($calc->balanceAsOf($this->bank->fresh(), CarbonImmutable::parse('2026-12-31'), $building->id))->toBe(25000);
});

test('an interfund transfer rejects a same-fund transfer', function () {
    $general = Fund::create(['name' => 'General', 'fund_type' => 'general', 'is_default' => true]);

    app(RecordInterfundTransfer::class)->handle(
        company: $this->company,
        fromFundId: $general->id,
        toFundId: $general->id,
        amountCents: 10000,
        date: '2026-04-01',
        cashAccountId: $this->bank->id,
    );
})->throws(InvalidArgumentException::class);
