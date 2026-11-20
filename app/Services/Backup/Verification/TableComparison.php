<?php

namespace App\Services\Backup\Verification;

/**
 * The tally of comparing one table of a bundle with the database, built up as
 * {@see RowComparator} goes.
 */
final class TableComparison
{
    public int $bundleRows = 0;

    public int $restoredRows = 0;

    public int $skipped = 0;

    public int $extra = 0;

    public int $missing = 0;

    public int $mismatched = 0;

    /** @var list<string> */
    public array $samples = [];

    /**
     * Keep `$line` as an example, up to {@see VerificationReport::SAMPLES_PER_TABLE}.
     */
    public function sample(string $line): void
    {
        if (count($this->samples) < VerificationReport::SAMPLES_PER_TABLE) {
            $this->samples[] = $line;
        }
    }

    /**
     * @return array{bundle_rows: int, restored_rows: int, skipped: int, extra: int, missing: int, mismatched: int, samples: list<string>}
     */
    public function toArray(): array
    {
        return [
            'bundle_rows' => $this->bundleRows,
            'restored_rows' => $this->restoredRows,
            'skipped' => $this->skipped,
            'extra' => $this->extra,
            'missing' => $this->missing,
            'mismatched' => $this->mismatched,
            'samples' => $this->samples,
        ];
    }
}
