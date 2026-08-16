<?php

use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Fundraising\SaveDonation;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\ContributionMethod;
use App\Enums\DonationStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Donation;
use App\Models\DonationReceipt;
use App\Models\Fund;
use App\Models\User;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Charity\DonationReceiptIssuer;
use App\Services\Fundraising\DonationPoster;
use Livewire\Livewire;

function fundraisingCompany(array $overrides = []): Company
{
    $company = Company::factory()->create(array_merge([
        'address_country' => 'CA',
        'organization_type' => 'non_profit',
        'industry' => 'non_profit',
        'features_fundraising' => true,
        'fiscal_year_start_month' => 1,
    ], $overrides));

    app()->instance('current_company', $company);

    // Mirror the settings flow: enabling fundraising backfills the donation/grant
    // revenue + deferred liability accounts (the factory seeds only the generic chart).
    app(EnsureFundraisingAccounts::class)->handle($company);

    return $company;
}

beforeEach(function () {
    $this->company = fundraisingCompany();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->donationRevenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Donation%')->first();
    $this->deferred = Account::query()->where('name', 'like', '%Deferred%')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

test('an unrestricted donation posts DR deposit / CR donation revenue', function () {
    $donation = app(SaveDonation::class)->handle([
        'donation_date' => '2026-03-01',
        'amount_cents' => 20000,
        'deposit_to_account_id' => $this->bank->id,
        'revenue_account_id' => $this->donationRevenue->id,
        'is_restricted' => false,
    ]);

    $entry = app(DonationPoster::class)->post($donation);
    $entry->load('lines');

    expect($donation->fresh()->status)->toBe(DonationStatus::Posted);
    expect($entry->lines->sum('debit_cents'))->toBe($entry->lines->sum('credit_cents'))->toBe(20000);
    expect($entry->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(20000);
    expect($entry->lines->firstWhere('account_id', $this->donationRevenue->id)->credit_cents)->toBe(20000);
});

test('a restricted donation under the deferral method credits the deferred liability', function () {
    expect($this->company->usesDeferralMethod())->toBeTrue();

    $donation = app(SaveDonation::class)->handle([
        'donation_date' => '2026-03-01',
        'amount_cents' => 15000,
        'deposit_to_account_id' => $this->bank->id,
        'is_restricted' => true,
        'deferred_account_id' => $this->deferred->id,
    ]);

    $entry = app(DonationPoster::class)->post($donation)->load('lines');

    expect($entry->lines->firstWhere('account_id', $this->deferred->id)->credit_cents)->toBe(15000);
    expect($entry->lines->firstWhere('account_id', $this->donationRevenue->id))->toBeNull();
});

test('a restricted donation under the restricted-fund method credits revenue tagged with the fund', function () {
    $company = fundraisingCompany([
        'contribution_method' => ContributionMethod::RestrictedFund->value,
        'features_funds' => true,
    ]);
    $company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    expect($company->usesRestrictedFundMethod())->toBeTrue();
    expect($company->tracksFunds())->toBeTrue();

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $revenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Donation%')->first();
    $fund = Fund::create(['name' => 'Building Fund', 'fund_type' => 'restricted', 'is_active' => true]);

    $donation = app(SaveDonation::class)->handle([
        'donation_date' => '2026-03-01',
        'amount_cents' => 30000,
        'deposit_to_account_id' => $bank->id,
        'revenue_account_id' => $revenue->id,
        'is_restricted' => true,
        'fund_id' => $fund->id,
    ]);

    $entry = app(DonationPoster::class)->post($donation)->load('lines');

    $revenueLine = $entry->lines->firstWhere('account_id', $revenue->id);
    expect($revenueLine->credit_cents)->toBe(30000);
    expect($revenueLine->fund_id)->toBe($fund->id);
});

test('voiding a donation writes a balanced reversing entry', function () {
    $donation = app(SaveDonation::class)->handle([
        'donation_date' => '2026-03-01',
        'amount_cents' => 10000,
        'deposit_to_account_id' => $this->bank->id,
        'revenue_account_id' => $this->donationRevenue->id,
    ]);
    app(DonationPoster::class)->post($donation);

    app(DonationPoster::class)->void($donation->fresh());

    expect($donation->fresh()->status)->toBe(DonationStatus::Void);
    expect($this->donationRevenue->fresh()->recomputeBalance())->toBe(0);
});

test('a cash donation that spawns a receipt does not double-count revenue', function () {
    $charity = fundraisingCompany([
        'organization_type' => 'charity',
        'charity_registration_number' => '123456789RR0001',
    ]);
    $charity->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $revenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Donation%')->first();

    $donation = app(SaveDonation::class)->handle([
        'donation_date' => '2026-03-01',
        'amount_cents' => 25000,
        'gift_type' => 'cash',
        'deposit_to_account_id' => $bank->id,
        'revenue_account_id' => $revenue->id,
        'issue_receipt' => true,
    ]);

    expect($donation->donation_receipt_id)->not->toBeNull();
    $receipt = DonationReceipt::find($donation->donation_receipt_id);
    expect($receipt->debit_account_id)->toBeNull();

    // The donation books the revenue once.
    app(DonationPoster::class)->post($donation);
    // Issuing the spawned cash receipt posts no GL (debit_account_id is null).
    app(DonationReceiptIssuer::class)->issue($receipt->fresh());

    expect($receipt->fresh()->journal_entry_id)->toBeNull();
    expect((int) $revenue->fresh()->recomputeBalance())->toBe(25000);
});

test('EnsureFundraisingAccounts backfills accounts for a non-profit-less company', function () {
    $forProfit = Company::factory()->create(['organization_type' => 'corporation', 'industry' => 'general', 'features_fundraising' => true]);
    app()->instance('current_company', $forProfit);

    $created = app(EnsureFundraisingAccounts::class)->handle($forProfit);

    expect($created)->toBeGreaterThan(0);
    expect(Account::query()->where('name', 'like', '%Donation%')->where('type', AccountType::Income->value)->exists())->toBeTrue();
    expect(Account::query()->where('name', 'like', '%Grant%')->where('type', AccountType::Income->value)->exists())->toBeTrue();
    expect(Account::query()->where('name', 'like', '%Deferred%')->exists())->toBeTrue();
});

test('the donation pages are gated on the fundraising feature flag', function () {
    Livewire::test('pages::donations.index', ['company' => $this->company])->assertOk();

    $off = Company::factory()->create(['features_fundraising' => false]);
    $off->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $off);

    Livewire::test('pages::donations.index', ['company' => $off])->assertStatus(403);
});

test('donations is registered for backup', function () {
    expect(array_column(BackupTableRegistry::tables(), 'table'))->toContain('donations');
});
