<?php

use App\Actions\Payroll\DecideTimeOffRequest;
use App\Actions\Payroll\SaveTimeOffRequest;
use App\Enums\TimeOffRequestStatus;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TimeOffPolicy;
use App\Models\TimeOffRequest;
use App\Services\Payroll\TimeOffBalanceProjection;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Time-off requests')] class extends Component {
    public Company $company;

    /** '' = the open pipeline (pending + manager-approved + approved). */
    public string $filterStatus = '';

    public ?int $filterEmployee = null;

    public ?int $decidingId = null;

    public string $decideNote = '';

    public bool $generateEntries = true;

    // Admin "new request on behalf" form.
    public ?int $f_contact_id = null;
    public ?int $f_policy_id = null;
    public string $f_start_date = '';
    public string $f_end_date = '';
    public string $f_hours_per_day = '8';
    public string $f_note = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        abort_unless($company->usesPayroll(), 404);
    }

    public function openDecide(int $id): void
    {
        $this->decidingId = $id;
        $this->decideNote = '';
        $this->generateEntries = true;
        Flux::modal('decide-request')->show();
    }

    public function managerApprove(DecideTimeOffRequest $action): void
    {
        $action->managerApprove($this->deciding, auth()->user(), $this->decideNote ?: null);
        $this->closeDecide(__('Absence approved — payroll confirmation is next.'));
    }

    public function approve(DecideTimeOffRequest $action): void
    {
        $action->approve($this->deciding, auth()->user(), $this->decideNote ?: null, $this->generateEntries);
        $this->closeDecide($this->generateEntries
            ? __('Approved — time entries were scheduled for each working day.')
            : __('Approved without scheduling time entries.'));
    }

    public function deny(DecideTimeOffRequest $action): void
    {
        $action->deny($this->deciding, auth()->user(), $this->decideNote ?: null);
        $this->closeDecide(__('Request denied.'));
    }

    public function cancel(DecideTimeOffRequest $action): void
    {
        $action->cancel($this->deciding, auth()->user(), $this->decideNote ?: null);
        $this->closeDecide(__('Request cancelled.'));
    }

    public function openCreate(): void
    {
        $this->reset(['f_contact_id', 'f_policy_id', 'f_start_date', 'f_end_date', 'f_hours_per_day', 'f_note']);
        $this->f_start_date = $this->company->currentDateTime()->toDateString();
        $this->f_end_date = $this->f_start_date;
        Flux::modal('request-form')->show();
    }

    public function save(SaveTimeOffRequest $action): void
    {
        $validated = $this->validate([
            'f_contact_id' => ['required', 'integer'],
            'f_policy_id' => ['required', 'integer'],
            'f_start_date' => ['required', 'date_format:Y-m-d'],
            'f_end_date' => ['required', 'date_format:Y-m-d'],
            'f_hours_per_day' => ['required', 'numeric', 'min:0.25', 'max:24'],
            'f_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $action->handle([
            'contact_id' => $validated['f_contact_id'],
            'time_off_policy_id' => $validated['f_policy_id'],
            'start_date' => $validated['f_start_date'],
            'end_date' => $validated['f_end_date'],
            'hours_per_day' => $validated['f_hours_per_day'],
            'note' => $validated['f_note'] ?? null,
        ]);

        Flux::modal('request-form')->close();
        Flux::toast(variant: 'success', text: __('Request recorded — it now awaits approval.'));
    }

    private function closeDecide(string $message): void
    {
        Flux::modal('decide-request')->close();
        $this->reset(['decidingId', 'decideNote']);
        unset($this->deciding);
        Flux::toast(variant: 'success', text: $message);
    }

    #[Computed]
    public function deciding(): ?TimeOffRequest
    {
        return $this->decidingId ? TimeOffRequest::query()->with(['employee.payrollProfile.approver', 'policy', 'managerDecidedBy', 'decidedBy'])->find($this->decidingId) : null;
    }

    /**
     * Balance projection for the request open in the decision modal.
     *
     * @return array{current: float, pending: float, projected: float}|null
     */
    #[Computed]
    public function projection(): ?array
    {
        $request = $this->deciding;

        if ($request === null || $request->employee === null || $request->policy === null) {
            return null;
        }

        // Dollar-unit policies track cents, not hours — hour math here would
        // show a bogus negative warning (the portal guards the same way).
        if ($request->policy->isDollarUnit()) {
            return null;
        }

        // While the request is still open its own hours must COUNT — that's
        // exactly the over-commitment this projection exists to warn about.
        // Once Approved, its unconsumed entries are counted instead, so it is
        // excluded to avoid double-counting.
        $excluding = $request->status === TimeOffRequestStatus::Approved ? $request : null;

        return app(TimeOffBalanceProjection::class)->for($request->employee, $request->policy, $excluding);
    }

    /**
     * @return Collection<int, TimeOffRequest>
     */
    #[Computed]
    public function requests(): Collection
    {
        return TimeOffRequest::query()
            ->with(['employee', 'policy', 'managerDecidedBy', 'decidedBy'])
            ->when(
                $this->filterStatus !== '',
                fn ($q) => $q->where('status', $this->filterStatus),
                fn ($q) => $q->whereIn('status', [TimeOffRequestStatus::Pending->value, TimeOffRequestStatus::ManagerApproved->value, TimeOffRequestStatus::Approved->value]),
            )
            ->when($this->filterEmployee, fn ($q) => $q->where('contact_id', $this->filterEmployee))
            ->orderByRaw("case status when 'pending' then 0 when 'manager_approved' then 1 else 2 end")
            ->orderBy('start_date')
            ->orderByDesc('id')
            ->get();
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
}; ?>

