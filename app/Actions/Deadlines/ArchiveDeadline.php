<?php

namespace App\Actions\Deadlines;

use App\Enums\AuditAction;
use App\Models\Company;
use App\Models\Deadline;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Deadlines\DeadlineAuditPayload;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use Illuminate\Support\Facades\DB;

/**
 * Archives a deadline the organization no longer has (a closed account, a
 * deregistered program), or pauses / resumes one. Archiving removes the open
 * occurrences nobody has touched; completed history stays.
 */
final class ArchiveDeadline
{
    public function __construct(
        private readonly DeadlineOccurrenceSync $sync,
        private readonly AccountingAuditRecorder $recorder,
    ) {}

    public function archive(Company $company, Deadline $deadline): Deadline
    {
        return $this->change($company, $deadline, ['archived_at' => now()], AuditAction::DeadlineArchived);
    }

    public function restore(Company $company, Deadline $deadline): Deadline
    {
        return $this->change($company, $deadline, ['archived_at' => null], AuditAction::DeadlineUpdated);
    }

    public function pause(Company $company, Deadline $deadline): Deadline
    {
        return $this->change($company, $deadline, ['is_active' => false], AuditAction::DeadlineUpdated);
    }

    public function resume(Company $company, Deadline $deadline): Deadline
    {
        return $this->change($company, $deadline, ['is_active' => true], AuditAction::DeadlineUpdated);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function change(Company $company, Deadline $deadline, array $attributes, AuditAction $action): Deadline
    {
        return DB::transaction(function () use ($company, $deadline, $attributes, $action): Deadline {
            $deadline->forceFill($attributes)->save();
            $this->sync->sync($deadline, $company->currentDateTime());

            $this->recorder->record(
                $company->id,
                $action,
                $deadline,
                ['attributes' => DeadlineAuditPayload::deadline($deadline)],
            );

            return $deadline->refresh();
        });
    }
}
