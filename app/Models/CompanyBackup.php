<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\CompanyBackupStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Static analysis can't see through `casts()` to the enum, and without this it
 * reads `$status` as a plain string — which makes the strict `in_array()` gate
 * in CompanyExporter::export() look permanently false and the whole method body
 * dead code.
 *
 * @property CompanyBackupStatus $status
 * @property string|null $disk
 * @property string|null $file_path
 */
#[Fillable([
    'company_id', 'requested_by_user_id', 'status', 'disk', 'file_path',
    'file_size_bytes', 'sha256', 'row_counts', 'app_version',
    'schema_version', 'error_message', 'expires_at',
])]
class CompanyBackup extends Model
{
    use BelongsToCompany;
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * The disk this archive was written to. Rows created before backups could
     * live on object storage carry no value and are on the local disk.
     */
    public function storageDisk(): string
    {
        return $this->disk ?: 'local';
    }

    public function isReady(): bool
    {
        return $this->status === CompanyBackupStatus::Ready
            && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        if ($this->status === CompanyBackupStatus::Expired) {
            return true;
        }

        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyBackupStatus::class,
            'row_counts' => 'array',
            'expires_at' => 'datetime',
            'file_size_bytes' => 'integer',
            'schema_version' => 'integer',
        ];
    }
}
