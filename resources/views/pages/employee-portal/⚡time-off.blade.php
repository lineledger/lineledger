<?php

use App\Actions\Portal\SubmitOwnTimeOffRequest;
use App\Enums\TimeOffRequestStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeeAccrualBalance;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;
use App\Services\Payroll\TimeOffBalanceProjection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.employee-portal')] #[Title('Time off')] class extends Component
{
    public Company $company;

    public Contact $employee;

    /** The visible team-calendar month, 'Y-m'. */
    public string $month = '';

    public ?int $f_policy_id = null;
    public string $f_start_date = '';
    public string $f_end_date = '';
    public string $f_hours_per_day = '';
    public string $f_note = '';

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->employee = auth('customer')->user();

        $today = $company->currentDateTime()->toDateString();
        $this->f_start_date = $today;
        $this->f_end_date = $today;
        // Workdays per period from the schedule (260 working days/year), so a
        // biweekly 80-hour period suggests 8 h/day — not 80 ÷ 5 = 16.
        $profile = $this->employee->payrollProfile;
        $periods = (int) ($profile?->payrollSchedule?->periods_per_year ?? 0);
        $defaultHours = (float) ($profile?->default_hours_per_period ?? 0);
        $suggested = $periods > 0 && $defaultHours > 0
            ? round($defaultHours * $periods / 260, 2)
            : 8.0;
        $this->f_hours_per_day = (string) min(24, max(1, $suggested));
        $this->month = $company->currentDateTime()->format('Y-m');
    }

    public function previousMonth(): void
    {
        $this->month = $this->monthStart()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->monthStart()->addMonth()->format('Y-m');
    }

    public function submit(SubmitOwnTimeOffRequest $action): void
    {
        $validated = $this->validate([
            'f_policy_id' => ['required', 'integer'],
            'f_start_date' => ['required', 'date_format:Y-m-d'],
            'f_end_date' => ['required', 'date_format:Y-m-d'],
            'f_hours_per_day' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'f_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->handle($this->employee, [
            'time_off_policy_id' => $validated['f_policy_id'],
            'start_date' => $validated['f_start_date'],
            'end_date' => $validated['f_end_date'],
            'hours_per_day' => $validated['f_hours_per_day'],
            'note' => $validated['f_note'] ?? null,
        ]);

        $this->reset(['f_policy_id', 'f_note']);
        unset($this->requests, $this->balances, $this->projection);

        Flux::toast(variant: 'success', text: __('Request sent — you’ll get an email once it’s decided.'));
    }

    public function cancelRequest(int $id, SubmitOwnTimeOffRequest $action): void
    {
        $request = TimeOffRequest::query()->where('contact_id', $this->employee->id)->findOrFail($id);
        $action->cancelOwn($this->employee, $request);

        unset($this->requests, $this->projection);

        Flux::toast(variant: 'success', text: __('Request withdrawn.'));
    }

    /**
     * The time-off policies the employee may request: their assignments plus
     * every active company default (first use materializes the assignment).
     *
     * @return Collection<int, TimeOffPolicy>
     */
    #[Computed]
    public function policies(): Collection
    {
        return $this->employee->payrollProfile?->availableTimeOffPolicies() ?? new Collection;
    }

    /**
     * Balance cards: one per assigned policy, with the running balance.
     *
     * @return Collection<int, array{policy: TimeOffPolicy, hours: float, cents: int}>
     */
    #[Computed]
    public function balances(): Collection
    {
        $profile = $this->employee->payrollProfile;

        return $this->policies->map(function (TimeOffPolicy $policy) use ($profile): array {
            $balance = $profile
                ? EmployeeAccrualBalance::query()
                    ->where('employee_payroll_profile_id', $profile->id)
                    ->where('code', $policy->code)
                    ->first()
                : null;

            return [
                'policy' => $policy,
                'hours' => (float) ($balance->balance_hours ?? 0),
                'cents' => (int) ($balance->balance_cents ?? 0),
            ];
        });
    }

    /**
     * Live projection for the policy picked in the form.
     *
     * @return array{current: float, pending: float, projected: float}|null
     */
    #[Computed]
    public function projection(): ?array
    {
        $policy = $this->f_policy_id ? $this->policies->firstWhere('id', (int) $this->f_policy_id) : null;

        if ($policy === null || $policy->isDollarUnit()) {
            return null;
        }

        return app(TimeOffBalanceProjection::class)->for($this->employee, $policy);
    }

    /**
     * @return Collection<int, TimeOffRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return TimeOffRequest::query()
            ->where('contact_id', $this->employee->id)
            ->with(['policy'])
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->limit(50)
            ->get();
    }

    /**
     * APPROVED team absences across the visible month, expanded to per-day
     * chips (names + dates + category color only — no reasons, no balances).
     * Empty when the company turned the team calendar off.
     *
     * @return Collection<string, Collection<int, TimeOffRequest>>
     */
    #[Computed]
    public function teamByDay(): Collection
    {
        if (! $this->company->portal_team_calendar) {
            return new Collection;
        }

        [$start, $end] = $this->gridRange();

        $approved = TimeOffRequest::query()
            ->with(['employee', 'policy'])
            ->where('status', \App\Enums\TimeOffRequestStatus::Approved->value)
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->orderBy('start_date')
            ->get();

        $byDay = [];

        foreach ($approved as $request) {
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

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Time off') }}</flux:heading>
            <flux:subheading>{{ __('Your balances, and requests for vacation, sick days and other leave.') }}</flux:subheading>
        </div>

        <flux:button size="sm" variant="ghost" :href="route('employee-portal.dashboard', ['company' => $company->slug])" wire:navigate>
            {{ __('Back') }}
        </flux:button>
    </div>

    @if ($this->policies->isEmpty())
        <flux:callout data-test="no-policies">
            {{ __('No time-off types are available to you yet. Ask your employer to assign one to you on the employee setup page — or to turn on “Use for new employees” on the time-off policy so everyone gets it automatically.') }}
        </flux:callout>
    @else
        {{-- Balances --}}
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="time-off-balances">
            @foreach ($this->balances as $row)
                <div class="rounded-lg border border-border p-4">
                    <flux:text size="sm" class="text-muted-foreground">{{ $row['policy']->name }}</flux:text>
                    <flux:heading size="lg" class="mt-1 font-mono">
                        @if ($row['policy']->isDollarUnit())
                            ${{ number_format($row['cents'] / 100, 2) }}
                        @else
                            {{ number_format($row['hours'], 2) }} {{ __('h') }}
                        @endif
                    </flux:heading>
                </div>
            @endforeach
        </div>

        {{-- Request form --}}
        <div class="rounded-lg border border-border p-5">
            <flux:heading size="lg" class="mb-4">{{ __('Request time off') }}</flux:heading>

            <form wire:submit="submit" class="space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <flux:select wire:model.live="f_policy_id" :label="__('Type')" required data-test="time-off-policy">
                        <flux:select.option value="">{{ __('— select —') }}</flux:select.option>
                        @foreach ($this->policies as $policy)
                            <flux:select.option value="{{ $policy->id }}">{{ $policy->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:input type="number" step="0.25" wire:model="f_hours_per_day" :label="__('Hours per day')" required inputmode="decimal" data-test="time-off-hours" />
                    <flux:input type="date" wire:model="f_start_date" :label="__('First day')" required data-test="time-off-start" />
                    <flux:input type="date" wire:model="f_end_date" :label="__('Last day')" required data-test="time-off-end" />
                </div>

                @if ($this->projection)
                    <div class="rounded-lg border border-border bg-muted/40 p-3 text-sm" data-test="portal-projection">
                        {{ __('You have :current h available; :pending h are already requested or scheduled → :projected h would remain.', [
                            'current' => number_format($this->projection['current'], 2),
                            'pending' => number_format($this->projection['pending'], 2),
                            'projected' => number_format($this->projection['projected'], 2),
                        ]) }}
                    </div>
                @endif

                <flux:textarea wire:model="f_note" :label="__('Note for your approver')" rows="2" data-test="time-off-note" />

                <div class="flex justify-end">
                    <flux:button variant="primary" type="submit" data-test="time-off-submit">{{ __('Send request') }}</flux:button>
                </div>
            </form>
        </div>
    @endif

    {{-- Team calendar (approved absences only) --}}
    @if ($company->portal_team_calendar)
        <div class="rounded-lg border border-border" data-test="team-calendar">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-3">
                <div class="flex items-center gap-1">
                    <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" data-test="team-cal-prev" />
                    <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" data-test="team-cal-next" />
                    <flux:heading size="lg" class="ml-2">{{ __('Team time off') }} · {{ $this->monthLabel }}</flux:heading>
                </div>
                <flux:text size="sm" class="text-muted-foreground">{{ __('Approved absences only.') }}</flux:text>
            </div>

            <div class="grid grid-cols-7 border-b border-border bg-muted text-center text-xs font-medium text-muted-foreground">
                @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $dow)
                    <div class="px-1 py-2">{{ $dow }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7">
                @foreach ($this->days as $day)
                    @php($dayTeam = $this->teamByDay[$day['date']] ?? collect())
                    <div
                        wire:key="tday-{{ $day['date'] }}"
                        class="flex min-h-20 flex-col items-start gap-1 border-b border-r border-border p-1.5 {{ $day['inMonth'] ? '' : 'bg-muted/40 text-muted-foreground' }}"
                        data-test="team-cal-day"
                        data-date="{{ $day['date'] }}"
                    >
                        <span @class([
                            'text-xs',
                            'flex size-5 items-center justify-center rounded-full bg-accent font-semibold text-accent-foreground' => $day['isToday'],
                        ])>{{ $day['day'] }}</span>

                        <span class="flex w-full flex-col gap-0.5">
                            @foreach ($dayTeam->take(3) as $request)
                                <flux:badge size="sm" :color="$request->policy?->category?->color() ?? 'zinc'" class="max-w-full truncate" data-test="team-cal-chip">
                                    {{ $request->employee?->display_name }}
                                </flux:badge>
                            @endforeach
                            @if ($dayTeam->count() > 3)
                                <span class="text-xs text-muted-foreground">{{ __('+:n more', ['n' => $dayTeam->count() - 3]) }}</span>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- My requests --}}
    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Dates') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Hours') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->requests as $request)
                    <tr data-test="my-time-off-row">
                        <td class="px-4 py-2">{{ $request->policy?->name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $request->start_date->toDateString() }}
                            @if (! $request->start_date->isSameDay($request->end_date))
                                → {{ $request->end_date->toDateString() }}
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $request->total_hours, 2) }}</td>
                        <td class="px-4 py-2">
                            <flux:badge size="sm" :color="$request->status->color()" data-test="my-request-status">{{ $request->status->label() }}</flux:badge>
                            @if ($request->decision_note)
                                <flux:tooltip :content="$request->decision_note" position="top">
                                    <flux:icon.chat-bubble-bottom-center-text class="ml-1 inline size-4 text-muted-foreground" />
                                </flux:tooltip>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if (in_array($request->status, [TimeOffRequestStatus::Pending, TimeOffRequestStatus::ManagerApproved], true))
                                <flux:button variant="ghost" size="sm" wire:click="cancelRequest({{ $request->id }})" wire:confirm="{{ __('Withdraw this request?') }}" data-test="withdraw-request">
                                    {{ __('Withdraw') }}
                                </flux:button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-muted-foreground">{{ __('You haven’t requested any time off yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
