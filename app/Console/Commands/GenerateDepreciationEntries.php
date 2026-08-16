<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDepreciationForCompany;
use App\Models\Company;
use App\Services\Assets\DepreciationGenerator;
use Illuminate\Console\Command;

class GenerateDepreciationEntries extends Command
{
    protected $signature = 'depreciation:generate {company? : Company ID or slug; all companies when omitted} {--sync : Generate inline instead of dispatching a queued job per company}';

    protected $description = 'Generate Draft monthly book-depreciation journal entries for assets with auto-depreciation enabled.';

    public function handle(DepreciationGenerator $generator): int
    {
        $arg = $this->argument('company');

        $companies = $arg !== null
            ? Company::query()->withoutGlobalScopes()->where('id', $arg)->orWhere('slug', $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

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
