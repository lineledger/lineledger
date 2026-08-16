<?php

namespace App\Actions\Payroll;

use App\Enums\AuditAction;
use App\Enums\TimeEntryStatus;
use App\Models\TimeEntry;
use App\Services\Audit\AccountingAuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Approve or reject time entries in bulk. Only entries not yet consumed by a pay
 * run or an invoice may change status — once time has been paid or billed its
 * approval state is locked. Each entry that actually changes status gets its own
 * immutable audit row. Company isolation comes from the global scope.
 */
final class SetTimeEntryStatus
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    /**
     * @param  array<int, int>  $ids
     * @return int the number of entries whose status changed
     */
    public function handle(array $ids, TimeEntryStatus $status): int
    {
        if ($ids === []) {
            return 0;
        }

        return DB::transaction(function () use ($ids, $status): int {
            $entries = TimeEntry::query()
                ->whereIn('id', $ids)
                ->whereNull('pay_run_id')
                ->whereNull('invoice_id')
                ->get();

            $changed = 0;

            foreach ($entries as $entry) {
                $from = $entry->status;

                if ($from === $status) {
                    continue;
                }

                $entry->update(['status' => $status->value]);

                $action = match ($status) {
                    TimeEntryStatus::Approved => AuditAction::TimeEntryApproved,
                    TimeEntryStatus::Rejected => AuditAction::TimeEntryRejected,
                    TimeEntryStatus::Pending => AuditAction::TimeEntryUpdated,
                };

                $this->recorder->record((int) $entry->company_id, $action, $entry, [
                    'from' => $from->value,
                    'to' => $status->value,
                ]);

                $changed++;
            }

            return $changed;
        });
    }
}
