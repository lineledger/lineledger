<?php

use App\Services\Proof\ProofValidator;
use App\Services\Proof\ScenarioBuilder;

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Test 1 — seed three fiscal years of trading and prove the books close cleanly
 * at every Dec 31: the trial balance balances, it ties to the balance sheet and
 * income statement, and the immutable audit chain verifies end to end.
 *
 * A reduced per-year volume keeps CI fast; the published artifacts (proof:generate)
 * use the full 500/year. The invariants hold at any volume.
 */
it('keeps three fiscal years of books balanced and audit-verified', function () {
    $scenario = app(ScenarioBuilder::class)->buildThreeYearScenario(perYear: 60);
    $result = app(ProofValidator::class)->validate($scenario);

    expect($result['checkpoints'])->toHaveCount(3);
    expect($result['audit']['passed'])->toBeTrue();
    expect($result['audit']['rows'])->toBeGreaterThan(0);

    foreach ($result['checkpoints'] as $checkpoint) {
        foreach ($checkpoint['checks'] as $check) {
            expect($check['passed'])->toBeTrue(
                "{$checkpoint['label']} — {$check['name']}: {$check['detail']}"
            );
        }
    }

    expect($result['passed'])->toBeTrue();
});
