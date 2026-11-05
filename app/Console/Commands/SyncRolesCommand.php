<?php

namespace App\Console\Commands;

use App\Console\Concerns\ResolvesCompanyArgument;
use App\Models\Company;
use App\Services\Access\LegacyRoleConverter;
use App\Services\Access\PredefinedRoleSeeder;
use Illuminate\Console\Command;

/**
 * Brings every company's roles up to date: adds the predefined roles it is
 * missing, then gives each member and invitation without a role the roles
 * their original role converts to (Admin → Full Access, Accountant →
 * Accountant, Custom → a role per distinct set of sections). Nobody's access
 * changes. Idempotent; part of `app:upgrade`.
 */
class SyncRolesCommand extends Command
{
    use ResolvesCompanyArgument;

    protected $signature = 'access:sync-roles
        {company? : Company ID or slug; all companies when omitted}
        {--dry-run : Report what would change without writing}';

    protected $description = 'Give every company its predefined roles and convert members and invitations that hold no role yet.';

    public function __construct(
        private readonly PredefinedRoleSeeder $seeder,
        private readonly LegacyRoleConverter $converter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $arg = $this->argument('company');
        $dryRun = (bool) $this->option('dry-run');

        // Soft-deleted companies too, so one restored from the trash has roles.
        $companies = $arg !== null
            ? $this->whereCompanyArgument(Company::query()->withoutGlobalScopes(), $arg)->get()
            : Company::query()->withoutGlobalScopes()->orderBy('id')->get();

        if ($companies->isEmpty()) {
            if ($arg === null) {
                $this->info('No companies yet; nothing to convert.');

                return self::SUCCESS;
            }

            $this->error('No matching company.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $seeded = $this->seeder->seed($company, $dryRun);
            $result = $this->converter->convertCompany($company, $dryRun);

            $this->line(sprintf(
                'Company %s — %s %d predefined role(s), %d member(s) and %d invitation(s) converted, %d custom role(s) %s.',
                $company->slug,
                $dryRun ? 'would add' : 'added',
                $seeded,
                $result['members'],
                $result['invitations'],
                $result['roles_created'],
                $dryRun ? 'to create' : 'created',
            ));
        }

        return self::SUCCESS;
    }
}
