<?php

namespace App\Services\Backup\Verification;

use App\Models\Company;
use App\Services\Restore\RestoreTrace;
use App\Support\Storage\StorageDisks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Checks on a restored organization's books and files, against the bundle it
 * came from: the same totals on every account, a ledger that passes the
 * nightly integrity check, and attachments byte-for-byte the files the bundle
 * holds.
 */
final class LedgerChecks
{
    public function check(BundleReader $bundle, RestoreTrace $trace, VerificationReport $report): void
    {
        $this->checkAccountTotals($bundle, $trace, $report);
        $this->checkIntegrity($trace, $report);
        $this->checkFiles($bundle, $trace, $report);
    }

    /**
     * Every account's posted debits and credits on the copy equal the bundle's.
     */
    private function checkAccountTotals(BundleReader $bundle, RestoreTrace $trace, VerificationReport $report): void
    {
        $expected = [];

        foreach ($bundle->rows('journal_lines') as $line) {
            if (! ($line['is_posted'] ?? false)) {
                continue;
            }

            $account = $trace->ids->get('accounts', (int) $line['account_id']) ?? 0;
            $expected[$account]['debit'] = ($expected[$account]['debit'] ?? 0) + (int) $line['debit_cents'];
            $expected[$account]['credit'] = ($expected[$account]['credit'] ?? 0) + (int) $line['credit_cents'];
        }

        $actual = [];

        DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $trace->companyId)
            ->where('journal_lines.is_posted', true)
            ->groupBy('journal_lines.account_id')
            ->select('journal_lines.account_id')
            ->selectRaw('SUM(journal_lines.debit_cents) AS debit, SUM(journal_lines.credit_cents) AS credit')
            ->get()
            ->each(function (object $row) use (&$actual): void {
                $actual[(int) $row->account_id] = ['debit' => (int) $row->debit, 'credit' => (int) $row->credit];
            });

        $differences = [];

        foreach ($expected + $actual as $account => $_totals) {
            $want = $expected[$account] ?? ['debit' => 0, 'credit' => 0];
            $have = $actual[$account] ?? ['debit' => 0, 'credit' => 0];

            if ($want != $have) {
                $differences[] = sprintf(
                    'account #%d: expected %d/%d, got %d/%d',
                    $account,
                    $want['debit'],
                    $want['credit'],
                    $have['debit'],
                    $have['credit'],
                );
            }
        }

        if ($differences !== []) {
            $report->fail('Account totals match', implode('; ', array_slice($differences, 0, 10)));

            return;
        }

        $report->pass('Account totals match', count($expected).' accounts with posted activity');
    }

    /**
     * The nightly ledger integrity check passes on the copy: the audit chain
     * re-hashed over its ids, double entry, balance caches, paid caches.
     */
    private function checkIntegrity(RestoreTrace $trace, VerificationReport $report): void
    {
        try {
            // Run the registered command with an output of its own, not through
            // Artisan::call(): that would replace Artisan's "last output", which
            // a caller running backup:verify through Artisan::call() reads back.
            $buffer = new BufferedOutput;
            $exitCode = Artisan::all()['integrity:check']->run(new ArrayInput([
                'command' => 'integrity:check',
                'company' => (string) $trace->companyId,
                '--no-alert' => true,
                '--no-log' => true,
            ]), $buffer);
            $output = trim($buffer->fetch());
        } catch (Throwable $e) {
            $report->fail('Ledger integrity', $e->getMessage());

            return;
        }

        if ($exitCode !== 0) {
            $issues = array_values(array_filter(
                array_map('trim', explode("\n", $output)),
                static fn (string $line): bool => str_starts_with($line, '- '),
            ));

            $report->fail('Ledger integrity', $issues === [] ? $output : implode(' ', $issues));

            return;
        }

        $report->pass('Ledger integrity', 'audit chain, double entry and balance caches check out');
    }

    /**
     * Every restored attachment, and the logos, hold exactly the bytes of the
     * file the bundle carried.
     */
    private function checkFiles(BundleReader $bundle, RestoreTrace $trace, VerificationReport $report): void
    {
        $checked = 0;
        $problems = [];

        foreach ($bundle->rows('attachments') as $row) {
            $source = $bundle->path((string) ($row['path'] ?? ''));

            if ($source === null || ! is_file($source)) {
                continue; // reported by the bundle check
            }

            $newId = $trace->ids->get('attachments', (int) $row['id']);

            if ($newId === null) {
                continue; // left out on purpose; the row comparison says so
            }

            $restored = DB::table('attachments')->where('id', $newId)->first(['disk', 'path']);

            if ($restored === null || ! $this->sameFile($source, (string) $restored->disk, (string) $restored->path)) {
                $problems[] = 'attachment #'.$row['id'];
            }

            $checked++;
        }

        $company = Company::query()->withoutGlobalScopes()->find($trace->companyId);

        foreach ($bundle->rows('companies') as $row) {
            foreach (['logo_path', 'document_logo_path'] as $column) {
                $source = is_string($row[$column] ?? null) ? $bundle->path($row[$column]) : null;

                if ($source === null || ! is_file($source)) {
                    continue;
                }

                $restoredPath = $company?->getAttribute($column);

                if (! is_string($restoredPath) || ! $this->sameFile($source, StorageDisks::logos(), $restoredPath)) {
                    $problems[] = $column;
                } elseif (Company::query()->withoutGlobalScopes()->withTrashed()->whereKeyNot($trace->companyId)
                    ->where(fn ($q) => $q->where('logo_path', $restoredPath)->orWhere('document_logo_path', $restoredPath))
                    ->exists()) {
                    // The same file as another organization's: removing either logo deletes both.
                    $problems[] = $column.' (shares its file with another organization)';
                }

                $checked++;
            }
        }

        if ($problems !== []) {
            $report->fail('Files restored intact', 'Differ from the backup: '.implode(', ', array_slice($problems, 0, 20)));

            return;
        }

        $report->pass('Files restored intact', $checked.' files byte-identical');
    }

    private function sameFile(string $source, string $disk, string $path): bool
    {
        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (Throwable) {
            return false;
        }

        if (! is_resource($stream)) {
            return false;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_equals((string) hash_file('sha256', $source), hash_final($context));
    }
}
