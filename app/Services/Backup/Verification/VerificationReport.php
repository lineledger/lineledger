<?php

namespace App\Services\Backup\Verification;

/**
 * The outcome of verifying one backup: a list of named checks (pass, warn or
 * fail) and, when a trial restore ran, how every table compared.
 *
 * Warnings never fail a verification: they describe the source (an attachment
 * that was already missing when the backup was taken), not the backup.
 */
final class VerificationReport
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    /** How many mismatching rows a table keeps as examples. */
    public const SAMPLES_PER_TABLE = 5;

    /** @var list<array{name: string, status: string, detail: string}> */
    private array $checks = [];

    /**
     * @var array<string, array{bundle_rows: int, restored_rows: int, skipped: int, extra: int, missing: int, mismatched: int, samples: list<string>}>
     */
    private array $tables = [];

    public function __construct(public readonly string $bundle) {}

    public function pass(string $name, string $detail = ''): void
    {
        $this->checks[] = ['name' => $name, 'status' => self::PASS, 'detail' => $detail];
    }

    public function warn(string $name, string $detail): void
    {
        $this->checks[] = ['name' => $name, 'status' => self::WARN, 'detail' => $detail];
    }

    public function fail(string $name, string $detail): void
    {
        $this->checks[] = ['name' => $name, 'status' => self::FAIL, 'detail' => $detail];
    }

    /**
     * Record how one table compared.
     *
     * @param  array{bundle_rows: int, restored_rows: int, skipped: int, extra: int, missing: int, mismatched: int, samples: list<string>}  $result
     */
    public function table(string $table, array $result): void
    {
        $this->tables[$table] = $result;
    }

    public function passed(): bool
    {
        return $this->failures() === [];
    }

    /**
     * One line per failed check or table.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $failures = [];

        foreach ($this->checks as $check) {
            if ($check['status'] === self::FAIL) {
                $failures[] = $check['name'].': '.$check['detail'];
            }
        }

        foreach ($this->tables as $table => $result) {
            if (self::tableFailed($result)) {
                $failures[] = sprintf(
                    '%s: %d missing, %d mismatched, %d unexpected. %s',
                    $table,
                    $result['missing'],
                    $result['mismatched'],
                    $result['extra'],
                    implode(' | ', $result['samples']),
                );
            }
        }

        return $failures;
    }

    /**
     * @return list<array{name: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * @return array<string, array{bundle_rows: int, restored_rows: int, skipped: int, extra: int, missing: int, mismatched: int, samples: list<string>}>
     */
    public function tables(): array
    {
        return $this->tables;
    }

    /**
     * @param  array{bundle_rows: int, restored_rows: int, skipped: int, extra: int, missing: int, mismatched: int, samples: list<string>}  $result
     */
    public static function tableFailed(array $result): bool
    {
        return $result['missing'] > 0 || $result['mismatched'] > 0 || $result['extra'] > 0;
    }

    /**
     * @return array{bundle: string, passed: bool, checks: list<array{name: string, status: string, detail: string}>, tables: array<string, mixed>, failures: list<string>}
     */
    public function toArray(): array
    {
        return [
            'bundle' => $this->bundle,
            'passed' => $this->passed(),
            'checks' => $this->checks,
            'tables' => $this->tables,
            'failures' => $this->failures(),
        ];
    }
}
