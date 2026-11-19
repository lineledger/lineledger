<?php

namespace App\Actions\Deadlines;

use App\Enums\AuditAction;
use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Deadlines\DeadlineAuditPayload;
use Illuminate\Support\Facades\DB;

/**
 * Puts a done or skipped occurrence back on the open list. The completion
 * details are cleared; the audit log keeps what they were. The occurrence is
 * marked edited, so the series rule no longer rewrites it.
 */
final class ReopenDeadlineOccurrence
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    public function handle(Company $company, DeadlineOccurrence $occurrence): DeadlineOccurrence
    {
        return DB::transaction(function () use ($company, $occurrence): DeadlineOccurrence {
            /** @var DeadlineOccurrence $fresh */
            $fresh = DeadlineOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === DeadlineStatus::Open) {
                return $fresh;
            }

            $fresh->fill([
                'status' => DeadlineStatus::Open,
                'completed_by_user_id' => null,
                'completed_on' => null,
                'completed_at' => null,
                'amount_cents' => null,
                'confirmation_number' => null,
                'skipped_reason' => null,
                'customized_at' => now(),
            ])->save();

            $this->recorder->record(
                $company->id,
                AuditAction::DeadlineOccurrenceReopened,
                $fresh,
                ['attributes' => DeadlineAuditPayload::occurrence($fresh)],
            );

            return $fresh;
        });
    }
}
