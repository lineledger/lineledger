<?php

namespace App\Services\Backup\Verification;

use App\Services\Restore\Exceptions\BundleValidationException;
use App\Services\Restore\RestoreTrace;
use Throwable;

/**
 * Proves a backup can be restored, and restores completely.
 *
 *  1. The bundle on its own ({@see BundleIntegrityChecker}): complete,
 *     undamaged, consistent, balanced, its audit chain intact. Writes nothing.
 *  2. A trial restore ({@see TrialRestore}): the bundle restored for real
 *     inside a transaction that is rolled back, then every row compared with
 *     the bundle ({@see RowComparator}) and the copy's books and files checked
 *     ({@see LedgerChecks}).
 */
final class BackupVerifier
{
    public function __construct(
        private readonly BundleIntegrityChecker $integrity,
        private readonly TrialRestore $trial,
        private readonly RowComparator $rows,
        private readonly LedgerChecks $ledger,
    ) {}

    /**
     * Verify the bundle at `$zipPath`. With `$trialRestore` false only step 1
     * runs, and nothing touches the database.
     */
    public function verify(string $zipPath, bool $trialRestore = true): VerificationReport
    {
        $report = new VerificationReport(basename($zipPath));

        try {
            $bundle = BundleReader::open($zipPath);
        } catch (BundleValidationException $e) {
            $report->fail('Bundle readable', $e->getMessage());

            return $report;
        }

        try {
            $this->integrity->check($bundle, $report);

            if (! $trialRestore) {
                return $report;
            }

            if (! $report->passed()) {
                $report->fail('Trial restore', 'Not attempted: the backup failed the checks above.');

                return $report;
            }

            try {
                $this->trial->run($zipPath, function (RestoreTrace $trace) use ($bundle, $report): void {
                    $this->inspectRestore($bundle, $trace, $report);
                });
            } catch (Throwable $e) {
                $report->fail('Trial restore', $e->getMessage());
            }
        } finally {
            $bundle->close();
        }

        return $report;
    }

    /**
     * Check an organization a restore has already made from `$zipPath`
     * against it (`backup:import --verify`). Its trace must come from that
     * restore.
     */
    public function verifyRestored(string $zipPath, RestoreTrace $trace): VerificationReport
    {
        $report = new VerificationReport(basename($zipPath));

        try {
            $bundle = BundleReader::open($zipPath);
        } catch (BundleValidationException $e) {
            $report->fail('Bundle readable', $e->getMessage());

            return $report;
        }

        try {
            $this->inspectRestore($bundle, $trace, $report);
        } finally {
            $bundle->close();
        }

        return $report;
    }

    private function inspectRestore(BundleReader $bundle, RestoreTrace $trace, VerificationReport $report): void
    {
        $restored = array_sum(array_map('count', $trace->ids->all()));

        $report->pass('Restore completed', sprintf(
            '%d rows restored%s',
            $restored,
            $trace->orphans === [] ? '' : sprintf(', %d reference(s) to deleted records left empty', count($trace->orphans)),
        ));

        $this->rows->compareRestore($bundle, $trace, $report);

        $tablesFailed = array_filter($report->tables(), VerificationReport::tableFailed(...));

        if ($tablesFailed === []) {
            $report->pass('Every row restored', sprintf('%d tables match the backup column for column', count($report->tables())));
        } else {
            $report->fail('Every row restored', count($tablesFailed).' table(s) differ: '.implode(', ', array_keys($tablesFailed)));
        }

        $this->ledger->check($bundle, $trace, $report);
    }
}
