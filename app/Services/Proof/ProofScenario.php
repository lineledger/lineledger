<?php

namespace App\Services\Proof;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The output of {@see ScenarioBuilder}: a fully-seeded company plus the metadata
 * the validator and artifact writer need to check and report on it.
 *
 * `checkpoints` are the as-of dates at which the books are verified (the fiscal
 * year-ends for Test 1; the conversion date for Test 2). `importedRows` carries
 * the opening trial-balance source rows for Test 2 so they can be re-checked and
 * exported verbatim.
 */
final class ProofScenario
{
    /**
     * @param  list<array{label: string, as_of: CarbonImmutable}>  $checkpoints
     * @param  list<array{code: string, debit: int, credit: int}>  $importedRows
     * @param  array{label: string, as_of: CarbonImmutable, accounts: array<string, int>, transactions: int}|null  $tieOut
     *                                                                                                                      A source-of-truth tie-out: expected raw (debit−credit) net per account
     *                                                                                                                      code as of a date, plus the number of transactions that should post.
     * @param  array<string, string>  $extraSourceFiles  verbatim files (name => contents) to
     *                                                   include under source/ in the bundle — e.g. the literal QuickBooks import CSVs.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly Company $company,
        public readonly array $checkpoints,
        public readonly array $importedRows = [],
        public readonly ?array $tieOut = null,
        public readonly array $extraSourceFiles = [],
    ) {}
}
