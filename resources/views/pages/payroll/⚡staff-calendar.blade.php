<?php

use App\Actions\Payroll\DecideTimeOffRequest;
use App\Enums\TimeOffRequestStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff calendar')] class extends Component {
    public Company $company;

    /** The visible calendar month, 'Y-m'. */
    public string $month = '';

    /** The day whose requests are open in the day panel, 'Y-m-d'. */
    public ?string $dayDate = null;

    public ?int $filterEmployee = null;

    public ?int $filterPolicy = null;

    public bool $showPending = true;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->month = $company->currentDateTime()->format('Y-m');

        abort_unless($company->usesPayroll(), 404);
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    public function currentMonth(): void
    {
        $this->month = $this->company->currentDateTime()->format('Y-m');
    }

    public function openDay(string $date): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }

        $this->dayDate = $date;
        Flux::modal('staff-day')->show();
    }

    public function managerApprove(int $id, DecideTimeOffRequest $action): void
    {
        $action->managerApprove(TimeOffRequest::findOrFail($id), auth()->user());
        unset($this->requests);
        Flux::toast(variant: 'success', text: __('Absence approved — payroll confirmation is next.'));
    }

    public function approve(int $id, DecideTimeOffRequest $action): void
    {
        $action->approve(TimeOffRequest::findOrFail($id), auth()->user());
        unset($this->requests);
        Flux::toast(variant: 'success', text: __('Approved — time entries were scheduled.'));
    }

    public function deny(int $id, DecideTimeOffRequest $action): void
    {
        $action->deny(TimeOffRequest::findOrFail($id), auth()->user());
        unset($this->requests);
        Flux::toast(variant: 'success', text: __('Request denied.'));
    }

    /**
     * Requests overlapping the visible grid, honouring the filters.
     *
     * @return Collection<int, TimeOffRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        [$start, $end] = $this->gridRange();

        $statuses = $this->showPending
            ? [TimeOffRequestStatus::Approved->value, TimeOffRequestStatus::Pending->value, TimeOffRequestStatus::ManagerApproved->value]
            : [TimeOffRequestStatus::Approved->value];

        return TimeOffRequest::query()
            ->with(['employee', 'policy'])
            ->whereIn('status', $statuses)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->when($this->filterEmployee, fn ($q) => $q->where('contact_id', $this->filterEmployee))
            ->when($this->filterPolicy, fn ($q) => $q->where('time_off_policy_id', $this->filterPolicy))
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Requests expanded to per-day chips across the grid, keyed by Y-m-d.
     * Only working days get chips (requests cover Mon–Fri).
     *
     * @return Collection<string, Collection<int, TimeOffRequest>>
     */
    #[Computed]
    public function requestsByDay(): Collection
    {
        [$start, $end] = $this->gridRange();

        $byDay = [];

        foreach ($this->requests as $request) {
            foreach ($request->businessDays() as $date) {
                if ($date >= $start->toDateString() && $date <= $end->toDateString()) {
                    $byDay[$date][] = $request;
                }
            }
        }

        return collect($byDay)->map(fn (array $requests) => collect($requests));
    }

    /**
     * @return list<array{date: string, day: int, inMonth: bool, isToday: bool}>
     */
    #[Computed]
    public function days(): array
    {
        $monthStart = $this->monthStart();
        [$cursor, $end] = $this->gridRange();
        $today = $this->company->currentDateTime()->toDateString();

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
        return $this->monthStart()->format('F Y');
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function employees(): Collection
    {
        return Contact::query()->where('is_employee', true)->where('is_active', true)->orderBy('display_name')->get();
    }

    /**
     * @return Collection<int, TimeOffPolicy>
     */
    #[Computed]
    public function policies(): Collection
    {
        return TimeOffPolicy::query()->where('is_active', true)->orderBy('name')->get();
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
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Staff calendar') }}</flux:heading>
            <flux:subheading>{{ __('Who is away when — approved time off in solid colors, requests still in approval outlined.') }}</flux:subheading>
        </div>

        <flux:button variant="ghost" size="sm" icon="clipboard-document-check" :href="route('time-off-requests.index')" wire:navigate>
            {{ __('Time-off requests') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:select wire:model.live="filterEmployee" class="max-w-56">
            <flux:select.option value="">{{ __('All employees') }}</flux:select.option>
            @foreach ($this->employees as $employee)
                <flux:select.option value="{{ $employee->id }}">{{ $employee->display_name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterPolicy" class="max-w-48">
            <flux:select.option value="">{{ __('All types') }}</flux:select.option>
            @foreach ($this->policies as $policy)
                <flux:select.option value="{{ $policy->id }}">{{ $policy->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:switch wire:model.live="showPending" :label="__('Show pending')" data-test="show-pending-toggle" />
    </div>

    <div class="rounded-lg border border-border" data-test="staff-calendar">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-3">
            <div class="flex items-center gap-1">
                <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" data-test="staff-cal-prev" />
                <flux:button variant="ghost" size="sm" wire:click="currentMonth">{{ __('Today') }}</flux:button>
                <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" data-test="staff-cal-next" />
                <flux:heading size="lg" class="ml-2" data-test="staff-cal-month">{{ $this->monthLabel }}</flux:heading>
            </div>
        </div>

        <div class="grid grid-cols-7 border-b border-border bg-muted text-center text-xs font-medium text-muted-foreground">
            @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $dow)
                <div class="px-1 py-2">{{ $dow }}</div>
            @endforeach
        </div>

        <div class="grid grid-cols-7">
            @foreach ($this->days as $day)
                @php($dayRequests = $this->requestsByDay[$day['date']] ?? collect())
                <button
                    type="button"
                    wire:key="sday-{{ $day['date'] }}"
                    @if ($dayRequests->isNotEmpty()) wire:click="openDay('{{ $day['date'] }}')" @endif
                    class="flex min-h-24 flex-col items-start gap-1 border-b border-r border-border p-1.5 text-left transition {{ $dayRequests->isNotEmpty() ? 'hover:bg-muted' : 'cursor-default' }} {{ $day['inMonth'] ? '' : 'bg-muted/40 text-muted-foreground' }}"
                    data-test="staff-cal-day"
                    data-date="{{ $day['date'] }}"
                >
                    <span @class([
                        'text-xs',
                        'flex size-5 items-center justify-center rounded-full bg-accent font-semibold text-accent-foreground' => $day['isToday'],
                    ])>{{ $day['day'] }}</span>

                    <span class="flex w-full flex-col gap-0.5">
                        @foreach ($dayRequests->take(4) as $request)
                            @php($color = $request->policy?->category?->color() ?? 'zinc')
                            <flux:badge
                                size="sm"
                                :color="$request->status === \App\Enums\TimeOffRequestStatus::Approved ? $color : 'amber'"
                                class="max-w-full truncate {{ $request->status === \App\Enums\TimeOffRequestStatus::Approved ? '' : 'border border-dashed border-amber-400' }}"
                                data-test="staff-cal-chip"
                            >
                                {{ $request->employee?->display_name }}
                            </flux:badge>
                        @endforeach
                        @if ($dayRequests->count() > 4)
                            <span class="text-xs text-muted-foreground">{{ __('+:n more', ['n' => $dayRequests->count() - 4]) }}</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    </div>

    <flux:modal name="staff-day" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ $dayDate ? \Carbon\CarbonImmutable::parse($dayDate)->format('l, F j, Y') : '' }}
            </flux:heading>

            <ul class="divide-y divide-border" data-test="staff-day-list">
                @foreach (($dayDate ? ($this->requestsByDay[$dayDate] ?? collect()) : collect()) as $request)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ $request->employee?->display_name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ $request->policy?->name }} · {{ number_format((float) $request->hours_per_day, 2) }}h/{{ __('day') }}
                                · {{ $request->start_date->toDateString() }} → {{ $request->end_date->toDateString() }}
                            </p>
                            <flux:badge size="sm" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge>
                        </div>
                        <div class="shrink-0 whitespace-nowrap">
                            @if ($request->status === \App\Enums\TimeOffRequestStatus::Pending)
                                <flux:button variant="ghost" size="sm" icon="check" wire:click="managerApprove({{ $request->id }})" data-test="cal-manager-approve" :aria-label="__('Approve absence')" />
                                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="deny({{ $request->id }})" data-test="cal-deny" :aria-label="__('Deny')" />
                            @elseif ($request->status === \App\Enums\TimeOffRequestStatus::ManagerApproved)
                                <flux:button variant="ghost" size="sm" icon="check-badge" wire:click="approve({{ $request->id }})" data-test="cal-approve" :aria-label="__('Confirm pay treatment')" />
                                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="deny({{ $request->id }})" data-test="cal-deny" :aria-label="__('Deny')" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </flux:modal>
</section>
