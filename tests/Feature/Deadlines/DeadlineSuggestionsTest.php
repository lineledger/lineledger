<?php

use App\Actions\Deadlines\AddSuggestedDeadlines;
use App\Enums\Country;
use App\Enums\LegalStructure;
use App\Enums\OrganizationType;
use App\Enums\RemittanceFrequency;
use App\Models\Company;
use App\Models\Deadline;
use App\Services\Deadlines\DeadlineScheduleCalculator;
use App\Services\Deadlines\Suggestions\DeadlineSuggester;
use App\Services\Deadlines\Suggestions\SuggestedDeadline;
use App\Services\Deadlines\Suggestions\SuggestionChoices;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function suggestionKeys(Company $company, array $choices = []): array
{
    return array_map(fn (SuggestedDeadline $s): string => $s->key, DeadlineSuggester::for($company, SuggestionChoices::fromArray($choices)));
}

function bindDeadlineCompany(Company $company): Company
{
    app()->instance('current_company', $company);

    return $company;
}

it('suggests GST/HST, PST, payroll, WorkSafeBC, EHT and T2 deadlines for a BC corporation with payroll', function () {
    $company = bindDeadlineCompany(Company::factory()->forCountry(Country::Canada, 'BC')->create([
        'organization_type' => OrganizationType::Corporation->value,
        'fiscal_year_start_month' => 8,
        'tax_number' => '123456789RT0001',
        'features_payroll' => true,
        'payroll_remittance_frequency' => RemittanceFrequency::Monthly->value,
    ]));

    expect(suggestionKeys($company))->toContain(
        'ca.gst_hst.return', 'ca.bc.pst', 'ca.payroll.source_deductions', 'ca.payroll.t4',
        'ca.bc.worksafebc_quarterly', 'ca.bc.worksafebc_annual', 'ca.bc.eht_return', 'ca.bc.eht_instalments',
        'ca.t2.return', 'ca.t2.balance', 'ca.t2.instalments',
    )->not->toContain('ca.qc.qst', 'ca.t3010', 'ca.gst_hst.instalments');
});

it('anchors GST/HST quarters to the fiscal year and puts each due a month after it ends', function () {
    $company = bindDeadlineCompany(Company::factory()->forCountry(Country::Canada, 'BC')->create([
        'organization_type' => OrganizationType::Corporation->value,
        'fiscal_year_start_month' => 8,
        'tax_number' => '123456789RT0001',
    ]));

    $gst = collect(DeadlineSuggester::for($company, new SuggestionChoices))->firstWhere('key', 'ca.gst_hst.return');
    $periodStarts = collect(app(DeadlineScheduleCalculator::class)
        ->upcoming($gst->rule(), CarbonImmutable::parse('2026-01-01'), 4))
        ->map(fn ($o) => $o->periodStart->format('m-d').' → '.$o->dueDate->format('m-d'))
        ->all();

    expect($periodStarts)->toBe(['11-01 → 02-28', '02-01 → 05-31', '05-01 → 08-31', '08-01 → 11-30']);
});

it('offers annual-filer instalments only for annual GST/HST filers', function () {
    $company = bindDeadlineCompany(Company::factory()->create(['tax_number' => '123456789RT0001']));

    expect(suggestionKeys($company, ['gst_frequency' => 'annual']))->toContain('ca.gst_hst.instalments')
        ->and(suggestionKeys($company, ['gst_frequency' => 'quarterly']))->not->toContain('ca.gst_hst.instalments');
});

it('splits annual GST/HST for a sole proprietor with a December year end into June 15 and April 30', function () {
    $company = bindDeadlineCompany(Company::factory()->create([
        'organization_type' => OrganizationType::SoleProprietorship->value,
        'fiscal_year_start_month' => 1,
        'tax_number' => '123456789RT0001',
    ]));

    $suggestions = collect(DeadlineSuggester::for($company, SuggestionChoices::fromArray(['gst_frequency' => 'annual'])));
    $due = fn (string $key) => app(DeadlineScheduleCalculator::class)
        ->upcoming($suggestions->firstWhere('key', $key)->rule(), CarbonImmutable::parse('2027-01-01'), 1)[0]->dueDate->format('m-d');

    expect($due('ca.gst_hst.return'))->toBe('06-15')
        ->and($due('ca.gst_hst.payment'))->toBe('04-30')
        ->and($suggestions->pluck('key'))->toContain('ca.t1.return', 'ca.t1.balance');
});

