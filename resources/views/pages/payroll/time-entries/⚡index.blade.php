<?php

use App\Actions\Payroll\SaveTimeEntry;
use App\Actions\Payroll\SetTimeEntryStatus;
use App\Actions\Sales\CreateInvoiceFromTimeEntries;
use App\Enums\AuditAction;
use App\Enums\TimeEntryStatus;
use App\Models\AccountingAuditLog;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\TimeEntry;
use App\Support\Payroll\TimeEntryPayCodeCatalogue;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Time entries')] class extends Component {
    public Company $company;

    public string $filterStatus = '';

    public ?int $filterEmployee = null;

    public ?int $filterCustomer = null;

    public string $filterPayCode = '';

    public ?int $editingId = null;

    /** @var array<int, string> Selected (pending) entry ids for bulk approval. */
    public array $selected = [];

    public bool $selectPage = false;

    public ?int $f_contact_id = null;
    public string $f_date_worked = '';
    public string $f_hours = '';
    public string $f_pay_code = TimeEntryPayCodeCatalogue::REGULAR;
    public string $f_description = '';
    public bool $f_billable = false;
    public ?int $f_customer_id = null;
    public ?int $f_item_id = null;
    public string $f_rate = '';
    public ?int $f_class_id = null;

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->f_date_worked = $this->company->currentDateTime()->toDateString();
        Flux::modal('entry-form')->show();
    }

    public function openEdit(int $id): void
    {
        $entry = TimeEntry::findOrFail($id);

        $this->editingId = $entry->id;
        $this->f_contact_id = $entry->contact_id;
        $this->f_date_worked = $entry->date_worked->toDateString();
        $this->f_hours = (string) (float) $entry->hours;
        $this->f_pay_code = $entry->pay_code;
        $this->f_description = $entry->description ?? '';
        $this->f_billable = $entry->billable;
        $this->f_customer_id = $entry->customer_id;
        $this->f_item_id = $entry->item_id;
        $this->f_rate = $entry->billable_rate_cents !== null ? (string) ((int) $entry->billable_rate_cents / 100) : '';
        $this->f_class_id = $entry->class_id;

        Flux::modal('entry-form')->show();
    }

    public function save(SaveTimeEntry $action): void
    {
        $validated = $this->validate([
            'f_contact_id' => ['required', 'integer'],
            'f_date_worked' => ['required', 'date_format:Y-m-d'],
            'f_hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'f_pay_code' => ['required', 'string', 'in:'.implode(',', array_keys($this->payCodes))],
            'f_description' => ['nullable', 'string', 'max:1000'],
            'f_billable' => ['boolean'],
            'f_customer_id' => ['nullable', 'integer'],
            'f_item_id' => ['nullable', 'integer'],
            'f_rate' => ['nullable', 'numeric', 'min:0'],
            'f_class_id' => ['nullable', 'integer'],
        ]);

        $action->handle([
            'contact_id' => $validated['f_contact_id'],
            'date_worked' => $validated['f_date_worked'],
            'hours' => $validated['f_hours'],
            'pay_code' => $validated['f_pay_code'],
            'description' => $validated['f_description'] ?? null,
            'billable' => $validated['f_billable'] ?? false,
            'customer_id' => ($validated['f_customer_id'] ?? null) ?: null,
            'item_id' => ($validated['f_item_id'] ?? null) ?: null,
            'billable_rate_cents' => ($validated['f_rate'] ?? '') !== '' ? (int) round((float) $validated['f_rate'] * 100) : null,
            'class_id' => ($validated['f_class_id'] ?? null) ?: null,
        ], $this->editingId ? TimeEntry::findOrFail($this->editingId) : null);

        Flux::modal('entry-form')->close();
        $this->resetForm();

        Flux::toast(variant: 'success', text: __('Time entry saved.'));
    }

    public function approve(int $id, SetTimeEntryStatus $action): void
    {
        $action->handle([$id], TimeEntryStatus::Approved);
        Flux::toast(variant: 'success', text: __('Time entry approved.'));
    }

    public function reject(int $id, SetTimeEntryStatus $action): void
    {
        $action->handle([$id], TimeEntryStatus::Rejected);
        Flux::toast(variant: 'success', text: __('Time entry rejected.'));
    }

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value ? $this->selectablePendingIds() : [];
    }

    public function updatedFilterStatus(): void
    {
        $this->clearSelection();
    }

    public function updatedFilterEmployee(): void
    {
        $this->clearSelection();
    }

    public function updatedFilterCustomer(): void
    {
        $this->clearSelection();
    }

    public function updatedFilterPayCode(): void
    {
        $this->clearSelection();
    }

    /**
     * The selectable pay codes for this company (wage codes + active time-off
     * policies), code => label.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function payCodes(): array
    {
        return TimeEntryPayCodeCatalogue::options();
    }

    public function approveSelected(SetTimeEntryStatus $action): void
    {
        $ids = array_values(array_map('intval', $this->selected));

        if ($ids === []) {
            return;
        }

        $count = $action->handle($ids, TimeEntryStatus::Approved);

        $this->clearSelection();

        Flux::toast(variant: 'success', text: trans_choice('{1} :n time entry approved.|[2,*] :n time entries approved.', $count, ['n' => $count]));
    }

    /**
     * The audit trail for the entry open in the edit modal, newest first. Lazy:
     * only evaluated when the modal renders the history section.
     *
     * @return Collection<int, AccountingAuditLog>
     */
    #[Computed]
    public function history(): Collection
    {
        if (! $this->editingId) {
            return new Collection;
        }

        return AccountingAuditLog::query()
            ->where('auditable_type', (new TimeEntry)->getMorphClass())
            ->where('auditable_id', $this->editingId)
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

    public function historyActorName(AccountingAuditLog $log): string
    {
        return $log->actor?->name
            ?? data_get($log->payload, 'actor.name')
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

    /**
     * @return array<int, string>
     */
    private function selectablePendingIds(): array
    {
        return $this->entries
            ->filter(fn (TimeEntry $e): bool => $e->status === TimeEntryStatus::Pending && ! $e->pay_run_id && ! $e->invoice_id)
            ->map(fn (TimeEntry $e): string => (string) $e->id)
            ->values()
            ->all();
    }

    private function clearSelection(): void
    {
        $this->reset(['selected', 'selectPage']);
    }

    /**
     * @return Collection<int, TimeEntry>
     */
    #[Computed]
    public function entries(): Collection
    {
        return TimeEntry::query()
            ->with(['employee', 'customer', 'item', 'classification'])
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterEmployee, fn ($q) => $q->where('contact_id', $this->filterEmployee))
            ->when($this->filterCustomer, fn ($q) => $q->where('customer_id', $this->filterCustomer))
            ->when($this->filterPayCode !== '', fn ($q) => $q->where('pay_code', $this->filterPayCode))
            ->orderByDesc('date_worked')
            ->orderByDesc('id')
            ->get();
    }

    /** Count of approved, billable, un-billed entries for the customer filter. */
    #[Computed]
    public function billableForCustomer(): int
    {
        if ($this->filterCustomer === null) {
            return 0;
        }

        return TimeEntry::query()
            ->where('customer_id', $this->filterCustomer)
            ->where('billable', true)
            ->where('status', TimeEntryStatus::Approved->value)
            ->whereNull('invoice_id')
            ->count();
    }

    public function createInvoice(CreateInvoiceFromTimeEntries $action): void
    {
        if ($this->filterCustomer === null) {
            return;
        }

        $customer = Contact::findOrFail($this->filterCustomer);

        $entries = TimeEntry::query()
            ->where('customer_id', $customer->id)
            ->where('billable', true)
            ->where('status', TimeEntryStatus::Approved->value)
            ->whereNull('invoice_id')
            ->get();

        if ($entries->isEmpty()) {
            Flux::toast(variant: 'warning', text: __('No approved, billable, un-billed time for this customer.'));

            return;
        }

        $invoice = $action->handle($customer, $entries);

        Flux::toast(variant: 'success', text: __('Draft invoice created from time.'));
        $this->redirectRoute('invoices.edit', ['company' => $this->company, 'invoice' => $invoice], navigate: true);
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
        $this->reset(['editingId', 'f_contact_id', 'f_date_worked', 'f_hours', 'f_pay_code', 'f_description', 'f_billable', 'f_customer_id', 'f_item_id', 'f_rate', 'f_class_id']);
    }
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Time entries') }}</flux:heading>
            <flux:subheading>{{ __('Log hours for employees. Approved hours can be pulled into a pay run and billable time invoiced to customers.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-entry-button">
            {{ __('Log time') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:select wire:model.live="filterStatus" class="max-w-48">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            @foreach (TimeEntryStatus::cases() as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterEmployee" class="max-w-56">
            <flux:select.option value="">{{ __('All employees') }}</flux:select.option>
            @foreach ($this->employees as $employee)
                <flux:select.option value="{{ $employee->id }}">{{ $employee->display_name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterCustomer" class="max-w-56">
            <flux:select.option value="">{{ __('All customers') }}</flux:select.option>
            @foreach ($this->customers as $customer)
                <flux:select.option value="{{ $customer->id }}">{{ $customer->display_name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterPayCode" class="max-w-48" data-test="filter-pay-code">
            <flux:select.option value="">{{ __('All pay types') }}</flux:select.option>
            @foreach ($this->payCodes as $code => $label)
                <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        @if ($filterCustomer && $this->billableForCustomer > 0)
            <flux:button variant="primary" size="sm" icon="document-plus" wire:click="createInvoice" data-test="create-invoice-from-time">
                {{ __('Create invoice (:n)', ['n' => $this->billableForCustomer]) }}
            </flux:button>
        @endif

        @if (count($selected) > 0)
            <flux:button variant="primary" size="sm" icon="check" wire:click="approveSelected" data-test="approve-selected">
                {{ __('Approve selected (:n)', ['n' => count($selected)]) }}
            </flux:button>
        @endif
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="w-8 px-4 py-2">
                        <input
                            type="checkbox"
                            wire:model.live="selectPage"
                            class="size-4 rounded border-zinc-300 dark:border-zinc-600"
                            title="{{ __('Select all pending') }}"
                            data-test="select-all-pending"
                        />
                    </th>
                    <th class="px-4 py-2 text-left">{{ __('Date') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Employee') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Hours') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Pay type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Billable to') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->entries as $entry)
                    <tr data-test="time-entry-row">
                        <td class="px-4 py-2">
                            @if ($entry->status === \App\Enums\TimeEntryStatus::Pending && ! $entry->pay_run_id && ! $entry->invoice_id)
                                <input
                                    type="checkbox"
                                    wire:model.live="selected"
                                    value="{{ $entry->id }}"
                                    class="size-4 rounded border-zinc-300 dark:border-zinc-600"
                                    data-test="select-entry"
                                />
                            @endif
                        </td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $entry->date_worked->toDateString() }}
                            <span class="text-muted-foreground">· {{ $entry->date_worked->format('D') }}</span>
                            @if ($entry->description)
                                <flux:tooltip :content="$entry->description" position="top">
                                    <flux:icon.chat-bubble-bottom-center-text class="ml-1 inline size-4 text-muted-foreground" data-test="entry-note-icon" />
                                </flux:tooltip>
                            @endif
                        </td>
                        <td class="px-4 py-2">{{ $entry->employee?->display_name }}</td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $entry->hours, 2) }}</td>
                        <td class="px-4 py-2" data-test="entry-pay-code">
                            @if ($entry->pay_code === \App\Support\Payroll\TimeEntryPayCodeCatalogue::REGULAR)
                                <span class="text-muted-foreground">{{ __('Regular') }}</span>
                            @else
                                <flux:badge size="sm" color="indigo">{{ $this->payCodes[$entry->pay_code] ?? ucfirst(str_replace('_', ' ', $entry->pay_code)) }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            @if ($entry->billable)
                                {{ $entry->customer?->display_name ?? __('— (no customer)') }}
                            @else
                                <span class="text-muted-foreground">{{ __('Non-billable') }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-2">
                            <flux:badge size="sm" :color="$entry->status === \App\Enums\TimeEntryStatus::Approved ? 'green' : ($entry->status === \App\Enums\TimeEntryStatus::Rejected ? 'red' : 'amber')">
                                {{ $entry->status->label() }}
                            </flux:badge>
                            @if ($entry->pay_run_id)
                                <flux:badge size="sm" color="sky">{{ __('Paid') }}</flux:badge>
                            @endif
                            @if ($entry->invoice_id)
                                <flux:badge size="sm" color="purple">{{ __('Billed') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            @if ($entry->status === \App\Enums\TimeEntryStatus::Pending && ! $entry->pay_run_id && ! $entry->invoice_id)
                                <flux:button variant="ghost" size="sm" icon="check" wire:click="approve({{ $entry->id }})" data-test="approve-entry" />
                                <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="reject({{ $entry->id }})" data-test="reject-entry" />
                            @endif
                            @if (! $entry->pay_run_id && ! $entry->invoice_id)
                                <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEdit({{ $entry->id }})" />
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">{{ __('No time entries yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="entry-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Edit time entry') : __('Log time') }}</flux:heading>

            <flux:select wire:model="f_contact_id" :label="__('Employee')" required data-test="entry-employee">
                <flux:select.option value="">{{ __('— select —') }}</flux:select.option>
                @foreach ($this->employees as $employee)
                    <flux:select.option value="{{ $employee->id }}">{{ $employee->display_name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <flux:input type="date" wire:model="f_date_worked" :label="__('Date')" required data-test="entry-date" />
                <flux:input type="number" step="0.25" wire:model="f_hours" :label="__('Hours')" required inputmode="decimal" data-test="entry-hours" />
            </div>

            <flux:select wire:model="f_pay_code" :label="__('Pay type')" :description="__('How these hours are paid: regular work, overtime, or a time-off type that draws the matching balance.')" data-test="entry-pay-code-select">
                @foreach ($this->payCodes as $code => $label)
                    <flux:select.option value="{{ $code }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:textarea wire:model="f_description" :label="__('Description')" rows="2" />

            <flux:select wire:model="f_class_id" :label="__('Class / project')">
                <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                @foreach ($this->classes as $class)
                    <flux:select.option value="{{ $class->id }}">{{ $class->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:switch wire:model.live="f_billable" :label="__('Billable to a customer')" data-test="entry-billable" />

            @if ($f_billable)
                <div class="space-y-4 rounded-lg border border-border p-4">
                    <flux:select wire:model="f_customer_id" :label="__('Customer')">
                        <flux:select.option value="">{{ __('— select —') }}</flux:select.option>
                        @foreach ($this->customers as $customer)
                            <flux:select.option value="{{ $customer->id }}">{{ $customer->display_name }}</flux:select.option>
                        @endforeach
                    </flux:select>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:select wire:model="f_item_id" :label="__('Service item')">
                            <flux:select.option value="">{{ __('— none —') }}</flux:select.option>
                            @foreach ($this->items as $item)
                                <flux:select.option value="{{ $item->id }}">{{ $item->name }}</flux:select.option>
                            @endforeach
                        </flux:select>

                        <flux:input type="number" step="0.01" wire:model="f_rate" :label="__('Rate / hour')" inputmode="decimal" :description="__('Blank = the item’s price.')" />
                    </div>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="entry-save-button">{{ __('Save') }}</flux:button>
            </div>

            @if ($editingId)
                <div class="border-t border-border pt-4" data-test="entry-history">
                    <flux:heading size="sm">{{ __('History') }}</flux:heading>

                    @if ($this->history->isEmpty())
                        <p class="mt-2 text-xs text-muted-foreground">{{ __('No recorded changes.') }}</p>
                    @else
                        <ul class="mt-2 max-h-48 space-y-2 overflow-y-auto text-xs">
                            @foreach ($this->history as $log)
                                <li data-test="entry-history-row">
                                    <span class="text-muted-foreground">{{ $log->recorded_at->format('Y-m-d H:i') }}</span>
                                    <span class="font-medium">{{ $this->historyActorName($log) }}</span>
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
</section>
