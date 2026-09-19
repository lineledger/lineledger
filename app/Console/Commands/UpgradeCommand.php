<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Models\Company;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;

/**
 * The one command an operator runs after pulling a release.
 *
 * It runs every post-release step in order — migrations, then each data
 * backfill a release needs, then (with --verify) the ledger integrity check —
 * each as its own artisan command called in-process, so its output streams
 * through and its exit code is honoured: the first non-zero exit stops the
 * run and becomes this command's exit code, and the summary table at the end
 * says what each step did and which were skipped.
 *
 * Every step is idempotent and cheap once it has run — migrations apply
 * nothing, both backfills match nothing — so re-running after a failure is
 * safe, and so is running it on every deploy. --dry-run lists the pending
 * migrations and what each backfill would change without writing (the
 * integrity check is read-only, so --verify still runs under it); while
 * migrations are pending the data steps are reported, not run, because they
 * read the schema those migrations create. --company limits the backfills
 * and the check to one tenant.
 *
 * Future post-migration steps get appended to {@see steps()}, in the order
 * they must run: a title plus a closure returning [exit code, one-line outcome].
 */
class UpgradeCommand extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'app:upgrade
        {--dry-run : Show pending migrations and what the backfills would change, without writing}
        {--verify : Run integrity:check afterwards}
        {--company= : Limit the backfills to one company ID or slug}';

    protected $description = 'Run every post-release step in order: migrate, backfill bank line memos and reconciliation stamps, and optionally verify ledger integrity.';

    /** The company --company resolved to, when given. */
    private ?Company $company = null;

    /** Migrations not yet applied when the run started. */
    private int $pending = 0;

    public function handle(Migrator $migrator): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->pending = $this->pendingMigrations($migrator);

        // Resolve the scope before touching anything, so a typo does nothing at
        // all. Only id and slug are read: a tenant's identity columns are the
        // one part of `companies` no pending migration will move. An all-digit
        // argument is an id and anything else a slug — never `id OR slug`,
        // because MySQL coerces `id = '1st-street-bakery'` to `id = 1` and the
        // lowest id would win over the tenant the operator actually named.
        if (($arg = $this->option('company')) !== null) {
            $this->company = $this->whereCompanyArgument(Company::query()->withoutGlobalScopes(), $arg)
                ->first(['id', 'slug']);

            if ($this->company === null) {
                $this->error(sprintf('No company matches "%s".', $arg));

                return self::FAILURE;
            }

            $this->line(sprintf('Limiting the backfills to company %s (#%d).', $this->company->slug, $this->company->id));
        }

        if ($dryRun) {
            $this->warn('Dry run — nothing will be written.');
        }

        $summary = [];
        $exitCode = self::SUCCESS;

        foreach ($this->steps($migrator, $dryRun) as $index => [$title, $run]) {
            if ($exitCode !== self::SUCCESS) {
                $summary[] = [$title, 'Skipped'];

                continue;
            }

            $this->newLine();
            $this->line(sprintf('<options=bold>%d. %s</>', $index + 1, $title));

            [$code, $outcome] = $run();

            $code === self::SUCCESS ? $this->info($outcome) : $this->error($outcome);
            $summary[] = [$title, $outcome];

            if ($code !== self::SUCCESS) {
                $exitCode = $code;
            }
        }

        $this->newLine();
        $this->table(['Step', 'Outcome'], $summary);

        return $exitCode;
    }

    /**
     * The steps, in the order they run. Append new post-migration steps here.
     *
     * @return list<array{string, Closure(): array{int, string}}>
     */
    private function steps(Migrator $migrator, bool $dryRun): array
    {
        $steps = [
            ['Migrations', fn (): array => $this->migrate($migrator, $dryRun)],
            ['Bank line memos', fn (): array => $this->afterMigrating($dryRun) ?? $this->backfill('banking:backfill-line-memos', $dryRun)],
            ['Reconciliation stamps', fn (): array => $this->afterMigrating($dryRun) ?? $this->backfill('banking:backfill-reconciliation-stamps', $dryRun)],
        ];

        if ($this->option('verify')) {
            $steps[] = ['Integrity check', fn (): array => $this->afterMigrating($dryRun) ?? $this->verify()];
        }

        return $steps;
    }

    /**
     * @return array{int, string}
     */
    private function migrate(Migrator $migrator, bool $dryRun): array
    {
        $pending = $this->pending;

        if ($dryRun) {
            // migrate:status --pending exits non-zero whenever anything is pending
            // — its contract for gating CI, not a failure here — so its exit code
            // is deliberately not what decides this step.
            $this->call('migrate:status', ['--pending' => true]);

            return [self::SUCCESS, $pending === 0
                ? 'Up to date; a live run would migrate nothing.'
                : sprintf('%d pending; a live run would apply them.', $pending)];
        }

        $code = $this->call('migrate', ['--force' => true]);

        if ($code !== self::SUCCESS) {
            return [$code, sprintf('Migrations failed (exit %d).', $code)];
        }

        return [self::SUCCESS, $pending === 0 ? 'Up to date.' : sprintf('Applied %d migration(s).', $pending)];
    }

    /**
     * On a dry run with migrations still pending, the data steps cannot be
     * previewed: they read the schema those migrations create (on a fresh
     * database, no tables at all). Say so instead of running them.
     *
     * @return array{int, string}|null
     */
    private function afterMigrating(bool $dryRun): ?array
    {
        if (! $dryRun || $this->pending === 0) {
            return null;
        }

        return [self::SUCCESS, sprintf('Runs after the %d pending migration(s); dry-run again once they are applied to preview it.', $this->pending)];
    }

    /**
     * Migration files on disk that the repository has not recorded as run —
     * the same set migrate:status marks Pending. A missing repository (fresh
     * database) means all of them.
     */
    private function pendingMigrations(Migrator $migrator): int
    {
        $paths = [...$migrator->paths(), $this->laravel->databasePath('migrations')];
        $files = array_keys($migrator->getMigrationFiles($paths));
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return count(array_diff($files, $ran));
    }

    /**
     * @return array{int, string}
     */
    private function backfill(string $command, bool $dryRun): array
    {
        $arguments = $dryRun ? ['--dry-run' => true] : [];

        if ($this->company !== null) {
            $arguments['company'] = $this->company->id;
        }

        $code = $this->call($command, $arguments);

        if ($code !== self::SUCCESS) {
            return [$code, sprintf('%s failed (exit %d).', $command, $code)];
        }

        return [self::SUCCESS, $dryRun ? 'Counted above; nothing written.' : 'Done; see the per-company lines above.'];
    }

    /**
     * @return array{int, string}
     */
    private function verify(): array
    {
        // --no-alert: an operator is watching this run, so a failure is shown
        // here and returned as the exit code rather than emailed to ops.
        $arguments = ['--no-alert' => true];

        if ($this->company !== null) {
            $arguments['company'] = $this->company->id;
        }

        $code = $this->call('integrity:check', $arguments);

        return $code === self::SUCCESS
            ? [self::SUCCESS, 'Passed.']
            : [$code, sprintf('Failed (exit %d); see the issues above.', $code)];
    }
}
