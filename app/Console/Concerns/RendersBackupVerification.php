<?php

namespace App\Console\Concerns;

use App\Services\Backup\Verification\VerificationReport;

/**
 * Prints a {@see VerificationReport} the same way from every backup command:
 * one line per check, then every table that didn't come back as the backup
 * has it, with a few example rows.
 */
trait RendersBackupVerification
{
    protected function renderVerification(VerificationReport $report): void
    {
        foreach ($report->checks() as $check) {
            $line = sprintf('  %s %s%s', match ($check['status']) {
                VerificationReport::PASS => '✓',
                VerificationReport::WARN => '!',
                default => '✗',
            }, $check['name'], $check['detail'] === '' ? '' : ' — '.$check['detail']);

            match ($check['status']) {
                VerificationReport::PASS => $this->line($line),
                VerificationReport::WARN => $this->warn($line),
                default => $this->error($line),
            };
        }

        foreach ($report->tables() as $table => $result) {
            if (! VerificationReport::tableFailed($result)) {
                continue;
            }

            $this->error(sprintf(
                '    %s: %d in the backup, %d restored, %d missing, %d different, %d unexpected',
                $table,
                $result['bundle_rows'],
                $result['restored_rows'],
                $result['missing'],
                $result['mismatched'],
                $result['extra'],
            ));

            foreach ($result['samples'] as $sample) {
                $this->line('      '.$sample);
            }
        }

        if ($report->passed()) {
            $this->info(sprintf('  %s verified.', $report->bundle));
        } else {
            $this->error(sprintf('  %s FAILED verification.', $report->bundle));
        }
    }
}
