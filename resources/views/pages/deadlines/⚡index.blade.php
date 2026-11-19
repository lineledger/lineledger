<?php

use App\Enums\Activity;
use App\Enums\DeadlineCategory;
use App\Enums\DeadlineDigestFrequency;
use App\Enums\DeadlineState;
use App\Enums\DeadlineStatus;
use App\Enums\PermissionAction;
use App\Livewire\Attributes\PermissionExempt;
use App\Models\Company;
use App\Models\Deadline;
use App\Models\DeadlineNotificationSetting;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use App\Services\Deadlines\DeadlineFeed;
use App\Services\Deadlines\DeadlineRuleDescriber;
use App\Support\Access\CurrentViewer;
use App\Support\Deadlines\DeadlineDates;
use App\Support\Money;
use App\Support\Reporting\ReportDatePresets;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Deadlines')] class extends Component {
    public const array VIEWS = ['calendar', 'list', 'year', 'all'];

    public Company $company;

    #[Url(as: 'view')]
    public string $view = 'calendar';

    /** The calendar month, 'Y-m'. */
    #[Url(as: 'month')]
    public string $month = '';

    /** The year tracker's fiscal year, by the calendar year it starts in. */
    #[Url(as: 'fy')]
    public ?int $fiscalYear = null;

    /** "me", "all", "unassigned" or a member id. */
    #[Url(as: 'assignee')]
    public string $assignee = 'all';

    #[Url(as: 'category')]
    public string $category = '';

    /** The list view's status filter: open, done, skipped or all. */
    #[Url(as: 'status')]
    public string $status = 'open';

    /** Show archived deadlines on the "All deadlines" tab. */
    #[Url(as: 'archived')]
    public bool $showArchived = false;

    /** An occurrence to open on arrival (links in emails and on the dashboard). */
    #[Url(as: 'occurrence')]
    public ?int $occurrence = null;

    /** The calendar day open in the day panel, 'Y-m-d'. */
    public ?string $dayDate = null;

    public bool $emailReminders = true;

    public string $digest = 'weekly';

    public function mount(Company $company): void
    {
        $this->company = $company;
        $today = $company->currentDateTime();

        if (! in_array($this->view, self::VIEWS, true)) {
            $this->view = 'calendar';
        }

        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            $this->month = $today->format('Y-m');
        }

        $this->fiscalYear ??= ReportDatePresets::fiscalYearStart($today, $this->fiscalStartMonth())->year;

        $setting = DeadlineNotificationSetting::query()->where('user_id', Auth::id())->first();
        $this->emailReminders = $setting?->email_reminders ?? true;
        $this->digest = ($setting?->digest ?? DeadlineDigestFrequency::Weekly)->value;
    }

    public function setView(string $view): void
    {
        if (in_array($view, self::VIEWS, true)) {
            $this->view = $view;
        }
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonthNoOverflow()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonthNoOverflow()->format('Y-m');
    }

    public function currentMonth(): void
    {
        $this->month = $this->company->currentDateTime()->format('Y-m');
    }

    public function previousYear(): void
    {
        $this->fiscalYear = (int) $this->fiscalYear - 1;
    }

    public function nextYear(): void
    {
        $this->fiscalYear = (int) $this->fiscalYear + 1;
    }

    public function openDay(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $this->dayDate = $date;
        Flux::modal('deadline-day')->show();
    }

    /**
     * The occurrence dialog changed something; computed lists rebuild on render.
     */
    #[On('deadline-occurrence-changed')]
    public function refreshOccurrences(): void
    {
        unset($this->calendarOccurrences, $this->listGroups, $this->yearPanels, $this->deadlines);
    }

    #[PermissionExempt('a member’s own deadline email preferences')]
    public function saveEmailSettings(): void
    {
        $this->validate([
            'emailReminders' => ['boolean'],
            'digest' => ['required', Rule::enum(DeadlineDigestFrequency::class)],
        ]);

        $setting = DeadlineNotificationSetting::query()->firstOrNew(['user_id' => Auth::id()]);
        $setting->company_id = $this->company->id;
        $setting->email_reminders = $this->emailReminders;
        $setting->digest = DeadlineDigestFrequency::from($this->digest);
        $setting->save();

        Flux::modal('deadline-email-settings')->close();
        Flux::toast(variant: 'success', text: __('Email settings saved.'));
    }

    #[Computed]
    public function today(): CarbonImmutable
    {
        return DeadlineFeed::dateOf($this->company);
    }

    #[Computed]
    public function canManage(): bool
    {
        return CurrentViewer::permits(Activity::Deadlines, PermissionAction::Modify);
    }

    /**
     * @return array<string, string>
     */
    #[Computed]
    public function tabs(): array
    {
        return [
            'calendar' => __('Calendar'),
            'list' => __('List'),
            'year' => __('Year at a glance'),
            'all' => __('All deadlines'),
        ];
    }

    /**
     * Members for the assignee filter.
     *
     * @return Collection<int, User>
     */
    #[Computed]
    public function members(): Collection
    {
        return $this->company->members()->orderBy('name')->get(['users.id', 'users.name']);
    }

    /**
     * @return Collection<int, DeadlineOccurrence>
     */
    #[Computed]
    public function calendarOccurrences(): Collection
    {
        [$start, $end] = $this->gridRange();

        return $this->filtered()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<string, Collection<int, DeadlineOccurrence>>
     */
    #[Computed]
    public function occurrencesByDay(): Collection
    {
        return $this->calendarOccurrences->groupBy(fn (DeadlineOccurrence $o): string => $o->due_date->toDateString());
    }

    /**
     * @return list<array{date: string, day: int, inMonth: bool, isToday: bool}>
     */
    #[Computed]
    public function days(): array
    {
        $monthStart = $this->monthStart();
        [$cursor, $end] = $this->gridRange();
        $today = $this->today->toDateString();
        $days = [];

        while ($cursor->lte($end)) {
            $days[] = [
                'date' => $cursor->toDateString(),
                'day' => $cursor->day,
                'inMonth' => $cursor->isSameMonth($monthStart),
                'isToday' => $cursor->toDateString() === $today,
            ];
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    #[Computed]
    public function monthLabel(): string
    {
        return DeadlineDates::fullMonthYear($this->monthStart());
    }

    /**
     * The list view, grouped: overdue / this week / next 30 days / later for
     * open occurrences, or one list for the other statuses.
     *
     * @return list<array{key: string, label: string, rows: Collection<int, DeadlineOccurrence>}>
     */
    #[Computed]
    public function listGroups(): array
    {
        $today = $this->today;

        if ($this->status !== 'open') {
            $rows = $this->filtered()
                ->when($this->status !== 'all', fn (Builder $q) => $q->where('status', $this->status))
                ->when($this->status === 'all', fn (Builder $q) => $q->where('due_date', '<=', $today->addMonthsNoOverflow(3)->toDateString()))
                ->orderByDesc('due_date')
                ->orderByDesc('id')
                ->limit(200)
                ->get();

            return [['key' => $this->status, 'label' => '', 'rows' => $rows]];
        }

        $rows = $this->filtered()
            ->where('status', DeadlineStatus::Open->value)
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        $week = $today->addDays(DeadlineOccurrence::DUE_SOON_DAYS);
        $month = $today->addDays(30);

        $groups = [
            ['key' => 'overdue', 'label' => __('Overdue'), 'rows' => $rows->filter(fn ($o) => $o->due_date->lt($today))->values()],
            ['key' => 'week', 'label' => __('Due in the next 7 days'), 'rows' => $rows->filter(fn ($o) => $o->due_date->gte($today) && $o->due_date->lte($week))->values()],
            ['key' => 'month', 'label' => __('Due in the next 30 days'), 'rows' => $rows->filter(fn ($o) => $o->due_date->gt($week) && $o->due_date->lte($month))->values()],
            ['key' => 'later', 'label' => __('Later'), 'rows' => $rows->filter(fn ($o) => $o->due_date->gt($month))->values()],
        ];

        return array_values(array_filter($groups, fn (array $g): bool => $g['rows']->isNotEmpty()));
    }

    /**
     * The year tracker: one panel per deadline with its occurrences in the
     * fiscal year (by period start, or due date when there is no period).
     *
     * @return list<array{deadline: Deadline, rows: Collection<int, DeadlineOccurrence>}>
     */
    #[Computed]
    public function yearPanels(): array
    {
        [$start, $end] = $this->fiscalRange();

        return $this->filtered()
            ->whereBetween('occurrence_key', [$start->toDateString(), $end->toDateString()])
            ->orderBy('occurrence_key')
            ->get()
            ->groupBy('deadline_id')
            ->map(fn (Collection $rows): array => ['deadline' => $rows->first()->deadline, 'rows' => $rows->values()])
            ->sortBy(fn (array $panel): string => $panel['deadline']->category->value.'|'.mb_strtolower($panel['deadline']->title))
            ->values()
            ->all();
    }

    #[Computed]
    public function fiscalLabel(): string
    {
        [$start, $end] = $this->fiscalRange();

        return $start->year === $end->year
            ? (string) $start->year
            : DeadlineDates::monthYear($start).' – '.DeadlineDates::monthYear($end);
    }

    /**
     * Every deadline (the series), with its next open due date.
     *
     * @return Collection<int, Deadline>
     */
    #[Computed]
    public function deadlines(): Collection
    {
        $deadlines = Deadline::query()
            ->with('assignee')
            ->when(! $this->showArchived, fn (Builder $q) => $q->whereNull('archived_at'))
            ->when($this->category !== '', fn (Builder $q) => $q->where('category', $this->category))
            ->when($this->assignee !== DeadlineFeed::EVERYONE, fn (Builder $q) => match (true) {
                $this->assignee === DeadlineFeed::UNASSIGNED => $q->whereNull('assigned_user_id'),
                $this->assignee === DeadlineFeed::MINE => $q->where('assigned_user_id', Auth::id()),
                ctype_digit($this->assignee) => $q->where('assigned_user_id', (int) $this->assignee),
                default => $q,
            })
            ->orderBy('title')
            ->get();

        $next = DeadlineOccurrence::query()
            ->whereIn('deadline_id', $deadlines->pluck('id'))
            ->where('status', DeadlineStatus::Open->value)
            ->orderBy('due_date')
            ->get(['deadline_id', 'due_date'])
            ->groupBy('deadline_id')
            ->map(fn (Collection $rows) => $rows->first()->due_date);

        return $deadlines->each(fn (Deadline $deadline) => $deadline->setAttribute('next_due', $next->get($deadline->id)));
    }

    public function stateOf(DeadlineOccurrence $occurrence): DeadlineState
    {
        return $occurrence->stateOn($this->today);
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '' : Money::fromCents($cents, (string) $this->company->currency_code)->format();
    }

    public function describe(Deadline $deadline): string
    {
        return DeadlineRuleDescriber::describe($deadline);
    }

    /**
     * Occurrences of current deadlines with the assignee and category filters.
     *
     * @return Builder<DeadlineOccurrence>
     */
    private function filtered(): Builder
    {
        $feed = DeadlineFeed::for($this->company);
        $user = Auth::user();

        return $feed->assignedTo($feed->query(), $this->assignee, $user instanceof User ? $user : null)
            ->when($this->category !== '', fn (Builder $q) => $q->whereHas('deadline', fn (Builder $d) => $d->where('category', $this->category)));
    }

    private function fiscalStartMonth(): int
    {
        return max(1, min(12, (int) ($this->company->fiscal_year_start_month ?: 1)));
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function fiscalRange(): array
    {
        $year = (int) ($this->fiscalYear ?: $this->today->year);
        $start = CarbonImmutable::create($year, $this->fiscalStartMonth(), 1);

        return [$start, $start->addYear()->subDay()];
    }

    private function monthStart(): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            $this->month = $this->company->currentDateTime()->format('Y-m');
        }

        return CarbonImmutable::createFromFormat('!Y-m', $this->month, $this->company->timezone ?: 'UTC')->startOfDay();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function gridRange(): array
    {
        $monthStart = $this->monthStart();

        return [
            $monthStart->startOfWeek(CarbonInterface::SUNDAY),
            $monthStart->endOfMonth()->endOfWeek(CarbonInterface::SATURDAY),
        ];
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Deadlines') }}</flux:heading>
            <flux:subheading>{{ __('Filing and payment due dates: who looks after each one, and when it was done.') }}</flux:subheading>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <flux:button variant="ghost" size="sm" icon="envelope" x-on:click="$flux.modal('deadline-email-settings').show()" data-test="deadline-email-settings">
                {{ __('Email settings') }}
            </flux:button>
            @reaches('deadlines.suggestions')
                <flux:button variant="ghost" size="sm" icon="sparkles" :href="route('deadlines.suggestions', ['company' => $company])" wire:navigate data-test="deadline-suggestions-button">
                    {{ __('Add suggested deadlines') }}
                </flux:button>
            @endreaches
            @reaches('deadlines.create')
                <flux:button variant="primary" size="sm" icon="plus" :href="route('deadlines.create', ['company' => $company])" wire:navigate data-test="new-deadline-button">
                    {{ __('New deadline') }}
                </flux:button>
            @endreaches
        </div>
    </div>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-border" role="tablist" data-test="deadline-tabs">
        @foreach ($this->tabs as $key => $label)
            <button
                type="button"
                role="tab"
                wire:click="setView('{{ $key }}')"
                aria-selected="{{ $view === $key ? 'true' : 'false' }}"
                @class([
                    '-mb-px shrink-0 whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium transition',
                    'border-accent text-accent' => $view === $key,
                    'border-transparent text-muted-foreground hover:text-foreground' => $view !== $key,
                ])
                data-test="deadline-tab-{{ $key }}"
            >{{ $label }}</button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:select wire:model.live="assignee" class="max-w-56" data-test="deadline-filter-assignee">
            <flux:select.option value="all">{{ __('Everyone') }}</flux:select.option>
            <flux:select.option value="me">{{ __('Assigned to me') }}</flux:select.option>
            <flux:select.option value="unassigned">{{ __('Unassigned') }}</flux:select.option>
            @foreach ($this->members as $member)
                <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="category" class="max-w-60" data-test="deadline-filter-category">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach (DeadlineCategory::options() as $value => $label)
                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($view === 'list')
            <flux:select wire:model.live="status" class="max-w-48" data-test="deadline-filter-status">
                <flux:select.option value="open">{{ __('Still to do') }}</flux:select.option>
                <flux:select.option value="done">{{ __('Done') }}</flux:select.option>
                <flux:select.option value="skipped">{{ __('Not required') }}</flux:select.option>
                <flux:select.option value="all">{{ __('Everything') }}</flux:select.option>
            </flux:select>
        @endif

        @if ($view === 'all')
            <flux:switch wire:model.live="showArchived" :label="__('Show archived')" data-test="deadline-show-archived" />
        @endif
    </div>

    {{-- Calendar --}}
    @if ($view === 'calendar')
        <div class="rounded-lg border border-border" data-test="deadline-calendar">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-3">
                <div class="flex items-center gap-1">
                    <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" :aria-label="__('Previous month')" data-test="deadline-cal-prev" />
                    <flux:button variant="ghost" size="sm" wire:click="currentMonth">{{ __('Today') }}</flux:button>
                    <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" :aria-label="__('Next month')" data-test="deadline-cal-next" />
                    <flux:heading size="lg" class="ml-2" data-test="deadline-cal-month">{{ $this->monthLabel }}</flux:heading>
                </div>
                <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                    <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                    <flux:badge size="sm" color="amber">{{ __('Due soon') }}</flux:badge>
                    <flux:badge size="sm" color="zinc">{{ __('Upcoming') }}</flux:badge>
                    <flux:badge size="sm" color="green">{{ __('Done') }}</flux:badge>
                </div>
            </div>

            <div class="hidden grid-cols-7 border-b border-border bg-muted text-center text-xs font-medium text-muted-foreground sm:grid">
                @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $dow)
                    <div class="px-1 py-2">{{ $dow }}</div>
                @endforeach
            </div>

            {{-- Phones: the month as a list of days that have something due. --}}
            <ul class="divide-y divide-border sm:hidden" data-test="deadline-cal-agenda">
                @forelse ($this->occurrencesByDay as $date => $dayOccurrences)
                    @if (str_starts_with($date, $month))
                        <li class="p-3" wire:key="agenda-{{ $date }}">
                            <p class="mb-1 text-xs font-medium text-muted-foreground">{{ DeadlineDates::shortWithWeekday(CarbonImmutable::parse($date)) }}</p>
                            <div class="flex flex-col gap-1">
                                @foreach ($dayOccurrences as $o)
                                    @php($state = $this->stateOf($o))
                                    <button type="button" wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }} })" class="text-left">
                                        <flux:badge size="sm" :color="$state->color()" class="max-w-full truncate">{{ $o->deadline->title }}</flux:badge>
                                    </button>
                                @endforeach
                            </div>
                        </li>
                    @endif
                @empty
                    <li class="p-6 text-center text-sm text-muted-foreground">{{ __('Nothing due this month.') }}</li>
                @endforelse
            </ul>

            <div class="hidden grid-cols-7 sm:grid">
                @foreach ($this->days as $day)
                    @php($dayOccurrences = $this->occurrencesByDay[$day['date']] ?? collect())
                    <div
                        wire:key="dday-{{ $day['date'] }}"
                        class="flex min-h-28 flex-col items-start gap-1 border-b border-r border-border p-1.5 {{ $day['inMonth'] ? '' : 'bg-muted/40 text-muted-foreground' }}"
                        data-test="deadline-cal-day"
                        data-date="{{ $day['date'] }}"
                    >
                        <span @class([
                            'text-xs',
                            'flex size-5 items-center justify-center rounded-full bg-accent font-semibold text-accent-foreground' => $day['isToday'],
                        ])>{{ $day['day'] }}</span>

                        <span class="flex w-full flex-col gap-0.5">
                            @foreach ($dayOccurrences->take(3) as $o)
                                @php($state = $this->stateOf($o))
                                <button
                                    type="button"
                                    wire:key="chip-{{ $o->id }}"
                                    wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }} })"
                                    class="w-full text-left"
                                    title="{{ $o->deadline->title }}{{ $o->periodLabel() ? ' — '.$o->periodLabel() : '' }}"
                                    data-test="deadline-cal-chip"
                                    data-state="{{ $state->value }}"
                                >
                                    <flux:badge size="sm" :color="$state->color()" @class(['w-full truncate', 'line-through opacity-70' => $state === DeadlineState::Skipped])>
                                        {{ $o->deadline->title }}
                                    </flux:badge>
                                </button>
                            @endforeach
                            @if ($dayOccurrences->count() > 3)
                                <button type="button" wire:click="openDay('{{ $day['date'] }}')" class="text-left text-xs text-muted-foreground hover:underline" data-test="deadline-cal-more">
                                    {{ __('+:n more', ['n' => $dayOccurrences->count() - 3]) }}
                                </button>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        <flux:modal name="deadline-day" class="max-w-md">
            <div class="space-y-4">
                <flux:heading size="lg">{{ $dayDate ? DeadlineDates::long(CarbonImmutable::parse($dayDate)) : '' }}</flux:heading>
                <ul class="divide-y divide-border">
                    @foreach (($dayDate ? ($this->occurrencesByDay[$dayDate] ?? collect()) : collect()) as $o)
                        @php($state = $this->stateOf($o))
                        <li class="flex items-center justify-between gap-3 py-2">
                            <button type="button" class="min-w-0 text-left" x-on:click="$flux.modal('deadline-day').close(); $wire.$dispatch('open-deadline-occurrence', { id: {{ $o->id }} })">
                                <p class="truncate font-medium">{{ $o->deadline->title }}</p>
                                <p class="text-xs text-muted-foreground">{{ $o->periodLabel() }} · {{ $o->assignee?->name ?? __('Unassigned') }}</p>
                            </button>
                            <flux:badge size="sm" :color="$state->color()">{{ $state->label() }}</flux:badge>
                        </li>
                    @endforeach
                </ul>
            </div>
        </flux:modal>
    @endif

    {{-- List --}}
    @if ($view === 'list')
        <div class="space-y-6" data-test="deadline-list">
            @forelse ($this->listGroups as $group)
                <div wire:key="group-{{ $group['key'] }}" data-test="deadline-group-{{ $group['key'] }}">
                    @if ($group['label'] !== '')
                        <flux:heading size="sm" class="mb-2 {{ $group['key'] === 'overdue' ? 'text-red-600 dark:text-red-400' : '' }}">
                            {{ $group['label'] }} <span class="font-normal text-muted-foreground">({{ $group['rows']->count() }})</span>
                        </flux:heading>
                    @endif

                    {{-- Phones --}}
                    <div class="space-y-2 lg:hidden">
                        @foreach ($group['rows'] as $o)
                            @php($state = $this->stateOf($o))
                            <div class="rounded-lg border border-border p-3" wire:key="card-{{ $o->id }}" data-test="deadline-card">
                                <button type="button" class="block w-full text-left" wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }} })">
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="truncate font-medium">{{ $o->deadline->title }}</span>
                                        <flux:badge size="sm" :color="$state->color()">{{ $state->label() }}</flux:badge>
                                    </div>
                                    <p class="mt-1 text-xs text-muted-foreground">
                                        {{ __('Due :date', ['date' => DeadlineDates::mediumWithWeekday($o->due_date)]) }}
                                        @if ($o->periodLabel()) · {{ $o->periodLabel() }} @endif
                                        · {{ $o->assignee?->name ?? __('Unassigned') }}
                                    </p>
                                </button>
                                @if ($this->canManage && $o->isOpen())
                                    <flux:button size="xs" variant="ghost" icon="check" class="mt-2" wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }}, mode: 'complete' })">
                                        {{ __('Mark done') }}
                                    </flux:button>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    {{-- Desktop --}}
                    <div class="hidden overflow-x-auto rounded-lg border border-border lg:block">
                        <table class="w-full text-sm">
                            <thead class="bg-muted">
                                <tr>
                                    <th class="px-4 py-2 text-left">{{ __('Due') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Deadline') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Agency') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Assigned to') }}</th>
                                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                                    <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                                    <th class="px-4 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group['rows'] as $o)
                                    @php($state = $this->stateOf($o))
                                    <tr class="border-t border-border hover:bg-muted/50" wire:key="row-{{ $o->id }}" data-test="deadline-row">
                                        <td class="whitespace-nowrap px-4 py-2">{{ DeadlineDates::mediumWithWeekday($o->due_date) }}</td>
                                        <td class="px-4 py-2">
                                            <button type="button" class="text-left font-medium hover:underline" wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }} })" data-test="deadline-row-open">
                                                {{ $o->deadline->title }}
                                            </button>
                                            @if ($o->periodLabel())
                                                <span class="block text-xs text-muted-foreground">{{ $o->periodLabel() }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-2 text-muted-foreground">{{ $o->deadline->agency_name }}</td>
                                        <td class="px-4 py-2">{{ $o->assignee?->name ?? __('Unassigned') }}</td>
                                        <td class="px-4 py-2">
                                            <flux:badge size="sm" :color="$state->color()">{{ $state->label() }}</flux:badge>
                                            @if ($o->status === DeadlineStatus::Done)
                                                <span class="block text-xs text-muted-foreground">{{ DeadlineDates::short($o->completed_on) }} · {{ $o->completedBy?->name }}</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">
                                            {{ $this->money($o->amount_cents ?? $o->deadline->expected_amount_cents) }}
                                        </td>
                                        <td class="whitespace-nowrap px-4 py-2 text-right">
                                            @if ($this->canManage && $o->isOpen())
                                                <flux:button size="xs" variant="ghost" icon="check" wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }}, mode: 'complete' })" data-test="deadline-row-done">
                                                    {{ __('Mark done') }}
                                                </flux:button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground" data-test="deadline-list-empty">
                    {{ $status === 'open' ? __('Nothing to do. Every deadline is done or not yet due.') : __('No deadlines match.') }}
                </div>
            @endforelse
        </div>
    @endif

    {{-- Year at a glance --}}
    @if ($view === 'year')
        <div data-test="deadline-year">
            <div class="mb-4 flex items-center gap-1">
                <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousYear" :aria-label="__('Previous year')" data-test="deadline-year-prev" />
                <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextYear" :aria-label="__('Next year')" data-test="deadline-year-next" />
                <flux:heading size="lg" class="ml-2" data-test="deadline-year-label">{{ __('Fiscal year :label', ['label' => $this->fiscalLabel]) }}</flux:heading>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                @forelse ($this->yearPanels as $panel)
                    @php($deadline = $panel['deadline'])
                    <div class="overflow-hidden rounded-lg border border-border" wire:key="panel-{{ $deadline->id }}" data-test="deadline-year-panel">
                        <div class="flex items-start justify-between gap-2 border-b border-border bg-muted px-4 py-2">
                            <div class="min-w-0">
                                <a href="{{ route('deadlines.show', ['company' => $company, 'deadline' => $deadline->id]) }}" wire:navigate class="font-medium hover:underline">{{ $deadline->title }}</a>
                                <p class="truncate text-xs text-muted-foreground">{{ $deadline->agency_name }}</p>
                            </div>
                            <flux:badge size="sm" :color="$deadline->category->color()">{{ $deadline->category->label() }}</flux:badge>
                        </div>
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-xs text-muted-foreground">
                                    <th class="px-3 py-1.5 text-left font-medium">{{ __('Period') }}</th>
                                    <th class="px-3 py-1.5 text-left font-medium">{{ __('Due') }}</th>
                                    <th class="px-3 py-1.5 text-left font-medium">{{ __('Done') }}</th>
                                    <th class="px-3 py-1.5 text-right font-medium">{{ __('Amount') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($panel['rows'] as $o)
                                    @php($state = $this->stateOf($o))
                                    <tr class="cursor-pointer border-t border-border hover:bg-muted/50" wire:key="yrow-{{ $o->id }}" wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }} })" data-test="deadline-year-row" data-state="{{ $state->value }}">
                                        <td class="px-3 py-1.5">{{ $o->periodLabel() ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-1.5">{{ DeadlineDates::short($o->due_date) }}</td>
                                        <td @class([
                                            'px-3 py-1.5',
                                            'bg-green-50 text-green-800 dark:bg-green-950/40 dark:text-green-300' => $state === DeadlineState::Done,
                                            'bg-red-50 font-medium text-red-700 dark:bg-red-950/40 dark:text-red-300' => $state === DeadlineState::Overdue,
                                            'bg-amber-50 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' => $state === DeadlineState::DueSoon || $state === DeadlineState::DueToday,
                                            'text-muted-foreground' => $state === DeadlineState::Skipped || $state === DeadlineState::Upcoming,
                                        ])>
                                            @switch($state)
                                                @case(DeadlineState::Done)
                                                    {{ __('Done :date', ['date' => DeadlineDates::short($o->completed_on)]) }}@if ($o->completedBy) · {{ $o->completedBy->name }}@endif
                                                    @break
                                                @case(DeadlineState::Skipped)
                                                    {{ __('Not required') }}
                                                    @break
                                                @case(DeadlineState::Overdue)
                                                    {{ __('Overdue') }}
                                                    @break
                                                @case(DeadlineState::DueToday)
                                                @case(DeadlineState::DueSoon)
                                                    {{ $state->label() }}
                                                    @break
                                                @default
                                                    {{ $o->assignee?->name ?? '' }}
                                            @endswitch
                                            @if ($o->notes)
                                                <span class="block truncate text-xs opacity-80" title="{{ $o->notes }}">{{ $o->notes }}</span>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-1.5 text-right tabular-nums">{{ $this->money($o->amount_cents) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @empty
                    <div class="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground xl:col-span-2" data-test="deadline-year-empty">
                        {{ __('No deadlines fall in this fiscal year.') }}
                    </div>
                @endforelse
            </div>
        </div>
    @endif

    {{-- All deadlines (the series) --}}
    @if ($view === 'all')
        <div class="overflow-x-auto rounded-lg border border-border" data-test="deadline-series">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Deadline') }}</th>
                        <th class="hidden px-4 py-2 text-left md:table-cell">{{ __('Schedule') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Assigned to') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Next due') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->deadlines as $deadline)
                        <tr class="border-t border-border hover:bg-muted/50" wire:key="series-{{ $deadline->id }}" data-test="deadline-series-row">
                            <td class="px-4 py-2">
                                <a href="{{ route('deadlines.show', ['company' => $company, 'deadline' => $deadline->id]) }}" wire:navigate class="font-medium hover:underline">{{ $deadline->title }}</a>
                                <span class="block text-xs text-muted-foreground">{{ $deadline->agency_name }}</span>
                                @if ($deadline->isArchived())
                                    <flux:badge size="sm" color="zinc">{{ __('Archived') }}</flux:badge>
                                @elseif (! $deadline->is_active)
                                    <flux:badge size="sm" color="amber">{{ __('Paused') }}</flux:badge>
                                @endif
                            </td>
                            <td class="hidden px-4 py-2 text-muted-foreground md:table-cell">{{ $this->describe($deadline) }}</td>
                            <td class="px-4 py-2">{{ $deadline->assignee?->name ?? __('Unassigned') }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ DeadlineDates::medium($deadline->next_due) ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-4 py-8 text-center text-muted-foreground">
                                {{ __('No deadlines yet.') }}
                                @reaches('deadlines.suggestions')
                                    <a href="{{ route('deadlines.suggestions', ['company' => $company]) }}" wire:navigate class="text-accent hover:underline">{{ __('Start with the suggested ones.') }}</a>
                                @endreaches
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <livewire:pages::deadlines.occurrence-dialog :company="$company" :initial-id="$occurrence" />

    <flux:modal name="deadline-email-settings" class="max-w-md">
        <form wire:submit="saveEmailSettings" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ __('Deadline emails') }}</flux:heading>
                <flux:subheading>{{ __('Your own settings for :company.', ['company' => $company->name]) }}</flux:subheading>
            </div>

            <flux:switch wire:model="emailReminders" :label="__('Remind me before my deadlines are due')" :description="__('Sent on the days each deadline asks for, and again if it goes overdue.')" data-test="deadline-email-reminders" />

            <flux:select wire:model="digest" :label="__('Summary email')" :description="__('What’s overdue and what’s coming up: yours, plus unassigned ones if you can complete them.')" data-test="deadline-email-digest">
                @foreach (DeadlineDigestFrequency::options() as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="deadline-email-save">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
