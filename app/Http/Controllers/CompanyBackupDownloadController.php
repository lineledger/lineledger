<?php

namespace App\Http\Controllers;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanyBackup;
use App\Support\Storage\StorageDisks;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a company backup ZIP to an authorized owner.
 *
 * The `signed` middleware on the route already rejects unsigned / expired
 * links with a 403 before this method runs. The controller then layers on
 * defense-in-depth checks:
 *
 *  1. The bound CompanyBackup must belong to the route-bound Company —
 *     because SubstituteBindings runs before EnsureCompanyMembership in the
 *     default Laravel 13 priority list, the global CompanyScope is inactive
 *     at binding time and a foreign-tenant backup could otherwise be
 *     resolved by ID (see project memory: cross-tenant-idor-livewire).
 *  2. The user must hold CompanyRole::Owner on that company. Backups embed
 *     API keys, member emails, and full GL — they're an owner-only secret.
 *  3. The backup must be in the Ready state and the underlying ZIP must
 *     still exist on disk (the prune command nulls file_path on expiry).
 */
class CompanyBackupDownloadController extends Controller
{
    public function __invoke(Request $request, Company $company, CompanyBackup $backup): BinaryFileResponse|StreamedResponse|RedirectResponse
    {
        abort_unless($backup->company_id === $company->id, 404);

        $user = $request->user();
        abort_unless(
            $user !== null && $user->companyRole($company) === CompanyRole::Owner,
            403,
        );

        abort_unless($backup->isReady(), 409, 'Backup not ready');

        $diskName = $backup->storageDisk();
        $disk = Storage::disk($diskName);

        abort_unless(
            $backup->file_path !== null && $disk->exists($backup->file_path),
            410,
            'Backup file no longer exists',
        );

        $filename = "lineledger-backup-{$company->slug}-{$backup->id}.zip";

        // On object storage, hand the client a short-lived presigned URL rather
        // than proxying the bytes: a backup ZIP is unbounded in size and
        // streaming it through PHP would pin a worker for the whole transfer.
        // Authorization has already been settled above; the signed link is
        // minted only for a request that passed every check.
        if (! StorageDisks::isLocal($diskName)) {
            return redirect()->away($disk->temporaryUrl(
                $backup->file_path,
                CarbonImmutable::now()->addMinutes(5),
                [
                    'ResponseContentType' => 'application/zip',
                    'ResponseContentDisposition' => 'attachment; filename="'.$filename.'"',
                ],
            ));
        }

        return $disk->download(
            $backup->file_path,
            $filename,
            [
                'Content-Type' => 'application/zip',
                'X-Content-SHA256' => $backup->sha256 ?? '',
            ],
        );
    }
}
