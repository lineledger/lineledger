<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Jobs\GenerateDepreciationForCompany;
use App\Models\Company;
use App\Services\Assets\DepreciationGenerator;
use Illuminate\Console\Command;

class GenerateDepreciationEntries extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'depreciation:generate {company? : Company ID or slug; all companies when omitted} {--sync : Generate inline instead of dispatching a queued job per company}';

    protected $description = 'Generate Draft monthly book-depreciation journal entries for assets with auto-depreciation enabled.';

    public function handle(DepreciationGenerator $generator): int
    {
        $arg = $this->argument('company');

        // Live companies only: Company's one global scope is soft-deletion, so
        // enumerating without scopes queued work for deleted tenants that the
        // per-company job could then never load (nightly failed jobs).
        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query(), $arg)->get()
            : Company::query()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            if (! $this->option('sync')) {
                GenerateDepreciationForCompany::dispatch($company->id);
                $this->line(sprintf('%s — queued.', $company->slug));

                continue;
            }

            $today = $company->currentDateTime()->startOfDay();

            $count = $generator->generateDue($company, $today)->count();

            $this->line(sprintf('%s — generated %d draft(s).', $company->slug, $count));
        }

        return self::SUCCESS;
    }
}
