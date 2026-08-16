<?php

use App\Actions\Payroll\FinalizeSlipFiling;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Payroll\UnlockSlipFiling;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\SlipType;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\PayrollSchedule;
use App\Models\PayrollSlipFiling;
use App\Models\PayrollSlipFilingLine;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\Rl1SlipCalculator;
use App\Services\Reporting\T4SlipCalculator;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Year-end slip finalize / lock lifecycle (T4, RL-1, T4A)
|--------------------------------------------------------------------------
|
| Slips are live-computed "drafts" until the company finalizes the year, which
| snapshots the calculator output into payroll_slip_filings(+lines) and
| publishes the slips to the employee portal. Unlocking deletes the snapshot,
| reverting to draft and pulling the slips off the portal.
*/

// NOTE: helper names are intentionally unique to this file — makeEmployee /
// postRunFor already exist globally in EmployeePortalTest.php and duplicate
// Pest helper names crash the full suite.

function slipFinalizationEmployee(string $name, string $province = 'AB'): Contact
{
    $contact = Contact::create([
        'display_name' => $name,
        'is_employee' => true,
        'is_active' => true,
    ]);

    EmployeePayrollProfile::factory()->create([
        'contact_id' => $contact->id,
        'province_of_employment' => $province,
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => test()->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => $province === 'QC' ? 1857100 : 2232300,
    ]);

    return $contact->refresh();
}

function slipFinalizationPostRun(Contact $employee, string $start, string $end, string $pay): PayRun
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => test()->schedule->id,
        'period_start_date' => $start,
        'period_end_date' => $end,
        'pay_date' => $pay,
        'lines' => [['contact_id' => $employee->id]],
    ]);

    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    return $run->fresh();
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();
    $this->employee = slipFinalizationEmployee('Fiona Finalize');
    $this->employee->forceFill(['email' => 'fiona@emp.test'])->save();

    slipFinalizationPostRun($this->employee, '2025-06-01', '2025-06-14', '2025-06-20');
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

// --- Finalize snapshots the calculator ------------------------------------

it('finalizes a T4 year into snapshot lines matching the live calculator', function () {
    $liveSlips = app(T4SlipCalculator::class)->slipsForYear($this->company, 2025);
    $liveSummary = app(T4SlipCalculator::class)->summary($this->company, 2025);

    $filing = app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);

    expect($filing->slip_type)->toBe(SlipType::T4)
        ->and($filing->year)->toBe(2025)
        ->and($filing->finalized_at)->not->toBeNull()
        ->and($filing->finalized_by_user_id)->toBe($this->user->id)
        // JSON columns may reorder keys (MySQL) — compare decoded arrays, never raw strings.
        // The snapshot adds slip_template render metadata on top of the calculator summary.
        ->and(collect($filing->summary)->except('slip_template')->all())->toEqual($liveSummary)
        ->and(data_get($filing->summary, 'slip_template.year'))->toBe(2025)
        ->and($filing->lines)->toHaveCount(count($liveSlips));

    $line = $filing->lines->firstWhere('contact_id', $this->employee->id);
    $liveSlip = collect($liveSlips)->firstWhere('contact_id', $this->employee->id);

    expect($line->data)->toEqual($liveSlip);
});

// --- Portal visibility follows finalization --------------------------------

it('hides T4 years on the portal dashboard until finalized, then shows them', function () {
    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.dashboard', ['company' => $this->company])
        ->assertSeeHtml('data-test="tax-slips-empty"')
        ->assertDontSeeHtml('data-test="t4-link"');

    // Finalize as the staff user (actingAs 'customer' switched the default guard).
    actingAs($this->user, 'web');
    app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);
    actingAs($this->employee, 'customer');

    Livewire::test('pages::employee-portal.dashboard', ['company' => $this->company])
        ->assertSeeHtml('data-test="t4-link"')
        ->assertSee('2025')
        ->assertDontSeeHtml('data-test="tax-slips-empty"');
});

// --- The snapshot is immutable --------------------------------------------

