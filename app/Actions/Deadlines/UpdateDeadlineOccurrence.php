<?php

namespace App\Actions\Deadlines;

use App\Enums\AuditAction;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Services\AttachmentService;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Deadlines\DeadlineAssignees;
use App\Services\Deadlines\DeadlineAuditPayload;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Edits one occurrence on its own: reassigns it, moves its due date (an
 * extension, a holiday) or adds notes and files. The occurrence is stamped
 * `customized_at`, so later changes to the series rule leave it as edited.
 *
 * Expected $data:
 *   assigned_user_id:  ?int (an eligible member)
 *   due_date:          string (Y-m-d)
 *   notes:             ?string
 *   attachments:       list<TemporaryUploadedFile>
 */
final class UpdateDeadlineOccurrence
{
    public function __construct(
        private readonly AccountingAuditRecorder $recorder,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(Company $company, DeadlineOccurrence $occurrence, array $data): DeadlineOccurrence
    {
        $data['assigned_user_id'] = filled($data['assigned_user_id'] ?? null) ? (int) $data['assigned_user_id'] : null;

        $valid = Validator::make($data, [
            'assigned_user_id' => ['nullable', 'integer'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->after(function ($validator) use ($company, $data, $occurrence): void {
            $assignee = $data['assigned_user_id'];

            // Keeping an assignee who has since lost access is allowed; picking one isn't.
            if ($assignee !== null && $assignee !== $occurrence->assigned_user_id
                && ! DeadlineAssignees::isEligible($company, $assignee)) {
                $validator->errors()->add('assigned_user_id', __('Choose someone in this organization who can open and complete deadlines.'));
            }
        })->validate();

        $occurrence = DB::transaction(function () use ($company, $occurrence, $valid): DeadlineOccurrence {
            /** @var DeadlineOccurrence $fresh */
            $fresh = DeadlineOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();

            $fresh->fill([
                'assigned_user_id' => $valid['assigned_user_id'] ?? null,
                'due_date' => $valid['due_date'],
                'notes' => filled($valid['notes'] ?? null) ? trim((string) $valid['notes']) : null,
            ]);

            if ($fresh->isDirty()) {
                $fresh->customized_at = now();
                $fresh->save();

                $this->recorder->record(
                    $company->id,
                    AuditAction::DeadlineOccurrenceUpdated,
                    $fresh,
                    ['attributes' => DeadlineAuditPayload::occurrence($fresh)],
                );
            }

            return $fresh;
        });

        $uploads = $data['attachments'] ?? [];

        if ($uploads !== []) {
            $this->attachments->upload($occurrence, $uploads, Auth::id());

            if ($occurrence->customized_at === null) {
                $occurrence->forceFill(['customized_at' => now()])->save();
            }
        }

        return $occurrence->refresh();
    }
}
