<?php

namespace App\Actions\Deadlines;

use App\Enums\AuditAction;
use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Deadlines\DeadlineAuditPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Marks one occurrence not required this time (a nil return that needn't be
 * filed, a period with no payroll), with an optional reason.
 */
final class SkipDeadlineOccurrence
{
    public function __construct(private readonly AccountingAuditRecorder $recorder) {}

    public function handle(Company $company, DeadlineOccurrence $occurrence, ?string $reason = null): DeadlineOccurrence
    {
        $reason = filled($reason) ? mb_substr(trim((string) $reason), 0, 255) : null;

        return DB::transaction(function () use ($company, $occurrence, $reason): DeadlineOccurrence {
            /** @var DeadlineOccurrence $fresh */
            $fresh = DeadlineOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status !== DeadlineStatus::Open) {
                throw ValidationException::withMessages([
                    'skipped_reason' => __('This deadline has already been dealt with.'),
                ]);
            }

            $fresh->fill([
                'status' => DeadlineStatus::Skipped,
                'skipped_reason' => $reason,
                'completed_by_user_id' => null,
                'completed_on' => null,
                'completed_at' => null,
            ])->save();

            $this->recorder->record(
                $company->id,
                AuditAction::DeadlineOccurrenceSkipped,
                $fresh,
                ['attributes' => DeadlineAuditPayload::occurrence($fresh)],
            );

            return $fresh;
        });
    }
}
