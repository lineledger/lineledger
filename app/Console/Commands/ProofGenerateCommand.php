<?php

namespace App\Console\Commands;

use App\Services\Proof\ProofArtifactWriter;
use App\Services\Proof\ProofScenario;
use App\Services\Proof\ProofValidator;
use App\Services\Proof\ScenarioBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Builds the practical-test scenarios, validates them, and writes the downloadable
 * evidence (ZIP + manifest.json) the public /verification page reads.
 *
 * Each scenario is built inside a database transaction that is ALWAYS rolled back,
 * so running this in production leaves no proof company behind. The artifacts are
 * flushed to storage before the rollback, so they persist.
 */
class ProofGenerateCommand extends Command
{
    protected $signature = 'proof:generate
        {test? : test-1, test-2, or test-3; all three when omitted}
        {--per-year=500 : Transactions per fiscal year for Test 1}';

    protected $description = 'Generate verification artifacts (reports + source data) for the public proof page.';

    public function handle(ScenarioBuilder $builder, ProofValidator $validator, ProofArtifactWriter $writer): int
    {
        // Rendering the full general ledger to PDF (thousands of rows at full volume)
        // is memory-hungry under dompdf; give it room.
        @ini_set('memory_limit', '2048M');

        $keys = $this->argument('test') ? [$this->argument('test')] : ['test-1', 'test-2', 'test-3'];
        $perYear = (int) $this->option('per-year');
        $allPassed = true;

        foreach ($keys as $key) {
            if (! in_array($key, ['test-1', 'test-2', 'test-3'], true)) {
                $this->error("Unknown test '{$key}'. Use test-1, test-2, or test-3.");

                return self::FAILURE;
            }

            $this->info("Generating {$key}…");
            DB::beginTransaction();

            try {
                $scenario = $this->build($builder, $key, $perYear);
                $validation = $validator->validate($scenario);
                $manifest = $writer->write($scenario, $validation, CarbonImmutable::now());

                $allPassed = $allPassed && $manifest['passed'];
                $this->reportResult($manifest);
            } catch (\Throwable $e) {
                $allPassed = false;
                $this->error("  {$key} failed: {$e->getMessage()}");
            } finally {
                // Roll back so no proof company persists. We deliberately do NOT
                // Auth::logout() here — that fires a security-log insert referencing
                // the user we just rolled away, violating its foreign key. But the
                // rolled-away user must not linger as Auth::user() either: audit
                // rows written by the next scenario before its own login would
                // reference them and violate the actor_user_id foreign key. So we
                // drop the in-memory guard state without firing any events.
                DB::rollBack();
                Auth::forgetGuards();
                app()->forgetInstance('current_company');
            }
        }

        $this->newLine();
        $this->line('Artifacts written to '.ProofArtifactWriter::directory());

        return $allPassed ? self::SUCCESS : self::FAILURE;
    }

    private function build(ScenarioBuilder $builder, string $key, int $perYear): ProofScenario
    {
        return match ($key) {
            'test-1' => $builder->buildThreeYearScenario($perYear),
            'test-2' => $builder->buildImportedTrialBalanceScenario(),
            'test-3' => $builder->buildQuickBooksJournalScenario(),
        };
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function reportResult(array $manifest): void
    {
        $this->line('  '.($manifest['passed'] ? '<info>PASSED</info>' : '<error>FAILED</error>')." — {$manifest['title']}");
        foreach ($manifest['checkpoints'] as $checkpoint) {
            foreach ($checkpoint['checks'] as $check) {
                $mark = $check['passed'] ? '✓' : '✗';
                $this->line("    {$mark} {$checkpoint['label']}: {$check['name']} ({$check['detail']})");
            }
        }
        $this->line("    {$manifest['audit']['detail']}");
    }
}
