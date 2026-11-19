<?php

namespace App\Actions\Deadlines;

use App\Enums\AuditAction;
use App\Enums\DeadlineCategory;
use App\Enums\DeadlineFrequency;
use App\Enums\DeadlineKind;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\WeekendAdjustment;
use App\Models\Company;
use App\Models\Deadline;
use App\Services\Audit\AccountingAuditRecorder;
use App\Services\Deadlines\DeadlineAssignees;
use App\Services\Deadlines\DeadlineAuditPayload;
use App\Services\Deadlines\DeadlineOccurrenceSync;
use App\Services\Deadlines\DeadlineRule;
use App\Support\Money;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Creates or updates a deadline and regenerates its occurrences. Shared by the
 * deadline form, the suggestions page and the MCP tools, so validation lives
 * here rather than in any one caller.
 *
 * Expected $data (keys match the form's properties, so errors land on fields):
 *   title, category, kind:                   string (enum values)
 *   agency_name, account_reference:          ?string
 *   tax_agency_id:                           ?int (this organization's)
 *   portal_url:                              ?string (http/https)
 *   instructions:                            ?string
 *   expected_amount:                         ?string ("1,234.56")
 *   assigned_user_id:                        ?int (an eligible member)
 *   notify_days_before:                      list<int> (0–90)
 *   frequency:                               string (DeadlineFrequency)
 *   interval:                                ?int (1–52)
 *   period_anchor, due_date:                 ?string (Y-m-d)
 *   track_from:                              ?string (Y-m-d; no occurrences due
 *                                            before it. New: today when blank;
 *                                            existing: kept when blank)
 *   annual_dates:                            list<{month, day|"last"}>
 *   due_months_after, due_days_after:        ?int
 *   due_day_rule:                            string (DueDayRule)
 *   due_day_of_month:                        ?int
 *   weekend_adjustment, end_type:            string
 *   end_date:                                ?string (Y-m-d)
 *   max_occurrences:                         ?int
 *   template_key:                            ?string
 */
final class SaveDeadline
{
    public function __construct(
        private readonly DeadlineOccurrenceSync $sync,
        private readonly AccountingAuditRecorder $recorder,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $data, ?Deadline $deadline = null): Deadline
    {
        $attributes = $this->validated($company, $data);

        return DB::transaction(function () use ($company, $attributes, $deadline): Deadline {
            $isNew = $deadline === null || ! $deadline->exists;

            if ($isNew) {
                $deadline = new Deadline;
                $deadline->company_id = $company->id;
                $deadline->created_by_user_id = Auth::id();
                $attributes['track_from'] ??= $company->currentDateTime()->toDateString();
            }

            $deadline->fill($attributes);
            $deadline->save();

            $this->sync->sync($deadline, $company->currentDateTime());

            $this->recorder->record(
                $company->id,
                $isNew ? AuditAction::DeadlineCreated : AuditAction::DeadlineUpdated,
                $deadline,
                ['attributes' => DeadlineAuditPayload::deadline($deadline)],
            );

            return $deadline->refresh();
        });
    }

    /**
     * The validation rules, keyed by the $data keys.
     *
     * @return array<string, mixed>
     */
    public static function rules(Company $company): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::enum(DeadlineCategory::class)],
            'kind' => ['required', Rule::enum(DeadlineKind::class)],
            'agency_name' => ['nullable', 'string', 'max:160'],
            'tax_agency_id' => ['nullable', 'integer', Rule::exists('tax_agencies', 'id')->where('company_id', $company->id)],
            'account_reference' => ['nullable', 'string', 'max:120'],
            'portal_url' => ['nullable', 'string', 'max:500', 'url:http,https'],
            'instructions' => ['nullable', 'string', 'max:5000'],
            'expected_amount' => ['nullable', 'string', 'max:30'],
            'assigned_user_id' => ['nullable', 'integer'],
            'notify_days_before' => ['array', 'max:6'],
            'notify_days_before.*' => ['integer', 'min:0', 'max:90'],
            'frequency' => ['required', Rule::enum(DeadlineFrequency::class)],
            'interval' => ['nullable', 'integer', 'min:1', 'max:52'],
            'period_anchor' => ['nullable', 'date_format:Y-m-d'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'track_from' => ['nullable', 'date_format:Y-m-d'],
            'annual_dates' => ['array', 'max:24'],
            'annual_dates.*.month' => ['required', 'integer', 'min:1', 'max:12'],
            'annual_dates.*.day' => ['required'],
            'due_months_after' => ['nullable', 'integer', 'min:0', 'max:24'],
            'due_day_rule' => ['required', Rule::enum(DueDayRule::class)],
            'due_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'due_days_after' => ['nullable', 'integer', 'min:0', 'max:366'],
            'weekend_adjustment' => ['required', Rule::enum(WeekendAdjustment::class)],
            'end_type' => ['required', Rule::enum(RecurrenceEndType::class)],
            'end_date' => ['nullable', 'date_format:Y-m-d'],
            'max_occurrences' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'template_key' => ['nullable', 'string', 'max:80'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validated(Company $company, array $data): array
    {
        $data = $this->blanksToNull($data);

        $validator = Validator::make($data, self::rules($company), [], [
            'period_anchor' => __('first period starts'),
            'due_date' => __('due date'),
            'due_day_of_month' => __('day of the month'),
            'assigned_user_id' => __('assigned to'),
        ]);

        $validator->after(function ($validator) use ($company, $data): void {
            $frequency = DeadlineFrequency::tryFrom((string) ($data['frequency'] ?? ''));
            $dayRule = DueDayRule::tryFrom((string) ($data['due_day_rule'] ?? ''));
            $endType = RecurrenceEndType::tryFrom((string) ($data['end_type'] ?? ''));

            if ($frequency === DeadlineFrequency::Once && empty($data['due_date'])) {
                $validator->errors()->add('due_date', __('Enter the due date.'));
            }

            if ($frequency?->hasPeriods() && empty($data['period_anchor'])) {
                $validator->errors()->add('period_anchor', __('Enter the date the first period starts.'));
            }

            if ($frequency === DeadlineFrequency::AnnualDates) {
                if (DeadlineRule::normalizeAnnualDates($data['annual_dates'] ?? []) === []) {
                    $validator->errors()->add('annual_dates', __('Add at least one date.'));
                }

                if (empty($data['period_anchor'])) {
                    $validator->errors()->add('period_anchor', __('Enter the date to start from.'));
                }
            }

            if ($frequency?->hasPeriods() && $dayRule === DueDayRule::DayOfMonth && empty($data['due_day_of_month'])) {
                $validator->errors()->add('due_day_of_month', __('Enter the day of the month.'));
            }

            if ($endType === RecurrenceEndType::OnDate && empty($data['end_date'])) {
                $validator->errors()->add('end_date', __('Enter the end date.'));
            }

            if ($endType === RecurrenceEndType::AfterOccurrences && empty($data['max_occurrences'])) {
                $validator->errors()->add('max_occurrences', __('Enter how many times it repeats.'));
            }

            if (! empty($data['expected_amount']) && Money::tryFromString((string) $data['expected_amount']) === null) {
                $validator->errors()->add('expected_amount', __('Enter an amount like 1,234.56.'));
            }

            if (! empty($data['assigned_user_id']) && ! DeadlineAssignees::isEligible($company, (int) $data['assigned_user_id'])) {
                $validator->errors()->add('assigned_user_id', __('Choose someone in this organization who can open and complete deadlines.'));
            }
        });

        $valid = $validator->validate();
        $frequency = DeadlineFrequency::from($valid['frequency']);
        $dayRule = DueDayRule::from($valid['due_day_rule']);
        $endType = RecurrenceEndType::from($valid['end_type']);

        $notify = array_values(array_unique(array_map('intval', $valid['notify_days_before'] ?? [])));
        rsort($notify);

        return [
            'title' => trim((string) $valid['title']),
            'category' => DeadlineCategory::from($valid['category']),
            'kind' => DeadlineKind::from($valid['kind']),
            'agency_name' => $valid['agency_name'] ?? null,
            'tax_agency_id' => $valid['tax_agency_id'] ?? null,
            'account_reference' => $valid['account_reference'] ?? null,
            'portal_url' => $valid['portal_url'] ?? null,
            'instructions' => $valid['instructions'] ?? null,
            'expected_amount_cents' => empty($valid['expected_amount'])
                ? null
                : Money::fromString((string) $valid['expected_amount'], (string) $company->currency_code)->cents,
            'assigned_user_id' => $valid['assigned_user_id'] ?? null,
            'notify_days_before' => $notify,
            'frequency' => $frequency,
            'interval' => $frequency->takesInterval() ? (int) ($valid['interval'] ?? 1) : 1,
            'period_anchor' => $frequency === DeadlineFrequency::Once ? null : ($valid['period_anchor'] ?? null),
            'due_date' => $frequency === DeadlineFrequency::Once ? $valid['due_date'] : null,
            'annual_dates' => $frequency === DeadlineFrequency::AnnualDates
                ? DeadlineRule::normalizeAnnualDates($valid['annual_dates'] ?? [])
                : null,
            'due_months_after' => $frequency->hasPeriods() ? (int) ($valid['due_months_after'] ?? 0) : 0,
            'due_day_rule' => $frequency->hasPeriods() ? $dayRule : DueDayRule::SameDay,
            'due_day_of_month' => $frequency->hasPeriods() && $dayRule === DueDayRule::DayOfMonth
                ? (int) $valid['due_day_of_month']
                : null,
            'due_days_after' => $frequency->hasPeriods() ? (int) ($valid['due_days_after'] ?? 0) : 0,
            'weekend_adjustment' => WeekendAdjustment::from($valid['weekend_adjustment']),
            'end_type' => $frequency === DeadlineFrequency::Once ? RecurrenceEndType::Never : $endType,
            'end_date' => $endType === RecurrenceEndType::OnDate ? $valid['end_date'] : null,
            'max_occurrences' => $endType === RecurrenceEndType::AfterOccurrences ? (int) $valid['max_occurrences'] : null,
            'template_key' => $valid['template_key'] ?? null,
        ] + (isset($valid['track_from']) ? ['track_from' => $valid['track_from']] : []);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function blanksToNull(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $value = trim($value);
                $data[$key] = $value === '' ? null : $value;
            }
        }

        return $data;
    }
}
