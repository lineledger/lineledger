<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Payroll\Calculators\CppCalculator;
use App\Services\Payroll\Calculators\EiCalculator;
use App\Services\Payroll\Calculators\IncomeTaxCalculator;
use App\Services\Payroll\Calculators\QpipCalculator;
use App\Services\Payroll\PayrollDeductionEngine;
use App\Services\Payroll\Verification\PayrollVerificationRunner;
use App\Support\Payroll\Constants\PayrollConstantsRepository;

function verificationRunner(): PayrollVerificationRunner
{
    return new PayrollVerificationRunner(new PayrollDeductionEngine(
        new PayrollConstantsRepository,
        new CppCalculator,
        new EiCalculator,
        new IncomeTaxCalculator,
        new QpipCalculator,
    ));
}

it('matches every loaded payroll reference value to the exact cent', function () {
    $report = verificationRunner()->run();

    // No reference (CPP/EI/tax) may disagree with the engine.
    expect($report['summary']['failed'])->toBe(0)
        ->and($report['summary']['passed'])->toBeTrue();

    // Surface any mismatch with a readable message.
    foreach ($report['checks'] as $check) {
        foreach ($check['components'] as $component) {
            if ($component['expected'] !== null) {
                expect($component['actual'])->toBe(
                    $component['expected'],
                    "{$check['label']} — {$component['label']}: expected {$component['expected']}, got {$component['actual']}",
                );
            }
        }
    }
});

it('has at least one verified reference per statutory component', function () {
    $report = verificationRunner()->run();

    $covered = [];
    foreach ($report['checks'] as $check) {
        foreach ($check['components'] as $key => $component) {
            if ($component['status'] === 'match') {
                $covered[$key] = true;
            }
        }
    }

    // Every statutory component has at least one exact-cent reference locked.
    // (Quebec income tax is intentionally excluded — it stays awaiting WebRAS.)
    foreach (['cpp', 'cpp2', 'ei', 'federal_tax', 'provincial_tax', 'qpp', 'qpip'] as $component) {
        expect($covered[$component] ?? false)->toBeTrue("no verified reference for {$component}");
    }
});

it('renders the payroll verification page for a payroll company and 404s otherwise', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['address_country' => 'CA', 'features_payroll' => true]);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    $this->get(route('payroll.reports.verification', $company))
        ->assertOk()
        ->assertSee('Payroll calculation verification')
        ->assertSee('Alberta');

    $company->update(['features_payroll' => false]);
    $this->get(route('payroll.reports.verification', $company))->assertNotFound();
});

it('passes the payroll:verify-calculations command', function () {
    $this->artisan('payroll:verify-calculations')->assertSuccessful();
});