it('suggests the T3010 for a registered charity', function () {
    $company = bindDeadlineCompany(Company::factory()->create([
        'organization_type' => OrganizationType::Charity->value,
        'legal_structure' => LegalStructure::RegisteredCharity->value,
    ]));

    expect(suggestionKeys($company))->toContain('ca.t3010')->not->toContain('ca.t2.return');
});

it('suggests QST and Revenu Québec deadlines in Quebec', function () {
    $company = bindDeadlineCompany(Company::factory()->forCountry(Country::Canada, 'QC')->create([
        'tax_number' => '123456789RT0001',
        'features_payroll' => true,
        'payroll_remittance_frequency' => RemittanceFrequency::Monthly->value,
    ]));

    expect(suggestionKeys($company))->toContain('ca.qc.qst', 'ca.qc.source_deductions', 'ca.qc.rl1');
});

it('suggests VAT, Corporation Tax and Companies House for a UK company', function () {
    $company = bindDeadlineCompany(Company::factory()->forCountry(Country::UnitedKingdom, 'ENG')->create([
        'organization_type' => OrganizationType::Corporation->value,
        'fiscal_year_start_month' => 4,
        'tax_number' => 'GB123456789',
    ]));

    $suggestions = collect(DeadlineSuggester::for($company, new SuggestionChoices));
    $vat = app(DeadlineScheduleCalculator::class)
        ->upcoming($suggestions->firstWhere('key', 'gb.vat')->rule(), CarbonImmutable::parse('2026-07-01'), 1)[0];

    expect($suggestions->pluck('key'))->toContain('gb.vat', 'gb.ct.payment', 'gb.ct.return', 'gb.companies_house.accounts')
        ->and($vat->periodEnd->toDateString())->toBe('2026-06-30')
        ->and($vat->dueDate->toDateString())->toBe('2026-08-07');
});

it('suggests Form 1120, estimates and 1099-NEC for a US C corporation', function () {
    $company = bindDeadlineCompany(Company::factory()->forCountry(Country::UnitedStates, 'NY')->create([
        'organization_type' => OrganizationType::Corporation->value,
    ]));

    expect(suggestionKeys($company))->toContain('us.1120', 'us.1120_estimates', 'us.1099_nec', 'us.941', 'us.state_sales_tax')
        ->and(suggestionKeys($company, ['us_corporation_type' => 's_corp']))->toContain('us.1120s')->not->toContain('us.1120');
});

it('adds the ticked suggestions once, with the assignee and track-from date', function () {
    $company = bindDeadlineCompany(Company::factory()->forCountry(Country::Canada, 'BC')->create([
        'organization_type' => OrganizationType::Corporation->value,
        'tax_number' => '123456789RT0001',
    ]));
    $owner = actAsCompanyOwner($company);
    $today = $company->currentDateTime()->toDateString();

    $created = app(AddSuggestedDeadlines::class)->handle($company, new SuggestionChoices, ['ca.gst_hst.return', 'ca.bc.pst'], $owner->id, $today);

    expect($created)->toHaveCount(2)
        ->and(Deadline::query()->pluck('template_key')->sort()->values()->all())->toBe(['ca.bc.pst', 'ca.gst_hst.return'])
        ->and(Deadline::query()->first()->assigned_user_id)->toBe($owner->id)
        ->and(Deadline::query()->first()->occurrences()->min('due_date'))->toBeGreaterThanOrEqual($today);

    // A second run skips what's already there.
    expect(fn () => app(AddSuggestedDeadlines::class)->handle($company, new SuggestionChoices, ['ca.gst_hst.return'], $owner->id, $today))
        ->toThrow(ValidationException::class);
});

it('renders the suggestions page and adds the default selection', function () {
    $company = bindDeadlineCompany(Company::factory()->forCountry(Country::Canada, 'BC')->create([
        'organization_type' => OrganizationType::Corporation->value,
        'tax_number' => '123456789RT0001',
    ]));
    actAsCompanyOwner($company);

    $this->get(route('deadlines.suggestions', ['company' => $company]))->assertOk()->assertSee(__('GST/HST return'));

    Livewire::test('pages::deadlines.suggestions', ['company' => $company])
        ->assertSet('selected', fn (array $selected) => in_array('ca.gst_hst.return', $selected, true) && ! in_array('ca.t2.instalments', $selected, true))
        ->call('add')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Deadline::query()->where('template_key', 'ca.gst_hst.return')->exists())->toBeTrue();
});
