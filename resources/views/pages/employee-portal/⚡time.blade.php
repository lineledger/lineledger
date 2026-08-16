<?php

use App\Actions\Portal\SaveOwnTimeEntry;
use App\Enums\AuditAction;
use App\Enums\TimeEntryStatus;
use App\Models\AccountingAuditLog;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\TimeEntry;
use App\Support\Payroll\TimeEntryPayCodeCatalogue;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.employee-portal')] #[Title('My time')] class extends Component
{
    public Company $company;

    public Contact $employee;

    /** 'calendar' (default) or 'list'. */
    public string $view = 'calendar';

    /** The visible calendar month, 'Y-m'. */
    public string $month = '';

    /** The day whose entries are open in the day panel, 'Y-m-d'. */
    public ?string $dayDate = null;

    public ?int $editingId = null;
    public string $f_date_worked = '';
    public string $f_hours = '';
    public string $f_pay_code = TimeEntryPayCodeCatalogue::REGULAR;
    public string $f_description = '';
    public bool $f_billable = false;
    public ?int $f_customer_id = null;
    public ?int $f_item_id = null;
    public ?int $f_class_id = null;

    public function mount(Company $company): void
    {
        $this->company = $company;
        $this->employee = auth('customer')->user();
        $this->month = $company->currentDateTime()->format('Y-m');
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['calendar', 'list'], true) ? $view : 'calendar';
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
        Flux::modal('my-time-day')->show();
    }

    public function openCreate(): void
    {
        $this->openCreateFor($this->company->currentDateTime()->toDateString());
    }

    public function openCreateFor(string $date): void
    {
        $this->resetForm();
        $this->f_date_worked = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? $date
            : $this->company->currentDateTime()->toDateString();

        Flux::modal('my-time-day')->close();
        Flux::modal('my-time-form')->show();
    }

    public function openEdit(int $id): void
    {
        $entry = TimeEntry::query()->where('contact_id', $this->employee->id)->findOrFail($id);
        abort_unless($entry->status === TimeEntryStatus::Pending && ! $entry->pay_run_id && ! $entry->invoice_id, 403);

        Flux::modal('my-time-day')->close();

        $this->editingId = $entry->id;
        $this->f_date_worked = $entry->date_worked->toDateString();
        $this->f_hours = (string) (float) $entry->hours;
        $this->f_pay_code = $entry->pay_code;
        $this->f_description = $entry->description ?? '';
        $this->f_billable = $entry->billable;
        $this->f_customer_id = $entry->customer_id;
        $this->f_item_id = $entry->item_id;
        $this->f_class_id = $entry->class_id;

        Flux::modal('my-time-form')->show();
    }

    public function save(SaveOwnTimeEntry $action): void
    {
        $validated = $this->validate([
            'f_date_worked' => ['required', 'date_format:Y-m-d'],
            'f_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'f_pay_code' => ['required', 'string', 'in:'.implode(',', array_keys($this->payCodes))],
            'f_description' => ['nullable', 'string', 'max:1000'],
            'f_billable' => ['boolean'],
            'f_customer_id' => ['nullable', 'integer'],
            'f_item_id' => ['nullable', 'integer'],
            'f_class_id' => ['nullable', 'integer'],
        ]);

        $action->handle($this->employee, [
            'date_worked' => $validated['f_date_worked'],
            'hours' => $validated['f_hours'],
            'pay_code' => $validated['f_pay_code'],
            'description' => $validated['f_description'] ?? null,
            'billable' => $validated['f_billable'] ?? false,
            'customer_id' => ($validated['f_customer_id'] ?? null) ?: null,
            'item_id' => ($validated['f_item_id'] ?? null) ?: null,
            'class_id' => ($validated['f_class_id'] ?? null) ?: null,
        ], $this->editingId ? TimeEntry::query()->where('contact_id', $this->employee->id)->findOrFail($this->editingId) : null);

        Flux::modal('my-time-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Time logged. It will be reviewed before it’s paid.'));
    }

    public function deleteEntry(int $id, SaveOwnTimeEntry $action): void
    {
        $entry = TimeEntry::query()->where('contact_id', $this->employee->id)->findOrFail($id);
        $action->delete($this->employee, $entry);

        Flux::toast(variant: 'success', text: __('Time entry removed.'));
    }

    /**
     * @return Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return TimeEntry::query()
            ->where('contact_id', $this->employee->id)
            ->with(['customer', 'item', 'classification'])
            ->orderByDesc('date_worked')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * The employee's entries across the visible calendar grid (full weeks of
     * the month), grouped by Y-m-d.
     *
     * @return Collection<string, Collection<int, TimeEntry>>
     */
    #[Computed]
    public function entriesByDay(): Collection
    {
        [$start, $end] = $this->gridRange();

        return TimeEntry::query()
            ->where('contact_id', $this->employee->id)
            ->whereBetween('date_worked', [$start->toDateString(), $end->toDateString()])
            ->orderBy('date_worked')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (TimeEntry $e): string => $e->date_worked->toDateString());
    }

    /**
     * The Sun→Sat day cells covering the full weeks of the visible month.
     *
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
    public function monthTotalHours(): float
    {
        $monthStart = $this->monthStart();

        return (float) TimeEntry::query()
            ->where('contact_id', $this->employee->id)
            ->whereBetween('date_worked', [$monthStart->toDateString(), $monthStart->endOfMonth()->toDateString()])
            ->sum('hours');
    }

    #[Computed]
    public function monthLabel(): string
    {
        return $this->monthStart()->format('F Y');
    }

    /**
     * The audit trail for the entry open in the edit modal, newest first, so
     * the employee sees exactly who changed what.
     *
     * @return Collection<int, AccountingAuditLog>
     */
    #[Computed]
    public function history(): Collection
    {
        if (! $this->editingId) {
            return new Collection;
        }

        // editingId is a public Livewire property (client-settable): only ever
        // show history for an entry that is actually this employee's.
        $ownEntryId = TimeEntry::query()
            ->where('contact_id', $this->employee->id)
            ->whereKey($this->editingId)
            ->value('id');

        if ($ownEntryId === null) {
            return new Collection;
        }

        return AccountingAuditLog::query()
            ->where('auditable_type', (new TimeEntry)->getMorphClass())
            ->where('auditable_id', $ownEntryId)
            ->with('actor')
            ->orderByDesc('sequence')
            ->limit(20)
            ->get();
    }

    public function historyLabel(AccountingAuditLog $log): string
    {
        return match ($log->action) {
            AuditAction::TimeEntryCreated => __('Created'),
            AuditAction::TimeEntryUpdated => __('Updated'),
            AuditAction::TimeEntryDeleted => __('Deleted'),
            AuditAction::TimeEntryApproved => __('Approved'),
            AuditAction::TimeEntryRejected => __('Rejected'),
            default => $log->action->value,
        };
    }

    /** Staff actors by user name; the employee's own edits read as "You". */
    public function historyActor(AccountingAuditLog $log): string
    {
        $payloadActor = data_get($log->payload, 'actor');

        if (is_array($payloadActor) && (int) ($payloadActor['contact_id'] ?? 0) === (int) $this->employee->id) {
            return __('You');
        }

        return $log->actor?->name
            ?? (is_array($payloadActor) ? ($payloadActor['name'] ?? null) : null)
            ?? __('System');
    }

    public function formatAuditValue(mixed $value): string
    {
        return match (true) {
            $value === null, $value === '' => '—',
            is_bool($value) => $value ? __('yes') : __('no'),
            default => (string) $value,
        };
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

    /**
     * The pay types the employee can pick when logging time (regular work,
     * overtime, or one of the company's time-off types).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function payCodes(): array
    {
        return TimeEntryPayCodeCatalogue::portalOptions($this->employee->payrollProfile);
    }

    /**
     * @return Collection<int, Contact>
     */
    #[Computed]
    public function customers(): Collection
    {
        return Contact::query()->where('is_customer', true)->where('is_active', true)->orderBy('display_name')->get();
    }

    /**
     * @return Collection<int, Item>
     */
    #[Computed]
    public function items(): Collection
    {
        return Item::query()->where('is_active', true)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, Classification>
     */
    #[Computed]
    public function classes(): Collection
    {
        return Classification::query()->where('is_active', true)->orderBy('name')->get();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'f_date_worked', 'f_hours', 'f_pay_code', 'f_description', 'f_billable', 'f_customer_id', 'f_item_id', 'f_class_id']);
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('My time') }}</flux:heading>
            <flux:subheading>{{ __('Log your hours. Entries are reviewed by your employer before they’re paid.') }}</flux:subheading>
        </div>

        <div class="flex items-center gap-2">
            <flux:button size="sm" variant="ghost" :href="route('employee-portal.dashboard', ['company' => $company->slug])" wire:navigate>
                {{ __('Back') }}
            </flux:button>
            <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="my-time-new">
                {{ __('Log time') }}
            </flux:button>
        </div>
    </div>

    <div class="flex items-center gap-1">
        <flux:button
            size="sm"
            :variant="$view === 'calendar' ? 'filled' : 'ghost'"
            icon="calendar"
            wire:click="setView('calendar')"
            data-test="my-time-view-calendar"
        >
            {{ __('Calendar') }}
        </flux:button>
        <flux:button
            size="sm"
            :variant="$view === 'list' ? 'filled' : 'ghost'"
            icon="list-bullet"
            wire:click="setView('list')"
            data-test="my-time-view-list"
        >
            {{ __('List') }}
        </flux:button>
    </div>

    @if ($view === 'calendar')
        <div class="rounded-lg border border-border" data-test="my-time-calendar">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-border p-3">
                <div class="flex items-center gap-1">
                    <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" data-test="cal-prev" />
                    <flux:button variant="ghost" size="sm" wire:click="currentMonth" data-test="cal-today">{{ __('Today') }}</flux:button>
                    <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" data-test="cal-next" />
                    <flux:heading size="lg" class="ml-2" data-test="cal-month-label">{{ $this->monthLabel }}</flux:heading>
                </div>
                <flux:text size="sm" data-test="cal-month-total">
                    {{ __(':n h this month', ['n' => number_format($this->monthTotalHours, 2)]) }}
                </flux:text>
            </div>

            <div class="grid grid-cols-7 border-b border-border bg-muted text-center text-xs font-medium text-muted-foreground">
                @foreach ([__('Sun'), __('Mon'), __('Tue'), __('Wed'), __('Thu'), __('Fri'), __('Sat')] as $dow)
                    <div class="px-1 py-2">{{ $dow }}</div>
                @endforeach
            </div>

            <div class="grid grid-cols-7">
                @foreach ($this->days as $day)
                    @php
                        $dayEntries = $this->entriesByDay[$day['date']] ?? collect();
                        $dayHours = $dayEntries->sum(fn ($e) => (float) $e->hours);
                    @endphp
                    <button
                        type="button"
                        wire:key="day-{{ $day['date'] }}"
                        @if ($dayEntries->isEmpty())
                            wire:click="openCreateFor('{{ $day['date'] }}')"
                        @else
                            wire:click="openDay('{{ $day['date'] }}')"
                        @endif
                        class="flex min-h-20 flex-col items-start gap-1 border-b border-r border-border p-1.5 text-left transition hover:bg-muted {{ $day['inMonth'] ? '' : 'bg-muted/40 text-muted-foreground' }}"
                        data-test="cal-day"
                        data-date="{{ $day['date'] }}"
                    >
                        <span @class([
                            'text-xs',
                            'flex size-5 items-center justify-center rounded-full bg-accent font-semibold text-accent-foreground' => $day['isToday'],
                        ])>{{ $day['day'] }}</span>

                        @if ($dayHours > 0)
                            <span class="font-mono text-xs font-medium" data-test="cal-day-hours">{{ number_format($dayHours, 2) }}h</span>
                        @endif

                        @if ($dayEntries->isNotEmpty())
                            <span class="flex flex-wrap gap-0.5">
                                @foreach ($dayEntries as $entry)
                                    <span
                                        class="size-1.5 rounded-full {{ $entry->status === \App\Enums\TimeEntryStatus::Approved ? 'bg-green-500' : ($entry->status === \App\Enums\TimeEntryStatus::Rejected ? 'bg-red-500' : 'bg-amber-500') }}"
                                        title="{{ $entry->status->label() }}"
                                    ></span>
                                @endforeach
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-sm">
                <thead class="bg-muted">
                    <tr>
                        <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                        <th class="px-4 py-2 text-right">{{ __('Hours') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Pay type') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Notes') }}</th>
                        <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($this->entries as $entry)
                        <tr data-test="my-time-row">
                            <td class="px-4 py-2 whitespace-nowrap">{{ $entry->date_worked->toDateString() }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $entry->hours, 2) }}</td>
                            <td class="px-4 py-2">
                                @if ($entry->pay_code === \App\Support\Payroll\TimeEntryPayCodeCatalogue::REGULAR)
                                    <span class="text-muted-foreground">{{ __('Regular') }}</span>
                                @else
                                    <flux:badge size="sm" color="indigo">{{ $this->payCodes[$entry->pay_code] ?? ucfirst(str_replace('_', ' ', $entry->pay_code)) }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-muted-foreground">{{ $entry->description }}</td>
                            <td class="px-4 py-2">
                                <flux:badge size="sm" :color="$entry->status === \App\Enums\TimeEntryStatus::Approved ? 'green' : ($entry->status === \App\Enums\TimeEntryStatus::Rejected ? 'red' : 'amber')">
                                    {{ $entry->status->label() }}
                                </flux:badge>
                            </td>
                            <td class="px-4 py-2 text-right whitespace-nowrap">
                                @if ($entry->status === \App\Enums\TimeEntryStatus::Pending && ! $entry->pay_run_id && ! $entry->invoice_id)
                                    <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $entry->id }})" />
                                    <flux:button variant="ghost" size="sm" icon="trash" wire:click="deleteEntry({{ $entry->id }})" wire:confirm="{{ __('Remove this time entry?') }}" />
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-10 text-center text-muted-foreground">{{ __('You haven’t logged any time yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    <flux:modal name="my-time-day" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ $dayDate ? \Carbon\CarbonImmutable::parse($dayDate)->format('l, F j, Y') : '' }}
            </flux:heading>

            <ul class="divide-y divide-border" data-test="day-panel-entries">
                @foreach (($dayDate ? ($this->entriesByDay[$dayDate] ?? collect()) : collect()) as $entry)
                    <li class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <span class="font-mono text-sm font-medium">{{ number_format((float) $entry->hours, 2) }}h</span>
                            <flux:badge size="sm" :color="$entry->status === \App\Enums\TimeEntryStatus::Approved ? 'green' : ($entry->status === \App\Enums\TimeEntryStatus::Rejected ? 'red' : 'amber')">
                                {{ $entry->status->label() }}
                            </flux:badge>
                            @if ($entry->pay_code !== \App\Support\Payroll\TimeEntryPayCodeCatalogue::REGULAR)
                                <flux:badge size="sm" color="indigo">{{ $this->payCodes[$entry->pay_code] ?? ucfirst(str_replace('_', ' ', $entry->pay_code)) }}</flux:badge>
                            @endif
                            @if ($entry->description)
                                <p class="truncate text-xs text-muted-foreground">{{ $entry->description }}</p>
                            @endif
                        </div>
                        <div class="shrink-0 whitespace-nowrap">
                            @if ($entry->status === \App\Enums\TimeEntryStatus::Pending && ! $entry->pay_run_id && ! $entry->invoice_id)
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $entry->id }})" />
                                <flux:button variant="ghost" size="sm" icon="trash" wire:click="deleteEntry({{ $entry->id }})" wire:confirm="{{ __('Remove this time entry?') }}" />
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>

            <div class="flex justify-end">
                <flux:button variant="primary" size="sm" icon="plus" wire:click="openCreateFor('{{ $dayDate }}')" data-test="day-panel-log">
                    {{ __('Log time') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="my-time-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit time') : __('Log time') }}</flux:heading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model="f_date_worked" :label="__('Date')" required data-test="my-time-date" />
                <flux:input type="number" step="0.25" wire:model="f_hours" :label="__('Hours')" required inputmode="decimal" data-test="my-time-hours" />
            </div>

            <flux:select wire:model="f_pay_code" :label="__('Pay type')" :description="__('Pick what these hours are: regular work, overtime, or time off (sick, vacation, …).')" data-test="my-time-pay-code">
                @foreach ($this->payCodes as $code => $label)
                    <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="f_description" :label="__('Notes')" rows="2" />

            <flux:select wire:model="f_class_id" :label="__('Class / project')">
                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                @foreach ($this->classes as $class)
                    <flux:select.option value="{{ $class->id }}">{{ $class->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch wire:model.live="f_billable" :label="__('Billable to a customer')" />

            @if ($f_billable)
                <div class="grid grid-cols-1 gap-4 rounded-lg border border-border p-4 sm:grid-cols-2">
                    <flux:select wire:model="f_customer_id" :label="__('Customer')">
                        <flux:select.option value="">{{ __('— select —') }}</flux:select.option>
                        @foreach ($this->customers as $customer)
                            <flux:select.option value="{{ $customer->id }}">{{ $customer->display_name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="f_item_id" :label="__('Service')">
                        <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                        @foreach ($this->items as $item)
                            <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="my-time-save">{{ __('Save') }}</flux:button>
            </div>

            @if ($editingId)
                <div class="border-t border-border pt-4" data-test="my-time-history">
                    <flux:heading size="sm">{{ __('Edit history') }}</flux:heading>

                    @if ($this->history->isEmpty())
                        <p class="mt-2 text-xs text-muted-foreground">{{ __('No recorded changes.') }}</p>
                    @else
                        <ul class="mt-2 max-h-48 space-y-2 overflow-y-auto text-xs">
                            @foreach ($this->history as $log)
                                <li data-test="my-time-history-row">
                                    <span class="text-muted-foreground">{{ $log->recorded_at->format('Y-m-d H:i') }}</span>
                                    <span class="font-medium">{{ $this->historyActor($log) }}</span>
                                    <span>{{ $this->historyLabel($log) }}</span>
                                    @if (isset($log->payload['from'], $log->payload['to']))
                                        <span class="text-muted-foreground">{{ __('status') }}: {{ $log->payload['from'] }} → {{ $log->payload['to'] }}</span>
                                    @endif
                                    @foreach (data_get($log->payload, 'changes', []) as $field => $change)
                                        <div class="ml-4 text-muted-foreground">
                                            {{ $field }}: {{ $this->formatAuditValue($change['from'] ?? null) }} → {{ $this->formatAuditValue($change['to'] ?? null) }}
                                        </div>
                                    @endforeach
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </form>
    </flux:modal>
</div>
