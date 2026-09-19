<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Models\Company;
use App\Services\Reconciliation\ReconciliationStampBackfiller;
use Illuminate\Console\Command;

class BackfillReconciliationStampsCommand extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'banking:backfill-reconciliation-stamps
        {company? : Company ID or slug; all companies when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Un-clear the service-charge and interest lines a completed reconciliation replaced or left unticked, so the register\'s cleared balance matches what was reconciled.';

    public function __construct(private ReconciliationStampBackfiller $backfiller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $arg = $this->argument('company');
        $dryRun = (bool) $this->option('dry-run');

        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query()->withoutGlobalScopes(), $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        // A named company that matches nothing is an operator error; no companies
        // at all (a fresh install, which app:upgrade runs on first boot) is not.
        if ($companies->isEmpty()) {
            if ($arg === null) {
                $this->info('No companies yet; nothing to backfill.');

                return self::SUCCESS;
            }

            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $result = $this->backfiller->backfill($company->id, $dryRun);

            $this->line(sprintf(
                'Company %s — %s %d line(s) on %d reconciliation(s).',
                $company->slug,
                $dryRun ? 'would un-clear' : 'un-cleared',
                $result['unstamped'],
                $result['reconciliations'],
            ));

            foreach ($result['skipped'] as $recId) {
                $this->warn(sprintf(
                    '  Skipped reconciliation #%d: its ticked lines do not match its cleared lines (restored from a backup?). Check it by hand.',
                    $recId,
                ));
            }
        }

        return self::SUCCESS;
    }
}
