<?php

namespace App\Console\Commands;

use App\Actions\Companies\PurgeCompany;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Services\Audit\SecurityLogRecorder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Daily sweep that permanently erases organizations whose owner deleted them
 * more than `companies.purge_after_days` ago.
 *
 * Only a trashed company with a purge date that has passed qualifies. The
 * owner-facing delete sets that date; the admin portal's "Mark as deleted"
 * does not (an indefinite hold), and restoring clears it, so neither an admin
 * hold nor a restored company is ever touched here. Companies deleted before
 * purge dates existed have none either, and stay for an admin to purge.
 */
class PurgeDeletedCompaniesCommand extends Command
{
    protected $signature = 'companies:purge-deleted
                            {--dry-run : List the organizations that are due without purging them}';

    protected $description = 'Permanently erase owner-deleted organizations whose grace period has ended.';

    public function handle(PurgeCompany $purger, SecurityLogRecorder $recorder): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $purged = 0;
        $failed = 0;

        // Keyset paging (eachById), not offset paging: rows vanish as the loop
        // purges them, and an offset would skip the ones that slide back.
        Company::onlyTrashed()
            ->whereNotNull('purge_at')
            ->where('purge_at', '<=', now())
            ->eachById(function (Company $company) use ($dryRun, $purger, $recorder, &$purged, &$failed): void {
                $label = "{$company->name} (#{$company->id}, {$company->slug})";

                if ($dryRun) {
                    $this->line("Would purge {$label}, due {$company->purge_at?->toDateTimeString()}.");
                    $purged++;

                    return;
                }

                // Captured first: once the purge commits, this metadata is the
                // only surviving record of the organization.
                $metadata = [
                    'company_id' => $company->id,
                    'slug' => $company->slug,
                    'name' => $company->name,
                    'actor' => 'scheduler',
                    'reason' => 'grace period elapsed',
                    'deleted_at' => $company->deleted_at?->toIso8601String(),
                    'purge_at' => $company->purge_at?->toIso8601String(),
                ];

                try {
                    $purger->handle($company);
                } catch (Throwable $e) {
                    // One organization that fails to purge must not hold back
                    // the rest; it stays soft-deleted and is retried tomorrow.
                    report($e);
                    $failed++;
                    $this->error("Failed to purge {$label}: {$e->getMessage()}");

                    return;
                }

                $recorder->record(SecurityEvent::CompanyPurged, metadata: $metadata);
                $purged++;
                $this->line("Purged {$label}.");
            });

        if ($dryRun) {
            $this->info("{$purged} organization(s) due for purge. Nothing was changed.");

            return self::SUCCESS;
        }

        $this->info("Purged {$purged} organization(s).".($failed > 0 ? " {$failed} failed." : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
