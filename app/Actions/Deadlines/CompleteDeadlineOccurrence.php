<?php

namespace App\Actions\Deadlines;

use App\Enums\AuditAction;
use App\Enums\DeadlineStatus;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Services\AttachmentService;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Deadlines\DeadlineAssignees;
use App\Services\Deadlines\DeadlineAuditPayload;
use App\Support\Deadlines\DeadlineDates;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Marks one occurrence done: who did it, on what date, for how much, with what
 * confirmation number, and any receipts. Re-reads the row under a lock so two
 * people marking the same deadline done at once can't both succeed.
 *
 * Expected $data:
 *   completed_on:          string (Y-m-d)
 *   completed_by_user_id:  int (an eligible member; defaults to the signed-in user)
 *   amount:                ?string
 *   confirmation_number:   ?string
 *   notes:                 ?string
 *   attachments:           list<TemporaryUploadedFile>
 */
final class CompleteDeadlineOccurrence
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
        $data['completed_by_user_id'] = $data['completed_by_user_id'] ?? Auth::id();

        $valid = Validator::make($data, [
            'completed_on' => ['required', 'date_format:Y-m-d'],
            'completed_by_user_id' => ['required', 'integer'],
            'amount' => ['nullable', 'string', 'max:30'],
            'confirmation_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->after(function ($validator) use ($company, $data): void {
            if (! DeadlineAssignees::isEligible($company, (int) $data['completed_by_user_id'])) {
                $validator->errors()->add('completed_by_user_id', __('Choose someone in this organization who can complete deadlines.'));
            }

            if (filled($data['amount'] ?? null) && Money::tryFromString((string) $data['amount']) === null) {
                $validator->errors()->add('amount', __('Enter an amount like 1,234.56.'));
            }
        })->validate();

        $uploads = $data['attachments'] ?? [];

        $occurrence = DB::transaction(function () use ($company, $occurrence, $valid): DeadlineOccurrence {
            /** @var DeadlineOccurrence $fresh */
            $fresh = DeadlineOccurrence::query()->whereKey($occurrence->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === DeadlineStatus::Done) {
                throw ValidationException::withMessages([
                    'completed_on' => __('Already marked done by :name on :date.', [
                        'name' => $fresh->completedBy->name ?? __('someone'),
                        'date' => DeadlineDates::medium($fresh->completed_on),
                    ]),
                ]);
            }

            $fresh->fill([
                'status' => DeadlineStatus::Done,
                'completed_on' => $valid['completed_on'],
                'completed_by_user_id' => (int) $valid['completed_by_user_id'],
                'completed_at' => now(),
                'amount_cents' => filled($valid['amount'] ?? null)
                    ? Money::fromString((string) $valid['amount'], (string) $company->currency_code)->cents
                    : null,
                'confirmation_number' => filled($valid['confirmation_number'] ?? null) ? trim((string) $valid['confirmation_number']) : null,
                'notes' => filled($valid['notes'] ?? null) ? trim((string) $valid['notes']) : $fresh->notes,
                'skipped_reason' => null,
            ])->save();

            $this->recorder->record(
                $company->id,
                AuditAction::DeadlineOccurrenceCompleted,
                $fresh,
                ['attributes' => DeadlineAuditPayload::occurrence($fresh)],
            );

            return $fresh;
        });

        if ($uploads !== []) {
            $this->attachments->upload($occurrence, $uploads, Auth::id());
        }

        return $occurrence->refresh();
    }
}