<section class="w-full">
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Time-off requests') }}</flux:heading>
            <flux:subheading>{{ __('Two-step approval: a manager accepts the absence, then payroll confirms the pay treatment — which schedules the matching time entries for the pay run.') }}</flux:subheading>
        </div>

        <flux:button variant="primary" icon="plus" wire:click="openCreate" data-test="new-request-button">
            {{ __('Record a request') }}
        </flux:button>
    </div>

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:select wire:model.live="filterStatus" class="max-w-52" data-test="filter-request-status">
            <flux:select.option value="">{{ __('Open (pending → approved)') }}</flux:select.option>
            @foreach (TimeOffRequestStatus::cases() as $status)
                <flux:select.option value="{{ $status->value }}">{{ $status->label() }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="filterEmployee" class="max-w-56">
            <flux:select.option value="">{{ __('All employees') }}</flux:select.option>
            @foreach ($this->employees as $employee)
                <flux:select.option value="{{ $employee->id }}">{{ $employee->display_name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left">{{ __('Employee') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Type') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Dates') }}</th>
                    <th class="px-4 py-2 text-right">{{ __('Hours') }}</th>
                    <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse ($this->requests as $request)
                    <tr data-test="time-off-request-row">
                        <td class="px-4 py-2">{{ $request->employee?->display_name }}</td>
                        <td class="px-4 py-2">{{ $request->policy?->name }}</td>
                        <td class="px-4 py-2 whitespace-nowrap">
                            {{ $request->start_date->toDateString() }}
                            @if (! $request->start_date->isSameDay($request->end_date))
                                → {{ $request->end_date->toDateString() }}
                            @endif
                            @if ($request->employee_note)
                                <flux:tooltip :content="$request->employee_note" position="top">
                                    <flux:icon.chat-bubble-bottom-center-text class="ml-1 inline size-4 text-muted-foreground" />
                                </flux:tooltip>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-right font-mono">{{ number_format((float) $request->total_hours, 2) }}</td>
                        <td class="px-4 py-2">
                            <flux:badge size="sm" :color="$request->status->color()" data-test="request-status">{{ $request->status->label() }}</flux:badge>
                        </td>
                        <td class="px-4 py-2 text-right whitespace-nowrap">
                            <flux:button variant="ghost" size="sm" wire:click="openDecide({{ $request->id }})" data-test="review-request">
                                {{ $request->status->isOpen() ? __('Review') : __('View') }}
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">{{ __('No time-off requests here.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <flux:modal name="decide-request" class="max-w-lg">
        @if ($this->deciding)
            @php($request = $this->deciding)
            <div class="space-y-5">
                <flux:heading size="lg">{{ $request->employee?->display_name }} · {{ $request->policy?->name }}</flux:heading>

                <div class="grid grid-cols-2 gap-3 text-sm">
                    <div>
                        <span class="text-muted-foreground">{{ __('Dates') }}</span>
                        <p>{{ $request->start_date->toDateString() }} → {{ $request->end_date->toDateString() }}</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Hours') }}</span>
                        <p class="font-mono">{{ number_format((float) $request->total_hours, 2) }} ({{ number_format((float) $request->hours_per_day, 2) }}/{{ __('day') }})</p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Status') }}</span>
                        <p><flux:badge size="sm" :color="$request->status->color()">{{ $request->status->label() }}</flux:badge></p>
                    </div>
                    <div>
                        <span class="text-muted-foreground">{{ __('Approver') }}</span>
                        <p>{{ $request->employee?->payrollProfile?->approver?->name ?? __('Any payroll user') }}</p>
                    </div>
                </div>

                @if ($request->employee_note)
                    <flux:callout>{{ $request->employee_note }}</flux:callout>
                @endif

                @if ($this->projection)
                    <div class="rounded-lg border border-border bg-muted/40 p-3 text-sm" data-test="balance-projection">
                        <span class="font-medium">{{ __('Balance check:') }}</span>
                        {{ __(':current h available − :pending h in flight → :projected h after', [
                            'current' => number_format($this->projection['current'], 2),
                            'pending' => number_format($this->projection['pending'], 2),
                            'projected' => number_format($this->projection['projected'], 2),
                        ]) }}
                        @if ($this->projection['projected'] < 0)
                            <p class="mt-1 text-amber-600 dark:text-amber-400">{{ __('This would take the balance negative — approving is allowed but worth a look.') }}</p>
                        @endif
                    </div>
                @endif

                @if ($request->manager_decided_at)
                    <flux:text size="sm" class="text-muted-foreground">
                        {{ __('Absence approved by :name on :date.', ['name' => $request->managerDecidedBy?->name ?? __('—'), 'date' => $request->manager_decided_at->format('Y-m-d')]) }}
                        @if ($request->manager_note) "{{ $request->manager_note }}" @endif
                    </flux:text>
                @endif

                @if ($request->status->isOpen())
                    <flux:textarea wire:model="decideNote" :label="__('Comment (sent to the employee on a final decision)')" rows="2" data-test="decide-note" />

                    @if ($request->status === TimeOffRequestStatus::ManagerApproved || $request->status === TimeOffRequestStatus::Pending)
                        <flux:switch wire:model="generateEntries" :label="__('Schedule the days as approved time entries for payroll')" />
                    @endif

                    <div class="flex flex-wrap justify-end gap-2">
                        @if ($request->status === TimeOffRequestStatus::Pending)
                            <flux:button variant="ghost" wire:click="deny" data-test="deny-request">{{ __('Deny') }}</flux:button>
                            <flux:button variant="filled" wire:click="managerApprove" data-test="manager-approve-request">{{ __('Approve absence') }}</flux:button>
                            <flux:button variant="primary" wire:click="approve" data-test="approve-request">{{ __('Approve + confirm pay') }}</flux:button>
                        @elseif ($request->status === TimeOffRequestStatus::ManagerApproved)
                            <flux:button variant="ghost" wire:click="deny" data-test="deny-request">{{ __('Deny') }}</flux:button>
                            <flux:button variant="primary" wire:click="approve" data-test="approve-request">{{ __('Confirm pay treatment') }}</flux:button>
                        @elseif ($request->status === TimeOffRequestStatus::Approved)
                            <flux:button variant="danger" wire:click="cancel" data-test="cancel-request">{{ __('Cancel request') }}</flux:button>
                        @endif
                    </div>
                @elseif ($request->decision_note)
                    <flux:text size="sm" class="text-muted-foreground">{{ __('Decision note: ":note"', ['note' => $request->decision_note]) }}</flux:text>
                @endif
            </div>
        @endif
    </flux:modal>

    <flux:modal name="request-form" class="max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ __('Record a time-off request') }}</flux:heading>

            <flux:select wire:model="f_contact_id" :label="__('Employee')" required data-test="request-employee">
                <flux:select.option value="">{{ __('— select —') }}</flux:select.option>
                @foreach ($this->employees as $employee)
                    <flux:select.option value="{{ $employee->id }}">{{ $employee->display_name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="f_policy_id" :label="__('Time-off type')" required data-test="request-policy">
                <flux:select.option value="">{{ __('— select —') }}</flux:select.option>
                @foreach ($this->policies as $policy)
                    <flux:select.option value="{{ $policy->id }}">{{ $policy->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:input type="date" wire:model="f_start_date" :label="__('First day')" required />
                <flux:input type="date" wire:model="f_end_date" :label="__('Last day')" required />
                <flux:input type="number" step="0.25" wire:model="f_hours_per_day" :label="__('Hours per day')" required inputmode="decimal" />
            </div>

            <flux:textarea wire:model="f_note" :label="__('Note')" rows="2" />

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" type="submit" data-test="request-save-button">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
