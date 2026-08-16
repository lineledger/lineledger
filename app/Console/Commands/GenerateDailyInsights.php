<?php

namespace App\Console\Commands;

use App\Jobs\GenerateDailyInsightForCompany;
use App\Models\Company;
use App\Services\Insights\DailyInsightGenerator;
use Illuminate\Console\Command;

class GenerateDailyInsights extends Command
{
    protected $signature = 'insights:generate {company? : Company ID or slug; all companies when omitted} {--sync : Generate inline instead of dispatching a queued job per company}';

    protected $description = 'Compute and store each company\'s daily "Did you know?" insight.';

    public function handle(DailyInsightGenerator $generator): int
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
