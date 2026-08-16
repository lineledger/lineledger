<?php

use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Fundraising\SaveGrant;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Enums\ContributionMethod;
use App\Enums\GrantStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Fund;
use App\Models\Grant;
use App\Models\User;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Fundraising\GrantPoster;
use App\Services\Fundraising\RecognizeDeferredContribution;
use Livewire\Livewire;

function grantCompany(array $overrides = []): Company
{
    $company = Company::factory()->create(array_merge([
        'address_country' => 'CA',
        'organization_type' => 'non_profit',
        'features_fundraising' => true,
        'fiscal_year_start_month' => 1,
    ], $overrides));

    app()->instance('current_company', $company);
    app(EnsureFundraisingAccounts::class)->handle($company);

    return $company;
}

beforeEach(function () {
    $this->company = grantCompany();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->grantRevenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Grant%')->first();
    $this->deferred = Account::query()->where('name', 'like', '%Deferred%')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function draftGrant(array $overrides = []): Grant
{
    return app(SaveGrant::class)->handle(array_merge([
        'name' => 'Operating Grant',
        'award_amount_cents' => 120000,
        'is_restricted' => true,
        'deposit_to_account_id' => test()->bank->id,
        'deferred_account_id' => test()->deferred->id,
        'revenue_account_id' => test()->grantRevenue->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ], $overrides));
}

test('posting a deferred grant award credits the deferred liability for the full award', function () {
    expect($this->company->usesDeferralMethod())->toBeTrue();

    $grant = draftGrant(['award_amount_cents' => 120000]);
    $entry = app(GrantPoster::class)->postAward($grant)->load('lines');

    $grant->refresh();
    expect($grant->status)->toBe(GrantStatus::Active);
    expect($grant->recognized_to_date_cents)->toBe(0);
    expect($grant->deferredBalanceCents())->toBe(120000);
    expect($entry->lines->firstWhere('account_id', $this->deferred->id)->credit_cents)->toBe(120000);
    expect($entry->lines->firstWhere('account_id', $this->bank->id)->debit_cents)->toBe(120000);
});

test('recognizing deferred grant revenue moves it from the liability to revenue', function () {
    $grant = draftGrant(['award_amount_cents' => 120000]);
    app(GrantPoster::class)->postAward($grant);

    app(RecognizeDeferredContribution::class)->recognize($grant->fresh(), 30000, '2026-04-01');

    $grant->refresh();
    expect($grant->recognized_to_date_cents)->toBe(30000);
    expect($grant->deferredBalanceCents())->toBe(90000);
    expect($grant->recognitions)->toHaveCount(1);
    expect((int) $this->grantRevenue->fresh()->recomputeBalance())->toBe(30000);
});

test('recognition cannot exceed the grant award', function () {
    $grant = draftGrant(['award_amount_cents' => 50000]);
    app(GrantPoster::class)->postAward($grant);

    app(RecognizeDeferredContribution::class)->recognize($grant->fresh(), 60000, '2026-04-01');
})->throws(RuntimeException::class);

test('full recognition marks the grant completed', function () {
    $grant = draftGrant(['award_amount_cents' => 40000]);
    app(GrantPoster::class)->postAward($grant);

    app(RecognizeDeferredContribution::class)->recognize($grant->fresh(), 40000, '2026-04-01');

    expect($grant->fresh()->status)->toBe(GrantStatus::Completed);
});

test('the straight-line amount splits the award across the period months', function () {
    $grant = draftGrant(['award_amount_cents' => 120000, 'period_start' => '2026-01-01', 'period_end' => '2026-12-31']);

    expect(app(RecognizeDeferredContribution::class)->straightLineAmountCents($grant))->toBe(10000);
});

test('the restricted-fund method recognizes the award immediately into the fund', function () {
    $company = grantCompany(['contribution_method' => ContributionMethod::RestrictedFund->value, 'features_funds' => true]);
    $company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $revenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Grant%')->first();
    $fund = Fund::create(['name' => 'Program Fund', 'fund_type' => 'restricted', 'is_active' => true]);

    $grant = app(SaveGrant::class)->handle([
        'name' => 'Program Grant',
        'award_amount_cents' => 80000,
        'is_restricted' => true,
        'fund_id' => $fund->id,
        'deposit_to_account_id' => $bank->id,
        'revenue_account_id' => $revenue->id,
    ]);

    $entry = app(GrantPoster::class)->postAward($grant)->load('lines');

    $grant->refresh();
    expect($grant->status)->toBe(GrantStatus::Completed);
    expect($grant->recognized_to_date_cents)->toBe(80000);
    $revenueLine = $entry->lines->firstWhere('account_id', $revenue->id);
    expect($revenueLine->credit_cents)->toBe(80000);
    expect($revenueLine->fund_id)->toBe($fund->id);
});

test('voiding a grant reverses the award and recognitions', function () {
    $grant = draftGrant(['award_amount_cents' => 50000]);
    app(GrantPoster::class)->postAward($grant);
    app(RecognizeDeferredContribution::class)->recognize($grant->fresh(), 20000, '2026-04-01');

    app(GrantPoster::class)->void($grant->fresh());

    expect($grant->fresh()->status)->toBe(GrantStatus::Void);
    expect((int) $this->grantRevenue->fresh()->recomputeBalance())->toBe(0);
    expect((int) $this->deferred->fresh()->recomputeBalance())->toBe(0);
});

test('the grant pages are gated on the fundraising feature flag', function () {
    Livewire::test('pages::grants.index', ['company' => $this->company])->assertOk();

    $off = Company::factory()->create(['features_fundraising' => false]);
    $off->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $off);

    Livewire::test('pages::grants.index', ['company' => $off])->assertStatus(403);
});

test('grants and grant_recognitions are registered for backup', function () {
    $tables = array_column(BackupTableRegistry::tables(), 'table');
    expect($tables)->toContain('grants')->toContain('grant_recognitions');
});
