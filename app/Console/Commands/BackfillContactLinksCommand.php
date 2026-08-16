<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Migration\ContactLinkBackfiller;
use Illuminate\Console\Command;

class BackfillContactLinksCommand extends Command
{
    protected $signature = 'migration:backfill-contact-links {company? : Company ID or slug; all companies when omitted}';

    protected $description = 'Backfill contact_id onto AR/AP journal lines from their source documents so GL-driven statements match the aging.';

    public function __construct(private ContactLinkBackfiller $backfiller)
    {
        parent::__construct();
    }

    public function handle(): int
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
            $result = $this->backfiller->backfill($company->id);

            $this->line(sprintf('Company %s — tagged %d journal line(s).', $company->slug, $result['updated']));
        }

        return self::SUCCESS;
    }
}
