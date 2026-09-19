<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Jobs\GenerateDailyInsightForCompany;
use App\Models\Company;
use App\Services\Insights\DailyInsightGenerator;
use Illuminate\Console\Command;

class GenerateDailyInsights extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'insights:generate {company? : Company ID or slug; all companies when omitted} {--sync : Generate inline instead of dispatching a queued job per company}';

    protected $description = 'Compute and store each company\'s daily "Did you know?" insight.';

    public function handle(DailyInsightGenerator $generator): int
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
                GenerateDailyInsightForCompany::dispatch($company->id);
                $this->line(sprintf('%s — queued.', $company->slug));

                continue;
            }

            $insight = $generator->generate($company, $company->currentDateTime());

            $this->line($insight === null
                ? sprintf('%s — no insight today.', $company->slug)
                : sprintf('%s — %s (%s).', $company->slug, $insight->headline, $insight->source->value));
        }

        return self::SUCCESS;
    }
}
