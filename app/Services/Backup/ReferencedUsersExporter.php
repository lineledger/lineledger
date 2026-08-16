<?php

namespace App\Services\Backup;

use App\Models\User;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Walks every table in the {@see BackupTableRegistry} and collects the
 * distinct set of `users.id` referenced by any audit-flavoured column
 * (`*_user_id`, `uploaded_by_id`, `invited_by`, `created_by`) for the
 * given company. Emits `users.json` so the Phase 2 importer can remap
 * IDs by email-match on the restore target.
 *
 * Only scans tables that carry a direct `company_id` column. The handful
 * of child tables that lack one (`journal_lines`, `receipt_applications`,
 * `bill_payment_applications`, `*_lines`) generally do not store their
 * own audit user — that lives on the parent row, which this pass already
 * covers.
 */
final class ReferencedUsersExporter
{
    /**
     * Suffix / exact column names that we treat as a `users.id` reference.
     *
     * @var list<string>
     */
    private const USER_ID_SUFFIXES = ['_user_id'];

    /**
     * @var list<string>
     */
    private const USER_ID_EXACT = ['uploaded_by_id', 'invited_by', 'created_by', 'user_id'];

    /**
     * @return array{count: int, sha256: string, bytes: int}
     */
    public function exportReferencedUsers(int $companyId, string $workDir): array
    {
        $userIds = [];

        foreach (BackupTableRegistry::tables() as $entry) {
            $table = $entry['table'];
            $model = $entry['model'];

            // Only scan tables with a direct `company_id` column. Child tables
            // (e.g. journal_lines, *_lines, receipt_applications) inherit
            // tenancy via their parent — the parent row's audit user is
            // already collected when we scan the parent table.
            if (! Schema::hasColumn($table, 'company_id')) {
                continue;
            }

            $userColumns = $this->userColumnsFor($table);

            if ($userColumns === []) {
                continue;
            }

            foreach ($userColumns as $col) {
                $ids = $model::query()
                    ->withoutGlobalScopes()
                    ->where('company_id', $companyId)
                    ->whereNotNull($col)
                    ->pluck($col)
                    ->all();

                foreach ($ids as $id) {
                    if ($id === null) {
                        continue;
                    }
                    $userIds[(int) $id] = true;
                }
            }
        }

        $users = User::query()
            ->whereIn('id', array_keys($userIds))
            ->orderBy('id')
            ->get(['id', 'email', 'name'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->id,
                'email' => $u->email,
                'name' => $u->name,
            ])
            ->all();

        if (! is_dir($workDir) && ! @mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            throw new RuntimeException("Unable to create backup working directory: {$workDir}");
        }

        $json = json_encode(
            $users,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            throw new RuntimeException('json_encode failed for users.json: '.json_last_error_msg());
        }

        $filePath = $workDir.'/users.json';

        if (file_put_contents($filePath, $json) === false) {
            throw new RuntimeException("Failed to write users.json to {$filePath}");
        }

        return [
            'count' => count($users),
            'sha256' => hash('sha256', $json),
            'bytes' => strlen($json),
        ];
    }

    /**
     * Columns on `$table` that hold a `users.id` reference, inferred from
     * the column name. Liberal by design — over-including a user id is
     * harmless on restore, missing one would silently lose audit linkage.
     *
     * @return list<string>
     */
    private function userColumnsFor(string $table): array
    {
        $columns = Schema::getColumnListing($table);
        $matches = [];

        foreach ($columns as $col) {
            if (in_array($col, self::USER_ID_EXACT, true)) {
                $matches[] = $col;

                continue;
            }

            foreach (self::USER_ID_SUFFIXES as $suffix) {
                if (str_ends_with($col, $suffix)) {
                    $matches[] = $col;

                    continue 2;
                }
            }
        }

        return $matches;
    }
}
