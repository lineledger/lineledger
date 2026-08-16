<?php

use App\Actions\Payroll\FinalizeSlipFiling;
use App\Actions\Payroll\SavePayRun;
use App\Enums\CompanyRole;
use App\Enums\PayBasis;
use App\Enums\SlipType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Models\Membership;
use App\Models\PayrollSchedule;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Pdf\Slips\SlipFieldMaps;
use App\Services\Pdf\Slips\SlipTemplateRegistry;
use App\Services\Pdf\Slips\T4SlipPdfAdapter;
use App\Services\Posting\PayRunPoster;
use App\Services\Reporting\PdfExporter;
use Smalot\PdfParser\Parser;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| Official CRA slip templates (T4 onto the real government form)
|--------------------------------------------------------------------------
|
| The app ships flattened copies of the official CRA fillable T4 for recent
| years; T4SlipPdfAdapter stamps slip values onto the form's two employee
| copies via FPDI. A year with no template/map falls back to the labelled
| facsimile — never a wrong-year official-looking form.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['name' => 'Maple Works Ltd.', 'address_country' => 'CA', 'features_payroll' => true]);
    Membership::create(['company_id' => $this->company->id, 'user_id' => $this->user->id, 'role' => CompanyRole::Owner]);
    actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->schedule = PayrollSchedule::factory()->create();

    $this->employee = Contact::create([
        'display_name' => 'Terry Forms',
        'is_employee' => true,
        'is_active' => true,
        'billing_line1' => '45 Pine Cres',
        'billing_city' => 'Calgary',
        'billing_region' => 'AB',
        'billing_postal_code' => 'T2P 1J9',
    ]);
    $this->profile = EmployeePayrollProfile::factory()->create([
        'contact_id' => $this->employee->id,
        'province_of_employment' => 'AB',
        'pay_basis' => PayBasis::Salary->value,
        'annual_salary_cents' => 6000000,
        'payroll_schedule_id' => $this->schedule->id,
        'td1_federal_claim_cents' => 1612900,
        'td1_provincial_claim_cents' => 2232300,
        'is_active' => true,
    ]);
    $this->profile->setSin('046454286');
    $this->profile->save();
});

afterEach(fn () => app()->forgetInstance('current_company'));

/** A hand-built slip array in the calculator's shape. */
function ostSlip(): array
{
    return [
        'contact_id' => test()->employee->id,
        'name' => 'Terry Forms',
        'sin_last4' => '4286',
        'province' => 'AB',
        'box14' => 6000000, 'box16' => 350000, 'box16a' => 18800, 'box17' => 0,
        'box18' => 105000, 'box22' => 920000, 'box24' => 6000000, 'box26' => 6000000,
        'box55' => 0, 'box56' => 0,
        'other' => ['40' => 48000, '34' => 12000],
    ];
}

it('ships verified official T4 templates for 2024 and 2025', function () {
    $registry = app(SlipTemplateRegistry::class);

    foreach ([2024, 2025] as $year) {
        expect($registry->installed(SlipTemplateRegistry::T4, $year))->toBeTrue()
            ->and(SlipFieldMaps::for(SlipTemplateRegistry::T4, $year))->not->toBeNull();
    }
});

it('renders the T4 onto the official form: values, full SIN, and both employee copies', function () {
    $bytes = app(T4SlipPdfAdapter::class)->render($this->company, ostSlip(), 2025);

    expect($bytes)->not->toBeNull();

    $text = (new Parser)->parseContent($bytes)->getText();

    // The official artwork is present…
    expect(substr_count($text, 'Employment income'))->toBeGreaterThanOrEqual(2);

    // …and every value lands once per employee copy (2 impressions).
    foreach (['60,000.00' => 6, '9,200.00' => 2, '046 454 286' => 2, 'Forms' => 2, '480.00' => 2, 'Maple Works Ltd.' => 2] as $needle => $atLeast) {
        expect(substr_count($text, (string) $needle))->toBeGreaterThanOrEqual($atLeast);
    }

    // Box 40 has no dedicated field → Other Information slot; box 44 does.
    expect(substr_count($text, '120.00'))->toBeGreaterThanOrEqual(2);
});

it('returns null (facsimile fallback) for a year with no template', function () {
    expect(app(T4SlipPdfAdapter::class)->render($this->company, ostSlip(), 2020))->toBeNull();
});

it('records whether the official template applies in the finalized snapshot, and the portal serves the official bytes', function () {
    // One posted 2025 pay run so the calculator produces a slip.
    $bank = Account::query()->where('code', '1000')->firstOrFail();
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $this->schedule->id,
        'period_start_date' => '2025-06-01',
        'period_end_date' => '2025-06-14',
        'pay_date' => '2025-06-20',
        'bank_account_id' => $bank->id,
        'lines' => [['contact_id' => $this->employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run->fresh());
    app(PayRunPoster::class)->post($run->fresh());

    $filing = app(FinalizeSlipFiling::class)->handle($this->company, SlipType::T4, 2025);

    expect(data_get($filing->summary, 'slip_template.official'))->toBeTrue()
        ->and(data_get($filing->summary, 'slip_template.year'))->toBe(2025);

    // The portal serves the slip rendered on the official template.
    actingAs($this->employee, 'customer');

    $response = $this->get(route('employee-portal.t4.pdf', ['company' => $this->company->slug, 'year' => 2025]));
    $response->assertOk();

    $text = (new Parser)->parseFile($response->getFile()->getPathname())->getText();
    expect(substr_count($text, 'Employment income'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($text, '046 454 286'))->toBe(2);
});

it('labels the facsimile fallback so it can never pass as the official form', function () {
    $bytes = app(PdfExporter::class)->raw('pdf.reports.t4-slip', [
        'company' => $this->company,
        'slip' => ostSlip(),
        'year' => 2020,
        'facsimile' => true,
    ]);

    expect((new Parser)->parseContent($bytes)->getText())->toContain('FACSIMILE');
});
