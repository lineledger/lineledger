<?php

use App\Services\Proof\ProofValidator;
use App\Services\Proof\ScenarioBuilder;

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Test 2 — create a brand-new company the setup-wizard way and bring it live with
 * an imported opening trial balance. Prove the resulting trial balance, balance
 * sheet, and income statement all tie back to the imported source figures, and
 * that the audit chain verifies.
 */
it('ties the resulting reports back to the imported trial balance', function () {
    $scenario = app(ScenarioBuilder::class)->buildImportedTrialBalanceScenario();
    $result = app(ProofValidator::class)->validate($scenario);

    expect($result['checkpoints'])->toHaveCount(1);
    expect($result['audit']['passed'])->toBeTrue();

    // The imported rows produce at least the per-account tie-out checks.
    expect(count($result['checkpoints'][0]['checks']))->toBeGreaterThanOrEqual(6);

    foreach ($result['checkpoints'] as $checkpoint) {
        foreach ($checkpoint['checks'] as $check) {
            expect($check['passed'])->toBeTrue(
                "{$checkpoint['label']} — {$check['name']}: {$check['detail']}"
            );
        }
    }

    expect($result['passed'])->toBeTrue();
});