it('does not change finalized snapshot values when another run is posted later in the year', function () {
    $filing = app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);
    $frozenLine = $filing->lines->firstWhere('contact_id', $this->employee->id)->data;
    $frozenSummary = $filing->summary;

    // A second posted run in the same year doubles the live totals…
    slipFinalizationPostRun($this->employee, '2025-06-15', '2025-06-28', '2025-07-04');

    $liveSlip = collect(app(T4SlipCalculator::class)->slipsForYear($this->company, 2025))
        ->firstWhere('contact_id', $this->employee->id);
    expect($liveSlip['box14'])->toBeGreaterThan($frozenLine['box14']);

    // …but the snapshot — what the report page and portal serve — is unchanged.
    $filing->refresh()->load('lines');

    expect($filing->summary)->toEqual($frozenSummary)
        ->and($filing->lines->firstWhere('contact_id', $this->employee->id)->data)->toEqual($frozenLine);
});

// --- Unlock reverts to draft -----------------------------------------------

it('removes portal access again when the filing is unlocked', function () {
    $filing = app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);

    actingAs($this->employee, 'customer');

    $this->get(route('employee-portal.t4.pdf', ['company' => $this->company->slug, 'year' => 2025]))
        ->assertOk();

    app(UnlockSlipFiling::class)->handle($filing);

    expect(PayrollSlipFiling::query()->count())->toBe(0)
        ->and(PayrollSlipFilingLine::query()->count())->toBe(0);

    $this->get(route('employee-portal.t4.pdf', ['company' => $this->company->slug, 'year' => 2025]))
        ->assertNotFound();

    Livewire::test('pages::employee-portal.dashboard', ['company' => $this->company])
        ->assertSeeHtml('data-test="tax-slips-empty"')
        ->assertDontSeeHtml('data-test="t4-link"');
});

// --- Report page lifecycle ---------------------------------------------------

it('finalizes and unlocks from the T4 report page, switching the badge and snapshot', function () {
    Livewire::test('pages::payroll.reports.t4', ['company' => $this->company, 'year' => 2025])
        ->assertSeeHtml('data-test="t4-draft-badge"')
        ->call('finalize')
        ->assertSeeHtml('data-test="t4-finalized-badge"')
        ->assertSeeHtml('data-test="t4-finalized-note"')
        ->call('unlock')
        ->assertSeeHtml('data-test="t4-draft-badge"');

    expect(PayrollSlipFiling::query()->count())->toBe(0);
});

// --- Guard rails ------------------------------------------------------------

it('cannot finalize the same slip type and year twice', function () {
    app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);

    expect(fn () => app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025))
        ->toThrow(ValidationException::class);

    expect(PayrollSlipFiling::query()->count())->toBe(1);
});

it('throws when finalizing a year with zero slips', function () {
    expect(fn () => app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2099))
        ->toThrow(ValidationException::class);

    expect(PayrollSlipFiling::query()->count())->toBe(0);
});

// --- RL-1 lifecycle for a Quebec employee -----------------------------------

it('serves a Quebec employee their RL-1 PDF only after rl1 finalization', function () {
    $quebecer = slipFinalizationEmployee('Quentin Québec', 'QC');
    $quebecer->forceFill(['email' => 'quentin@emp.test'])->save();
    slipFinalizationPostRun($quebecer, '2025-03-01', '2025-03-14', '2025-03-20');

    actingAs($quebecer, 'customer');

    $this->get(route('employee-portal.rl1.pdf', ['company' => $this->company->slug, 'year' => 2025]))
        ->assertNotFound();

    // The staff user (web guard) finalizes; actingAs('customer') switched the
    // default guard, so be explicit about who is doing the finalizing.
    actingAs($this->user, 'web');
    $filing = app(FinalizeSlipFiling::class)->handle($this->company, SlipType::Rl1, 2025);
    actingAs($quebecer, 'customer');

    // The RL-1 filing only captures Quebec employees.
    expect($filing->lines->pluck('contact_id')->all())->toBe([$quebecer->id]);

    $liveSlip = collect(app(Rl1SlipCalculator::class)->slipsForYear($this->company, 2025))
        ->firstWhere('contact_id', $quebecer->id);
    expect($filing->lines->firstWhere('contact_id', $quebecer->id)->data)->toEqual($liveSlip);

    $this->get(route('employee-portal.rl1.pdf', ['company' => $this->company->slug, 'year' => 2025]))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');

    Livewire::test('pages::employee-portal.dashboard', ['company' => $this->company])
        ->assertSeeHtml('data-test="rl1-link"');
});
