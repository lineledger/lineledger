<?php

namespace App\Support\Storage;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * Resolves the filesystem disk backing each *kind* of durable file.
 *
 * Application code asks for a role — `StorageDisks::attachments()` — instead of
 * naming a disk, so a deployment can move blobs to object storage by setting
 * env vars alone. The role map lives in `config/filesystems.php` under `roles`
 * and defaults to the disk each role has always used, which is what keeps
 * self-hosted installs and the test suite on the local filesystem.
 *
 * Only *durable* files have roles. Scratch space (Livewire temp uploads,
 * migration CSV staging, backup/restore work directories) deliberately stays
 * local — several of its consumers need a real filesystem path.
 */
final class StorageDisks
{
    /** Transaction, document, inbox and bank-statement attachments. Private. */
    public static function attachments(): string
    {
        return self::role('attachments', 'local');
    }

    /** Company branding and document logos. Publicly readable. */
    public static function logos(): string
    {
        return self::role('logos', 'public');
    }

    /** Finished company backup ZIPs. Private. */
    public static function backups(): string
    {
        return self::role('backups', 'local');
    }

    /**
     * Whether a disk is backed by the local filesystem, and so can hand out a
     * real path via `Storage::disk($name)->path()`.
     *
     * Callers that shell out to a binary, open a ZipArchive, or otherwise need
     * a path use this to decide between the direct path and a temporary local
     * copy — see {@see TemporaryLocalFile}.
     */
    public static function isLocal(string $disk): bool
    {
        return config("filesystems.disks.{$disk}.driver") === 'local';
    }

    /**
     * Resolve a role to a disk name, falling back to the historical default if
     * the role is unset, and validating that the disk actually exists.
     *
     * A typo in ATTACHMENT_DISK would otherwise surface much later as a
     * confusing "Disk [xyz] does not have a configured driver" deep inside an
     * upload; failing here names the culprit.
     */
    private static function role(string $role, string $fallback): string
    {
        $configured = config("filesystems.roles.{$role}");
        $disk = is_string($configured) && $configured !== '' ? $configured : $fallback;

        if (! Config::has("filesystems.disks.{$disk}")) {
            throw new RuntimeException(
                "Storage role [{$role}] points at disk [{$disk}], "
                .'which is not defined in config/filesystems.php.'
            );
        }

        return $disk;
    }
}
