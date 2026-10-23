<?php

namespace App\Models;

use App\Enums\CompanyRestoreStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// NOTE: No BelongsToCompany trait — restore rows exist before the Company they create.
/**
 * Static analysis can't see through `casts()` to the enum, and without this it
 * reads `$status` as a plain string — which makes the strict `in_array()` gate
 * in CompanyImporter::import() look permanently false and the restore itself
 * dead code.
 *
 * @property CompanyRestoreStatus $status
 * @property string|null $disk
 * @property string|null $file_path
 */
#[Fillable([
    'requested_by_user_id', 'company_id', 'status', 'file_path', 'disk',
    'file_size_bytes', 'sha256', 'manifest_data', 'step_results',
    'error_message', 'started_at', 'completed_at',
])]
class CompanyRestore extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The disk the uploaded bundle was staged on. Rows created before bundles
     * could live on object storage carry no value and are on the local disk.
     */
    public function storageDisk(): string
    {
        return $this->disk ?: 'local';
    }

    public function isComplete(): bool
    {
        return $this->status === CompanyRestoreStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === CompanyRestoreStatus::Failed;
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, [
            CompanyRestoreStatus::Pending,
            CompanyRestoreStatus::Inspecting,
            CompanyRestoreStatus::Running,
        ], true);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompanyRestoreStatus::class,
            'manifest_data' => 'array',
            'step_results' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'file_size_bytes' => 'integer',
        ];
    }
}
