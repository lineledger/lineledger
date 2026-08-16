<?php

namespace App\Console\Commands;

use App\Enums\CompanyBackupStatus;
use App\Enums\CompanyRole;
use App\Jobs\ExportCompanyDataJob;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Models\Membership;
use App\Services\Backup\CompanyExporter;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operational entry point for the backup exporter. Two modes:
 *
 *   php artisan backup:export --company=7              # dispatches the queued job
 *   php artisan backup:export --company=7 --sync       # runs in-process so stack
 *                                                       # traces surface in the shell
 *
 * The `--sync` mode is what you reach for during a smoke test: any exception
 * inside the exporter bubbles straight out of handle(), and the produced ZIP
 * path is printed for `unzip -l` follow-up.
 */
class ExportCompanyCommand extends Command
{
    protected $signature = 'backup:export {--company= : Company ID} {--sync : Run the export synchronously instead of dispatching the queued job}';

    protected $description = 'Produce a ZIP backup of a single company\'s data on the local disk.';

    public function handle(): int
    {
        $companyId = $this->option('company');

        if ($companyId === null || $companyId === '') {
            $this->error('--company=<id> is required.');

            return self::FAILURE;
        }

        $company = Company::query()
            ->withoutGlobalScopes()
            ->where('id', $companyId)
            ->first();

        if ($company === null) {
            $this->error("No company with id [{$companyId}].");

            return self::FAILURE;
        }

        $ownerUserId = Membership::query()
            ->where('company_id', $company->id)
            ->where('role', CompanyRole::Owner)
            ->value('user_id');

        // BelongsToCompany stamps company_id from app('current_company') on
        // creating() — bind it so the row lands on the right tenant.
        app()->instance('current_company', $company);

        $backup = CompanyBackup::create([
            'status' => CompanyBackupStatus::Pending,
            'requested_by_user_id' => $ownerUserId,
            'app_version' => config('version.app'),
            'schema_version' => config('version.schema'),
        ]);

        $this->line(sprintf('Created CompanyBackup #%d for %s.', $backup->id, $company->slug));

        if (! $this->option('sync')) {
            ExportCompanyDataJob::dispatch($backup);
            $this->info(sprintf('Backup #%d queued.', $backup->id));

            return self::SUCCESS;
        }

        $backup->forceFill(['status' => CompanyBackupStatus::Running])->save();

        try {
            app(CompanyExporter::class)->export($backup);
        } catch (Throwable $e) {
            $this->error(sprintf('Backup #%d failed: %s', $backup->id, $e->getMessage()));

            return self::FAILURE;
        }

        $backup->refresh();

        $this->info(sprintf(
            'Backup #%d ready: %s (%s bytes, sha256 %s).',
            $backup->id,
            $backup->file_path ?? 'unknown',
            number_format((int) $backup->file_size_bytes),
            $backup->sha256 ?? 'n/a',
        ));

        return self::SUCCESS;
    }
}
