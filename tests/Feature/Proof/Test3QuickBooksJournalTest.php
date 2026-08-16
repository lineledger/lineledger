<?php

use App\Services\Proof\ProofValidator;
use App\Services\Proof\ScenarioBuilder;

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Test 3 — replay a mocked QuickBooks Desktop "Journal" export (2023/2024/2025)
 * through the live full-history importer, then prove the books balance and tie to
 * the balance sheet / income statement at every year-end, every replayed account
 * ties back to the source totals, and the audit chain verifies.
 */
it('replays a 3-year QuickBooks journal and ties out to the source', function () {
    $scenario = app(ScenarioBuilder::class)->buildQuickBooksJournalScenario(perYear: 40);
    $result = app(ProofValidator::class)->validate($scenario);

    // Three year-end checkpoints + one source tie-out block.
    expect($result['checkpoints'])->toHaveCount(4);
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
