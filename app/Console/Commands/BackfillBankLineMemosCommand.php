<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Banking\BankLineMemoBackfiller;
use Illuminate\Console\Command;

class BackfillBankLineMemosCommand extends Command
{
    protected $signature = 'banking:backfill-line-memos
        {company? : Company ID or slug; all companies when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = "Append each posted document's own memo to its bank journal line so the register reads \"Deposit: <memo>\" instead of a bare \"Deposit\".";

    public function __construct(private BankLineMemoBackfiller $backfiller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $arg = $this->argument('company');
        $dryRun = (bool) $this->option('dry-run');

        // An all-digit argument is an id and anything else a slug — never
        // `id OR slug`, because MySQL coerces `id = '1st-street-bakery'` to
        // `id = 1` (and `slug = 5` matches '5abc'), pulling in a second tenant.
        $companies = $arg !== null
            ? Company::query()
                ->withoutGlobalScopes()
                ->when(
                    ctype_digit((string) $arg),
                    fn ($query) => $query->whereKey((int) $arg),
                    fn ($query) => $query->where('slug', $arg),
                )
                ->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $result = $this->backfiller->backfill($company->id, $dryRun);

            $this->line(sprintf(
                'Company %s — %s %d bank line memo(s).',
                $company->slug,
                $dryRun ? 'would rewrite' : 'rewrote',
                $result['updated'],
            ));
        }

        return self::SUCCESS;
    }
}
