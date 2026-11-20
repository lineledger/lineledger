<?php

namespace App\Services\Backup\Verification;

use App\Models\User;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Backup\BackupTableRegistry;
use App\Services\Restore\BundleInspector;
use App\Services\Restore\Exceptions\BundleValidationException;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Checks a backup bundle on its own, without writing anything: that it is
 * complete and undamaged (every table file present and matching its recorded
 * hash and row count, every attachment's file inside), that its rows hang
 * together (no reference to a row the bundle doesn't hold where the database
 * wouldn't allow one), that its books balance, and that its audit chain is
 * intact.
 */
final class BundleIntegrityChecker
{
    public function __construct(private readonly BundleInspector $inspector) {}

    public function check(BundleReader $bundle, VerificationReport $report): void
    {
        if (! $this->checkInspector($bundle, $report)) {
            return; // the inspector refused it; nothing further would mean much
        }

        $this->checkTableFiles($bundle, $report);
        $this->checkUsersFile($bundle, $report);
        $this->checkFiles($bundle, $report);
        $this->checkReferences($bundle, $report);
        $this->checkLedger($bundle, $report);
        $this->checkAuditChain($bundle, $report);
    }

    /**
     * The same acceptance a restore applies first: schema version, manifest
     * shape, encodings, the organization row.
     */
    private function checkInspector(BundleReader $bundle, VerificationReport $report): bool
    {
        try {
            // The inspector previews who each bundle user would match; nobody
            // is restoring here, so an unsaved user stands in.
            $preview = $this->inspector->inspect($bundle->zipPath, new User);
        } catch (BundleValidationException $e) {
            $report->fail('Bundle accepted for restore', $e->getMessage());

            return false;
        }

        $report->pass('Bundle accepted for restore', sprintf(
            '%s, schema %s, made by version %s',
            $preview['company']['name'],
            (string) ($bundle->manifest()['schema_version'] ?? '?'),
            $preview['bundle_app_version'],
        ));

        foreach ($preview['warnings'] as $warning) {
            $report->warn('Bundle accepted for restore', $warning);
        }

        return true;
    }

    private function checkTableFiles(BundleReader $bundle, VerificationReport $report): void
    {
        $problems = [];
        $tables = $bundle->manifestTables();

        foreach ($tables as $table => $meta) {
            if (! $bundle->hasTable($table)) {
                $problems[] = "data/{$table}.jsonl is missing";

                continue;
            }

            $sha = hash_file('sha256', $bundle->tablePath($table));
            if (isset($meta['sha256']) && ! hash_equals((string) $meta['sha256'], (string) $sha)) {
                $problems[] = "data/{$table}.jsonl does not match its recorded sha256";
            }

            $rows = 0;
            foreach ($bundle->rows($table) as $_row) {
                $rows++;
            }

            if (isset($meta['rows']) && (int) $meta['rows'] !== $rows) {
                $problems[] = sprintf('data/%s.jsonl holds %d rows, the manifest says %d', $table, $rows, (int) $meta['rows']);
            }
        }

        foreach ($bundle->tableFiles() as $table) {
            if (! array_key_exists($table, $tables)) {
                $problems[] = "data/{$table}.jsonl is not listed in the manifest";
            }
        }

        $unknown = array_diff(array_keys($tables), array_column(BackupTableRegistry::tables(), 'table'));

        if ($problems !== []) {
            $report->fail('Table files intact', implode('; ', $problems));
        } else {
            $report->pass('Table files intact', sprintf('%d tables, each matching its recorded hash and row count', count($tables)));
        }

        if ($unknown !== []) {
            $report->warn('Table files intact', 'Tables this version does not restore: '.implode(', ', $unknown));
        }
    }

    private function checkUsersFile(BundleReader $bundle, VerificationReport $report): void
    {
        $expected = $bundle->manifest()['users']['sha256'] ?? null;

        if (! is_string($expected) || $expected === '') {
            $report->warn('Users file intact', 'The manifest records no hash for users.json (an older backup).');

            return;
        }

        $path = $bundle->path('users.json');
        $actual = $path !== null && is_file($path) ? hash_file('sha256', $path) : false;

        if ($actual === false || ! hash_equals($expected, $actual)) {
            $report->fail('Users file intact', 'users.json is missing or does not match its recorded sha256.');

            return;
        }

        $report->pass('Users file intact', count($bundle->users()).' people referenced');
    }

    /**
     * Every attachment's file and the logos are inside the bundle. A file that
     * was already missing on the source when the backup ran (the manifest says
     * so) is a warning; one the bundle lost is a failure.
     */
    private function checkFiles(BundleReader $bundle, VerificationReport $report): void
    {
        $files = $bundle->manifest()['files'] ?? [];
        $declaredMissingIds = array_map('intval', (array) ($files['missing_ids'] ?? []));
        $declaredMissingCount = (int) ($files['missing_count'] ?? 0);

        $present = 0;
        $missingDeclared = [];
        $missingUndeclared = [];

        foreach ($bundle->rows('attachments') as $row) {
            $id = (int) ($row['id'] ?? 0);
            $path = $bundle->path((string) ($row['path'] ?? ''));

            if ($path !== null && str_starts_with((string) $row['path'], 'files/') && is_file($path)) {
                $present++;

                continue;
            }

            if (in_array($id, $declaredMissingIds, true)) {
                $missingDeclared[] = $id;
            } else {
                $missingUndeclared[] = $id;
            }
        }

        // An older bundle lists only how many were missing, not which.
        if ($declaredMissingIds === [] && $missingUndeclared !== [] && count($missingUndeclared) <= $declaredMissingCount) {
            $missingDeclared = $missingUndeclared;
            $missingUndeclared = [];
        }

        foreach ($bundle->rows('companies') as $company) {
            foreach (['logo_path', 'document_logo_path'] as $column) {
                $logo = $company[$column] ?? null;

                if (is_string($logo) && str_starts_with($logo, 'files/')) {
                    $path = $bundle->path($logo);

                    if ($path === null || ! is_file($path)) {
                        $missingUndeclared[] = $column;
                    }
                }
            }
        }

        if ($missingUndeclared !== []) {
            $report->fail('Attachment files present', 'Missing from the bundle: '.implode(', ', array_slice($missingUndeclared, 0, 20)));
        } else {
            $report->pass('Attachment files present', sprintf('%d attachment files', $present));
        }

        if ($missingDeclared !== []) {
            $report->warn('Attachment files present', sprintf(
                '%d attachment(s) had no file on the source when the backup ran (ids %s).',
                count($missingDeclared),
                implode(', ', array_slice($missingDeclared, 0, 20)),
            ));
        }
    }

    /**
     * Every reference in the bundle names a row the bundle holds, where the
     * database itself would insist on one (a foreign-key constraint). A soft
     * reference to a deleted record (an audit row's subject, a line's former
     * contact) is normal history and only counted.
     */
    private function checkReferences(BundleReader $bundle, VerificationReport $report): void
    {
        $ids = [];
        foreach (BackupTableRegistry::tables() as $entry) {
            foreach ($bundle->rows($entry['table']) as $row) {
                if (isset($row['id'])) {
                    $ids[$entry['table']][(int) $row['id']] = true;
                }
            }
        }

        $constrained = [];
        $hard = [];
        $soft = [];

        foreach (BackupTableRegistry::tables() as $entry) {
            $table = $entry['table'];

            if (! isset($constrained[$table])) {
                $constrained[$table] = [];

                foreach (Schema::getForeignKeys($table) as $fk) {
                    foreach ($fk['columns'] as $column) {
                        $constrained[$table][$column] = true;
                    }
                }
            }

            foreach ($bundle->rows($table) as $row) {
                foreach (References::of($table, $row) as $reference) {
                    if (isset($ids[$reference['table']][$reference['id']])) {
                        continue;
                    }

                    $key = $table.'.'.$reference['column'];

                    if (isset($constrained[$table][$reference['column']]) && BackupTableRegistry::modelFor($reference['table']) !== null) {
                        $hard[$key][] = $reference['id'];
                    } else {
                        $soft[$key] = ($soft[$key] ?? 0) + 1;
                    }
                }
            }
        }

        if ($hard !== []) {
            $report->fail('References resolve', implode('; ', array_map(
                static fn (string $key, array $missing): string => sprintf('%s names %d row(s) not in the bundle (e.g. #%s)', $key, count($missing), implode(', #', array_slice($missing, 0, 3))),
                array_keys($hard),
                $hard,
            )));
        } else {
            $report->pass('References resolve', 'every constrained reference names a row in the bundle');
        }

        if ($soft !== []) {
            $report->warn('References resolve', 'References to records deleted before the backup (restored as empty): '.implode(', ', array_map(
                static fn (string $key, int $count): string => "{$key} ×{$count}",
                array_keys($soft),
                $soft,
            )));
        }
    }

    /**
     * Posted journal lines balance, entry by entry and overall.
     */
    private function checkLedger(BundleReader $bundle, VerificationReport $report): void
    {
        $byEntry = [];
        $debits = 0;
        $credits = 0;

        foreach ($bundle->rows('journal_lines') as $line) {
            if (! ($line['is_posted'] ?? false)) {
                continue;
            }

            $debit = (int) ($line['debit_cents'] ?? 0);
            $credit = (int) ($line['credit_cents'] ?? 0);
            $entry = (int) ($line['journal_entry_id'] ?? 0);

            $byEntry[$entry] = ($byEntry[$entry] ?? 0) + $debit - $credit;
            $debits += $debit;
            $credits += $credit;
        }

        $unbalanced = array_keys(array_filter($byEntry, static fn (int $net): bool => $net !== 0));

        if ($debits !== $credits || $unbalanced !== []) {
            $report->fail('Books balance', sprintf(
                'Posted debits %d vs credits %d; unbalanced entries: %s',
                $debits,
                $credits,
                $unbalanced === [] ? 'none' : '#'.implode(', #', array_slice($unbalanced, 0, 10)),
            ));

            return;
        }

        $report->pass('Books balance', sprintf('%d posted entries, debits = credits = %s', count($byEntry), number_format($debits / 100, 2)));
    }

    /**
     * The audit chain links up exactly as it did on the source: contiguous
     * sequence numbers, each row's previous hash the row before's hash, and
     * each hash the digest of its recorded input.
     */
    private function checkAuditChain(BundleReader $bundle, VerificationReport $report): void
    {
        $rows = [];

        foreach ($bundle->rows('accounting_audit_logs') as $row) {
            $rows[(int) ($row['sequence'] ?? 0)] = $row;
        }

        if ($rows === []) {
            $report->pass('Audit chain intact', 'no audit rows');

            return;
        }

        ksort($rows);

        $expectedSequence = (int) array_key_first($rows);
        $previousHash = $expectedSequence === 1 ? AccountingAuditRecorder::GENESIS_HASH : null;
        $problems = [];

        foreach ($rows as $sequence => $row) {
            if ($sequence !== $expectedSequence) {
                $problems[] = "sequence jumps to {$sequence} after ".($expectedSequence - 1);
            }

            $expectedSequence = $sequence + 1;

            try {
                $digest = AccountingAuditRecorder::hashFromInput((string) $row['previous_hash'], (string) $row['hash_input']);
            } catch (Throwable) {
                $digest = null;
            }

            if ($previousHash !== null && $row['previous_hash'] !== $previousHash) {
                $problems[] = "#{$sequence} does not link to the row before it";
            }

            if ($digest !== $row['row_hash']) {
                $problems[] = "#{$sequence} does not match its recorded input";
            }

            $previousHash = (string) $row['row_hash'];

            if (count($problems) >= 10) {
                break;
            }
        }

        if ($problems !== []) {
            $report->fail('Audit chain intact', implode('; ', $problems));

            return;
        }

        $report->pass('Audit chain intact', count($rows).' linked rows');
    }
}
