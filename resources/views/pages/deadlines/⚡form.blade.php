<?php

use App\Actions\Deadlines\SaveDeadline;
use App\Enums\DeadlineCategory;
use App\Enums\DeadlineFrequency;
use App\Enums\DeadlineKind;
use App\Enums\DueDayRule;
use App\Enums\RecurrenceEndType;
use App\Enums\WeekendAdjustment;
use App\Livewire\Concerns\GuardsEditLockedForm;
use App\Models\Company;
use App\Models\Deadline;
use App\Models\TaxAgency;
use App\Models\User;
use App\Services\Deadlines\DeadlineAssignees;
use App\Services\Deadlines\DeadlineRule;
use App\Services\Deadlines\DeadlineRuleDescriber;
use App\Services\Deadlines\DeadlineScheduleCalculator;
use App\Services\Deadlines\ScheduledOccurrence;
use App\Support\Deadlines\DeadlineDates;
use App\Support\Money;
use App\Support\Tax\TaxAuthorityCatalog;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Deadline')] class extends Component {
    use GuardsEditLockedForm;

    /** Lead days offered as quick picks. */
    public const array LEAD_DAY_CHOICES = [30, 14, 7, 3, 1, 0];

    public Company $company;

    public ?Deadline $deadline = null;

    public string $title = '';

    public string $category = 'sales_tax';

    public string $kind = 'file_and_pay';

    public string $agency_name = '';

    public ?int $tax_agency_id = null;

    public string $account_reference = '';

    public string $portal_url = '';

    public string $instructions = '';

    public string $expected_amount = '';

    public ?int $assigned_user_id = null;

    /** @var list<int> */
    public array $notify_days_before = [7, 1];

    public string $frequency = 'quarterly';

    public int $interval = 1;

    public string $period_anchor = '';

    public string $due_date = '';

    public string $track_from = '';

    /** @var list<array{month: int|string, day: int|string}> */
    public array $annual_dates = [];

    public int $due_months_after = 1;

    public string $due_day_rule = 'last_day';

    public ?int $due_day_of_month = 15;

    public int $due_days_after = 0;

    public string $weekend_adjustment = 'keep';

    public string $end_type = 'never';

    public string $end_date = '';

    public ?int $max_occurrences = null;

    protected function editLockRecord(): ?Model
    {
        return $this->deadline;
    }

    public function mount(Company $company, ?Deadline $deadline = null): void
    {
        $this->company = $company;
        $today = $company->currentDateTime();

        if ($deadline?->exists) {
            abort_unless($deadline->company_id === $company->id, 404);
            $this->deadline = $deadline;
            $this->fillFrom($deadline);

            return;
        }

        $this->deadline = null;
        $this->period_anchor = $today->startOfMonth()->toDateString();
        $this->due_date = $today->addMonth()->toDateString();
        $this->track_from = $today->toDateString();
        $this->annual_dates = [['month' => (int) $today->month, 'day' => 15]];
        $this->assigned_user_id = DeadlineAssignees::isEligible($company, auth()->id()) ? auth()->id() : null;

        $copy = request()->integer('copy');

        if ($copy > 0 && ($source = Deadline::query()->find($copy)) !== null) {
            $this->fillFrom($source);
            $this->title = __(':title (copy)', ['title' => $source->title]);
            $this->track_from = $today->toDateString();
        }
    }

    public function toggleLeadDay(int $days): void
    {
        if (! in_array($days, self::LEAD_DAY_CHOICES, true)) {
            return;
        }

        $current = collect($this->notify_days_before)->map(fn ($d) => (int) $d);

        $this->notify_days_before = ($current->contains($days) ? $current->reject(fn ($d) => $d === $days) : $current->push($days))
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    public function addAnnualDate(): void
    {
        if (count($this->annual_dates) < 24) {
            $this->annual_dates[] = ['month' => 1, 'day' => 15];
        }
    }

    public function removeAnnualDate(int $index): void
    {
        unset($this->annual_dates[$index]);
        $this->annual_dates = array_values($this->annual_dates);
    }

    public function save(SaveDeadline $action)
    {
        $deadline = $action->handle($this->company, $this->payload(), $this->deadline);

        Flux::toast(variant: 'success', text: __('Deadline saved.'));

        $this->redirectRoute('deadlines.show', ['company' => $this->company, 'deadline' => $deadline], navigate: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'title' => $this->title,
            'category' => $this->category,
            'kind' => $this->kind,
            'agency_name' => $this->agency_name,
            'tax_agency_id' => $this->tax_agency_id ?: null,
            'account_reference' => $this->account_reference,
            'portal_url' => $this->portal_url,
            'instructions' => $this->instructions,
            'expected_amount' => $this->expected_amount,
            'assigned_user_id' => $this->assigned_user_id ?: null,
            'notify_days_before' => array_values(array_map('intval', $this->notify_days_before)),
            'frequency' => $this->frequency,
            'interval' => $this->interval,
            'period_anchor' => $this->period_anchor,
            'due_date' => $this->due_date,
            'track_from' => $this->track_from,
            'annual_dates' => $this->normalizedAnnualDates(),
            'due_months_after' => $this->due_months_after,
            'due_day_rule' => $this->due_day_rule,
            'due_day_of_month' => $this->due_day_of_month,
            'due_days_after' => $this->due_days_after,
            'weekend_adjustment' => $this->weekend_adjustment,
            'end_type' => $this->end_type,
            'end_date' => $this->end_date,
            'max_occurrences' => $this->max_occurrences,
            'template_key' => $this->deadline?->template_key,
        ];
    }

    /**
     * @return list<array{month: int, day: int|string}>
     */
    private function normalizedAnnualDates(): array
    {
        return array_values(array_map(fn (array $d): array => [
            'month' => (int) ($d['month'] ?? 0),
            'day' => ($d['day'] ?? '') === 'last' ? 'last' : (int) ($d['day'] ?? 0),
        ], $this->annual_dates));
    }

    /**
     * The rule as currently entered, or null when it's incomplete.
     */
    #[Computed]
    public function draftRule(): ?DeadlineRule
    {
        $frequency = DeadlineFrequency::tryFrom($this->frequency);
        $dayRule = DueDayRule::tryFrom($this->due_day_rule);
        $weekend = WeekendAdjustment::tryFrom($this->weekend_adjustment);
        $endType = RecurrenceEndType::tryFrom($this->end_type);

        if ($frequency === null || $dayRule === null || $weekend === null || $endType === null) {
            return null;
        }

        return new DeadlineRule(
            frequency: $frequency,
            interval: max(1, min(52, $this->interval)),
            periodAnchor: $this->date($this->period_anchor),
            dueDate: $this->date($this->due_date),
            annualDates: DeadlineRule::normalizeAnnualDates($this->normalizedAnnualDates()),
            dueMonthsAfter: max(0, min(24, $this->due_months_after)),
            dueDayRule: $dayRule,
            dueDayOfMonth: $this->due_day_of_month,
            dueDaysAfter: max(0, min(366, $this->due_days_after)),
            weekendAdjustment: $weekend,
            endType: $endType,
            endDate: $this->date($this->end_date),
            maxOccurrences: $this->max_occurrences,
        );
    }

    /**
     * The next six occurrences the rule produces, from "track from".
     *
     * @return list<ScheduledOccurrence>
     */
    #[Computed]
    public function preview(): array
    {
        $rule = $this->draftRule;

        if ($rule === null) {
            return [];
        }

        $from = $this->date($this->track_from) ?? $this->company->currentDateTime()->startOfDay();

        return app(DeadlineScheduleCalculator::class)->upcoming($rule, $from, 6);
    }

    #[Computed]
    public function ruleSentence(): string
    {
        return $this->draftRule ? DeadlineRuleDescriber::describe($this->draftRule) : '';
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignees(): Collection
    {
        return DeadlineAssignees::for($this->company);
    }

    /**
     * @return Collection<int, TaxAgency>
     */
    #[Computed]
    public function taxAgencies(): Collection
    {
        return TaxAgency::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Agency names to suggest while typing.
     *
     * @return list<string>
     */
    #[Computed]
    public function agencyNames(): array
    {
        $names = collect(TaxAuthorityCatalog::forCompany($this->company))
            ->pluck('name')
            ->merge($this->taxAgencies->pluck('name'))
            ->merge(Deadline::query()->whereNotNull('agency_name')->distinct()->pluck('agency_name'));

        return $names->unique()->sort()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function monthOptions(): array
    {
        $options = [];

        for ($m = 1; $m <= 12; $m++) {
            $options[$m] = DeadlineDates::monthName(CarbonImmutable::create(2001, $m, 1));
        }

        return $options;
    }

    private function date(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fillFrom(Deadline $deadline): void
    {
        $this->title = $deadline->title;
        $this->category = $deadline->category->value;
        $this->kind = $deadline->kind->value;
        $this->agency_name = (string) $deadline->agency_name;
        $this->tax_agency_id = $deadline->tax_agency_id;
        $this->account_reference = (string) $deadline->account_reference;
        $this->portal_url = (string) $deadline->portal_url;
        $this->instructions = (string) $deadline->instructions;
        $this->expected_amount = $deadline->expected_amount_cents !== null
            ? Money::fromCents($deadline->expected_amount_cents)->toDecimalString()
            : '';
        $this->assigned_user_id = $deadline->assigned_user_id;
        $this->notify_days_before = $deadline->leadDays();
        $this->frequency = $deadline->frequency->value;
        $this->interval = (int) $deadline->interval;
        $this->period_anchor = (string) $deadline->period_anchor?->toDateString();
        $this->due_date = (string) $deadline->due_date?->toDateString();
        $this->track_from = (string) $deadline->track_from?->toDateString();
        $this->annual_dates = $deadline->annual_dates ?? [];
        $this->due_months_after = (int) $deadline->due_months_after;
        $this->due_day_rule = $deadline->due_day_rule->value;
        $this->due_day_of_month = $deadline->due_day_of_month ?? 15;
        $this->due_days_after = (int) $deadline->due_days_after;
        $this->weekend_adjustment = $deadline->weekend_adjustment->value;
        $this->end_type = $deadline->end_type->value;
        $this->end_date = (string) $deadline->end_date?->toDateString();
        $this->max_occurrences = $deadline->max_occurrences;
    }
}; ?>

<section class="mx-auto w-full max-w-3xl">
    @if ($editLockBlocked) <x-edit-lock.blocked :lock="$this->editLockView" /> @else
    <x-edit-lock.status :lock="$this->editLockView" />

    <flux:heading size="xl" level="1" data-test="page-title">{{ $deadline?->exists ? __('Edit deadline') : __('New deadline') }}</flux:heading>
    <flux:subheading class="mb-6">{{ __('What is due, how often, and who looks after it.') }}</flux:subheading>

    @php($frequencyEnum = DeadlineFrequency::tryFrom($frequency))

    <form wire:submit="save" class="space-y-8">
        {{-- What --}}
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('What’s due') }}</flux:heading>

            <flux:input wire:model="title" :label="__('Name')" :placeholder="__('e.g. GST/HST return')" required data-test="deadline-title" />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:select wire:model="category" :label="__('Category')" data-test="deadline-category">
                    @foreach (DeadlineCategory::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="kind" :label="__('What has to happen')" data-test="deadline-kind">
                    @foreach (DeadlineKind::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <flux:input wire:model="agency_name" :label="__('Agency or payee')" list="deadline-agency-names" :placeholder="__('e.g. Canada Revenue Agency')" data-test="deadline-agency" />
                    <datalist id="deadline-agency-names">
                        @foreach ($this->agencyNames as $name)
                            <option value="{{ $name }}"></option>
                        @endforeach
                    </datalist>
                </div>
                <flux:input wire:model="account_reference" :label="__('Account or reference number')" :placeholder="__('e.g. 123456789 RT0001')" data-test="deadline-reference" />
            </div>

            @if ($this->taxAgencies->isNotEmpty())
                <flux:select wire:model="tax_agency_id" :label="__('Linked tax agency')" :description="__('Optional. Ties this deadline to one of your sales-tax agencies.')" data-test="deadline-tax-agency">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->taxAgencies as $agency)
                        <flux:select.option value="{{ $agency->id }}">{{ $agency->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input wire:model="portal_url" type="url" :label="__('Filing site')" placeholder="https://" data-test="deadline-portal" />
                @if (DeadlineKind::tryFrom($kind)?->involvesPayment())
                    <flux:input wire:model="expected_amount" :label="__('Usual amount')" :description="__('Optional, e.g. a fixed instalment.')" placeholder="0.00" inputmode="decimal" data-test="deadline-expected-amount" />
                @endif
            </div>

            <flux:textarea
                wire:model="instructions"
                :label="__('How to do it')"
                :description="__('Steps, reports to run, where to find the numbers. Don’t store passwords here.')"
                rows="3"
                data-test="deadline-instructions"
            />
        </div>

        {{-- When --}}
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('When it’s due') }}</flux:heading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:select wire:model.live="frequency" :label="__('Repeats')" data-test="deadline-frequency">
                    @foreach (DeadlineFrequency::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($frequencyEnum?->takesInterval())
                    <flux:input type="number" min="1" max="52" wire:model.live.debounce.400ms="interval" :label="__('Every')"
                        :description="$frequencyEnum === DeadlineFrequency::Weekly ? __('weeks') : __('periods')" data-test="deadline-interval" />
                @endif

                @if ($frequencyEnum === DeadlineFrequency::Once)
                    <flux:input type="date" wire:model.live="due_date" :label="__('Due date')" required data-test="deadline-due-date" />
                @else
                    <flux:input type="date" wire:model.live="period_anchor"
                        :label="$frequencyEnum === DeadlineFrequency::AnnualDates ? __('Starting from') : __('First period starts')"
                        :description="$frequencyEnum === DeadlineFrequency::AnnualDates ? null : __('Usually the start of your fiscal year.')"
                        required data-test="deadline-period-anchor" />
                @endif
            </div>

            @if ($frequencyEnum === DeadlineFrequency::AnnualDates)
                <div class="space-y-2" data-test="deadline-annual-dates">
                    <flux:label>{{ __('Dates each year') }}</flux:label>
                    @foreach ($annual_dates as $i => $date)
                        <div class="flex items-end gap-2" wire:key="annual-{{ $i }}">
                            <flux:select wire:model.live="annual_dates.{{ $i }}.month" class="max-w-44" :aria-label="__('Month')">
                                @foreach ($this->monthOptions as $m => $label)
                                    <flux:select.option value="{{ $m }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:select wire:model.live="annual_dates.{{ $i }}.day" class="max-w-36" :aria-label="__('Day')">
                                @for ($d = 1; $d <= 31; $d++)
                                    <flux:select.option value="{{ $d }}">{{ $d }}</flux:select.option>
                                @endfor
                                <flux:select.option value="last">{{ __('Last day') }}</flux:select.option>
                            </flux:select>
                            <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeAnnualDate({{ $i }})" :aria-label="__('Remove date')" />
                        </div>
                    @endforeach
                    <flux:button variant="ghost" size="sm" icon="plus" wire:click="addAnnualDate" data-test="deadline-add-annual-date">{{ __('Add a date') }}</flux:button>
                    <flux:error name="annual_dates" />
                </div>
            @endif

            @if ($frequencyEnum?->hasPeriods())
                <div class="rounded-lg border border-border p-4">
                    <p class="mb-3 text-sm font-medium">{{ __('Each period is due…') }}</p>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:input type="number" min="0" max="24" wire:model.live.debounce.400ms="due_months_after" :label="__('Months after it ends')" data-test="deadline-due-months" />
                        <flux:select wire:model.live="due_day_rule" :label="__('On')" data-test="deadline-due-day-rule">
                            @foreach (DueDayRule::options() as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @if ($due_day_rule === DueDayRule::DayOfMonth->value)
                            <flux:input type="number" min="1" max="31" wire:model.live.debounce.400ms="due_day_of_month" :label="__('Day of the month')" data-test="deadline-due-day" />
                        @endif
                    </div>
                    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <flux:input type="number" min="0" max="366" wire:model.live.debounce.400ms="due_days_after" :label="__('Plus days')" :description="__('e.g. 7 for UK VAT')" data-test="deadline-due-days" />
                    </div>
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:select wire:model.live="weekend_adjustment" :label="__('On a weekend')" :description="__('Statutory holidays aren’t known.')" data-test="deadline-weekend">
                    @foreach (WeekendAdjustment::options() as $value => $label)
                        <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($frequencyEnum !== DeadlineFrequency::Once)
                    <flux:select wire:model.live="end_type" :label="__('Ends')" data-test="deadline-end-type">
                        @foreach (RecurrenceEndType::cases() as $case)
                            <flux:select.option value="{{ $case->value }}">{{ $case->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @if ($end_type === RecurrenceEndType::OnDate->value)
                        <flux:input type="date" wire:model.live="end_date" :label="__('No deadlines after')" data-test="deadline-end-date" />
                    @elseif ($end_type === RecurrenceEndType::AfterOccurrences->value)
                        <flux:input type="number" min="1" max="1000" wire:model.live.debounce.400ms="max_occurrences" :label="__('Number of times')" data-test="deadline-max-occurrences" />
                    @endif
                @endif
            </div>

            <flux:input type="date" wire:model.live="track_from" class="max-w-xs" :label="__('Track from')"
                :description="__('Earlier due dates aren’t added. Set an earlier date to record past filings.')" data-test="deadline-track-from" />

            <div class="rounded-lg bg-muted p-4" data-test="deadline-preview">
                @if ($this->ruleSentence !== '')
                    <p class="mb-2 text-sm">{{ $this->ruleSentence }}</p>
                @endif
                @if ($this->preview === [])
                    <flux:text class="text-sm">{{ __('Fill in the schedule to see the due dates.') }}</flux:text>
                @else
                    <p class="mb-1 text-xs font-medium text-muted-foreground">{{ __('Next due dates') }}</p>
                    <ul class="space-y-0.5 text-sm">
                        @foreach ($this->preview as $occurrence)
                            <li class="flex flex-wrap justify-between gap-2" data-test="deadline-preview-row">
                                <span class="text-muted-foreground">
                                    @if ($occurrence->periodStart && $occurrence->periodEnd)
                                        {{ DeadlineDates::medium($occurrence->periodStart) }} – {{ DeadlineDates::medium($occurrence->periodEnd) }}
                                    @endif
                                </span>
                                <span class="font-medium tabular-nums">{{ DeadlineDates::mediumWithWeekday($occurrence->dueDate) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        {{-- Who --}}
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Who and reminders') }}</flux:heading>

            <flux:select wire:model="assigned_user_id" :label="__('Assigned to')" :description="__('People who can open and complete deadlines.')" data-test="deadline-assignee">
                <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                @foreach ($this->assignees as $member)
                    <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div>
                <flux:label>{{ __('Email the assignee') }}</flux:label>
                <div class="mt-2 flex flex-wrap gap-2" data-test="deadline-lead-days">
                    @foreach ($this::LEAD_DAY_CHOICES as $days)
                        @php($on = in_array($days, array_map('intval', $notify_days_before), true))
                        <button type="button" wire:click="toggleLeadDay({{ $days }})"
                            @class([
                                'rounded-full border px-3 py-1 text-sm transition',
                                'border-accent bg-accent text-accent-foreground' => $on,
                                'border-border text-muted-foreground hover:text-foreground' => ! $on,
                            ])
                            aria-pressed="{{ $on ? 'true' : 'false' }}"
                            data-test="deadline-lead-{{ $days }}"
                        >{{ $days === 0 ? __('On the day') : trans_choice(':count day before|:count days before', $days, ['count' => $days]) }}</button>
                    @endforeach
                </div>
                <flux:text class="mt-1 text-xs">{{ __('And again if it goes overdue. Each person can turn these off in their email settings.') }}</flux:text>
                <flux:error name="notify_days_before" />
            </div>
        </div>

        <div class="flex justify-end gap-2">
            <flux:button variant="ghost" :href="$deadline?->exists ? route('deadlines.show', ['company' => $company, 'deadline' => $deadline]) : route('deadlines.index', ['company' => $company])" wire:navigate>{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" type="submit" data-test="deadline-save">{{ __('Save deadline') }}</flux:button>
        </div>
    </form>
    @endif
</section>
