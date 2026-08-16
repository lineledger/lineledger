<?php

use App\Actions\Fundraising\EnsureFundraisingAccounts;
use App\Actions\Fundraising\SaveDonation;
use App\Actions\Fundraising\SaveGrant;
use App\Actions\Membership\BillMemberDues;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Member;
use App\Models\MembershipLevel;
use App\Models\User;
use App\Services\Fundraising\DonationPoster;
use App\Services\Fundraising\FundraisingReportCalculator;
use App\Services\Fundraising\GrantPoster;
use App\Services\Fundraising\RecognizeDeferredContribution;
use App\Support\Reporting\ReportCatalog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create([
        'address_country' => 'CA',
        'organization_type' => 'non_profit',
        'features_fundraising' => true,
        'features_membership' => true,
        'fiscal_year_start_month' => 1,
    ]);
    app()->instance('current_company', $this->company);
    app(EnsureFundraisingAccounts::class)->handle($this->company);

    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->donationRevenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Donation%')->first();
    $this->grantRevenue = Account::query()->where('type', AccountType::Income->value)->where('name', 'like', '%Grant%')->first();
    $this->deferred = Account::query()->where('name', 'like', '%Deferred%')->first();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function postDonation(int $cents, ?int $contactId): void
{
    $donation = app(SaveDonation::class)->handle([
        'contact_id' => $contactId,
        'donation_date' => '2026-03-01',
        'amount_cents' => $cents,
        'deposit_to_account_id' => test()->bank->id,
        'revenue_account_id' => test()->donationRevenue->id,
    ]);
    app(DonationPoster::class)->post($donation);
}

test('donations-by-donor totals group and sum posted donations', function () {
    $alice = Contact::factory()->create(['display_name' => 'Alice', 'is_donor' => true]);
    $bob = Contact::factory()->create(['display_name' => 'Bob', 'is_donor' => true]);

    postDonation(10000, $alice->id);
    postDonation(5000, $alice->id);
    postDonation(20000, $bob->id);

    $rows = app(FundraisingReportCalculator::class)->donationsByDonor(
        $this->company,
        CarbonImmutable::create(2026, 1, 1),
        CarbonImmutable::create(2026, 12, 31),
    );

    expect($rows->firstWhere('donor', 'Bob')['total_cents'])->toBe(20000);
    expect($rows->firstWhere('donor', 'Alice')['total_cents'])->toBe(15000);
    expect($rows->firstWhere('donor', 'Alice')['count'])->toBe(2);
});

test('grants-summary reports award, recognized, and deferred balance', function () {
    $grant = app(SaveGrant::class)->handle([
        'name' => 'Operating Grant',
        'award_amount_cents' => 100000,
        'is_restricted' => true,
        'deposit_to_account_id' => $this->bank->id,
        'deferred_account_id' => $this->deferred->id,
        'revenue_account_id' => $this->grantRevenue->id,
        'period_start' => '2026-01-01',
        'period_end' => '2026-12-31',
    ]);
    app(GrantPoster::class)->postAward($grant);
    app(RecognizeDeferredContribution::class)->recognize($grant->fresh(), 30000, '2026-04-01');

    $rows = app(FundraisingReportCalculator::class)->grantsSummary($this->company);
    $row = $rows->firstWhere('grant_no', $grant->grant_no);

    expect($row['award_cents'])->toBe(100000);
    expect($row['recognized_cents'])->toBe(30000);
    expect($row['deferred_cents'])->toBe(70000);
});

test('the report catalog surfaces membership and fundraising reports when enabled', function () {
    $keys = array_keys(ReportCatalog::flatten($this->company, $this->user));

    expect($keys)
        ->toContain('reports.membership-roster')
        ->toContain('reports.membership-revenue-by-level')
        ->toContain('reports.donations-by-donor')
        ->toContain('reports.grants-summary');
});

test('the report catalog hides the reports when the features are off', function () {
    $plain = Company::factory()->create(['features_fundraising' => false, 'features_membership' => false]);
    $plain->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $keys = array_keys(ReportCatalog::flatten($plain, $this->user));

    expect($keys)
        ->not->toContain('reports.membership-roster')
        ->not->toContain('reports.donations-by-donor')
        ->not->toContain('reports.grants-summary');
});

test('the membership and fundraising report pages render when enabled and 403 when off', function () {
    Livewire::test('pages::reports.membership-roster', ['company' => $this->company])->assertOk();
    Livewire::test('pages::reports.donations-by-donor', ['company' => $this->company])->assertOk();
    Livewire::test('pages::reports.grants-summary', ['company' => $this->company])->assertOk();

    $plain = Company::factory()->create(['features_fundraising' => false, 'features_membership' => false]);
    $plain->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $plain);

    Livewire::test('pages::reports.membership-roster', ['company' => $plain])->assertStatus(403);
    Livewire::test('pages::reports.donations-by-donor', ['company' => $plain])->assertStatus(403);
});

test('the membership roster shows open dues and links to each member', function () {
    $income = Account::query()->where('type', AccountType::Income->value)->orderBy('code')->first();
    $level = MembershipLevel::factory()->create([
        'company_id' => $this->company->id,
        'default_dues_cents' => 10000,
        'revenue_account_id' => $income->id,
    ]);
    $contact = Contact::factory()->create(['display_name' => 'Roster Person']);
    $member = Member::factory()->create([
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'membership_level_id' => $level->id,
        'member_no' => 'MEM-000001',
    ]);

    // A posted, unpaid dues invoice gives the member an open balance.
    app(BillMemberDues::class)->handle($member, ['post' => true]);

    Livewire::test('pages::reports.membership-roster', ['company' => $this->company])
        ->assertOk()
        ->assertSee('Roster Person')
        ->assertSeeHtml(route('members.show', ['company' => $this->company, 'member' => $member]))
        ->assertSeeText('100.00');
});
