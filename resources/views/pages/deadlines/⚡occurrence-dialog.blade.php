<?php

use App\Actions\Deadlines\CompleteDeadlineOccurrence;
use App\Actions\Deadlines\ReopenDeadlineOccurrence;
use App\Actions\Deadlines\SkipDeadlineOccurrence;
use App\Actions\Deadlines\UpdateDeadlineOccurrence;
use App\Enums\Activity;
use App\Enums\DeadlineStatus;
use App\Enums\PermissionAction;
use App\Livewire\Attributes\Requires;
use App\Livewire\Concerns\HoldsEditLock;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use App\Services\AttachmentService;
use App\Services\Deadlines\DeadlineAssignees;
use App\Support\Access\CurrentViewer;
use App\Support\Deadlines\DeadlineDates;
use App\Support\Money;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
 * The dialog for one deadline occurrence, shared by the Deadlines page and a
 * deadline's own page: its details, and (for members who can complete
 * deadlines) Mark done, Not required, Reopen and Edit. Opened by the
 * `open-deadline-occurrence` browser event, or on arrival via ?occurrence=.
 */
new class extends Component {
    use HoldsEditLock;
    use WithFileUploads;

    public Company $company;

    #[Locked]
    public ?int $occurrenceId = null;

    /** view, complete, skip or edit. */
    public string $mode = 'view';

    public string $completed_on = '';

    public ?int $completed_by_user_id = null;

    public string $amount = '';

    public string $confirmation_number = '';

    public string $notes = '';

    public string $skipped_reason = '';

    public ?int $assigned_user_id = null;

    public string $due_date = '';

    /** @var array<int, mixed> */
    public array $newAttachments = [];

    public function mount(Company $company, ?int $initialId = null): void
    {
        $this->company = $company;

        if ($initialId !== null) {
            $this->open($initialId);
        }
    }

    #[On('open-deadline-occurrence')]
    public function open(int $id, string $mode = 'view'): void
    {
        $occurrence = DeadlineOccurrence::query()->with('deadline')->find($id);

        if ($occurrence === null) {
            Flux::toast(variant: 'danger', text: __('That deadline no longer exists.'));

            return;
        }

        $mode = $this->canManage && in_array($mode, ['complete', 'skip', 'edit'], true) && $this->allowsMode($occurrence, $mode)
            ? $mode
            : 'view';

        // Reading needs no lock; it's taken once someone starts to change it.
        if ($mode !== 'view') {
            if (! $this->acquireEditLock($occurrence, reopen: 'open', reopenArgs: [$id, $mode])) {
                return;
            }
        } else {
            $this->releaseEditLock();
        }

        $this->occurrenceId = $occurrence->id;
        $this->resetErrorBag();
        $this->newAttachments = [];
        $this->fillFrom($occurrence->refresh());
        $this->mode = $mode;
        unset($this->occurrence);

        Flux::modal('deadline-occurrence')->show();
    }

    public function setMode(string $mode): void
    {
        $occurrence = $this->occurrence;

        if ($occurrence === null || ! in_array($mode, ['view', 'complete', 'skip', 'edit'], true)) {
            return;
        }

        if ($mode !== 'view') {
            if (! $this->canManage || ! $this->allowsMode($occurrence, $mode)) {
                return;
            }

            if ($this->editLockToken === null && ! $this->acquireEditLock($occurrence, reopen: 'open', reopenArgs: [$occurrence->id, $mode])) {
                return;
            }

            $this->fillFrom($occurrence);
        }

        $this->resetErrorBag();
        $this->mode = $mode;
    }

    #[Requires(Activity::Deadlines, PermissionAction::Modify)]
    public function complete(CompleteDeadlineOccurrence $action): void
    {
        $occurrence = $this->lockedOccurrence();

        if ($occurrence === null) {
            return;
        }

        $this->validate(AttachmentService::uploadRules());

        $action->handle($this->company, $occurrence, [
            'completed_on' => $this->completed_on,
            'completed_by_user_id' => $this->completed_by_user_id,
            'amount' => $this->amount,
            'confirmation_number' => $this->confirmation_number,
            'notes' => $this->notes,
            'attachments' => $this->newAttachments,
        ]);

        $this->finish(__('Marked done.'));
    }

    #[Requires(Activity::Deadlines, PermissionAction::Modify)]
    public function skip(SkipDeadlineOccurrence $action): void
    {
        $occurrence = $this->lockedOccurrence();

        if ($occurrence === null) {
            return;
        }

        $action->handle($this->company, $occurrence, $this->skipped_reason);

        $this->finish(__('Marked not required.'));
    }

    #[Requires(Activity::Deadlines, PermissionAction::Modify)]
    public function reopen(ReopenDeadlineOccurrence $action): void
    {
        $occurrence = $this->lockedOccurrence();

        if ($occurrence === null) {
            return;
        }

        $action->handle($this->company, $occurrence);

        $this->finish(__('Reopened.'));
    }

    #[Requires(Activity::Deadlines, PermissionAction::Modify)]
    public function save(UpdateDeadlineOccurrence $action): void
    {
        $occurrence = $this->lockedOccurrence();

        if ($occurrence === null) {
            return;
        }

        $this->validate(AttachmentService::uploadRules());

        $action->handle($this->company, $occurrence, [
            'assigned_user_id' => $this->assigned_user_id,
            'due_date' => $this->due_date,
            'notes' => $this->notes,
            'attachments' => $this->newAttachments,
        ]);

        $this->finish(__('Saved.'));
    }

    #[Computed]
    public function occurrence(): ?DeadlineOccurrence
    {
        return $this->occurrenceId === null
            ? null
            : DeadlineOccurrence::query()
                ->with(['deadline', 'assignee', 'completedBy', 'attachments'])
                ->find($this->occurrenceId);
    }

    #[Computed]
    public function canManage(): bool
    {
        return CurrentViewer::permits(Activity::Deadlines, PermissionAction::Modify);
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function assignees(): Collection
    {
        return DeadlineAssignees::for($this->company);
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '' : Money::fromCents($cents, (string) $this->company->currency_code)->format();
    }

    private function allowsMode(DeadlineOccurrence $occurrence, string $mode): bool
    {
        return match ($mode) {
            'complete', 'skip' => $occurrence->status === DeadlineStatus::Open,
            default => true,
        };
    }

    /**
     * The open occurrence, once the edit lock is confirmed.
     */
    private function lockedOccurrence(): ?DeadlineOccurrence
    {
        if ($this->occurrenceId === null || ! $this->ensureEditLockForSave(DeadlineOccurrence::class, $this->occurrenceId)) {
            return null;
        }

        return DeadlineOccurrence::query()->findOrFail($this->occurrenceId);
    }

    private function finish(string $message): void
    {
        $this->completeEditLockSave();
        $this->newAttachments = [];
        $this->occurrenceId = null;
        unset($this->occurrence);

        Flux::modal('deadline-occurrence')->close();
        Flux::toast(variant: 'success', text: $message);
        $this->dispatch('deadline-occurrence-changed');
    }

    private function fillFrom(DeadlineOccurrence $occurrence): void
    {
        $today = $this->company->currentDateTime()->toDateString();
        $userId = Auth::id();

        $this->completed_on = $occurrence->completed_on?->toDateString() ?? $today;
        $this->completed_by_user_id = $occurrence->completed_by_user_id
            ?? (DeadlineAssignees::isEligible($this->company, $userId) ? $userId : $occurrence->assigned_user_id);
        $this->amount = $occurrence->amount_cents !== null
            ? Money::fromCents($occurrence->amount_cents)->toDecimalString()
            : ($occurrence->deadline->expected_amount_cents !== null ? Money::fromCents($occurrence->deadline->expected_amount_cents)->toDecimalString() : '');
        $this->confirmation_number = (string) $occurrence->confirmation_number;
        $this->notes = (string) $occurrence->notes;
        $this->skipped_reason = (string) $occurrence->skipped_reason;
        $this->assigned_user_id = $occurrence->assigned_user_id;
        $this->due_date = $occurrence->due_date->toDateString();
    }
}; ?>

<div>
    <flux:modal name="deadline-occurrence" class="w-full max-w-xl" wire:close="releaseEditLock">
        @if ($editLockToken)
            <x-edit-lock.keeper :config="$this->editLockKeeper" :token="$editLockToken" />
        @endif

        @php($o = $this->occurrence)
        @if ($o)
            @php($deadline = $o->deadline)
            @php($state = $o->stateOn(\App\Services\Deadlines\DeadlineFeed::dateOf($company)))
            <div class="space-y-5" data-test="deadline-occurrence-dialog">
                <div class="pr-8">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$state->color()" data-test="occurrence-state">{{ $state->label() }}</flux:badge>
                        <flux:badge size="sm" :color="$deadline->category->color()">{{ $deadline->category->label() }}</flux:badge>
                        <flux:badge size="sm" color="zinc">{{ $deadline->kind->label() }}</flux:badge>
                    </div>
                    <flux:heading size="lg" class="mt-2">
                        <a href="{{ route('deadlines.show', ['company' => $company, 'deadline' => $deadline->id]) }}" wire:navigate class="hover:underline">{{ $deadline->title }}</a>
                    </flux:heading>
                    <flux:subheading>
                        {{ __('Due :date', ['date' => DeadlineDates::long($o->due_date)]) }}
                        @if ($o->periodLabel())
                            · {{ __('Period: :period', ['period' => $o->periodLabel()]) }}
                        @endif
                    </flux:subheading>
                    @if (! $o->due_date->isSameDay($o->unadjusted_due_date))
                        <flux:text class="text-xs">{{ __('Moved from :date.', ['date' => DeadlineDates::shortWithWeekday($o->unadjusted_due_date)]) }}</flux:text>
                    @endif
                </div>

                @if ($mode === 'view')
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">{{ __('Assigned to') }}</dt>
                            <dd>{{ $o->assignee?->name ?? __('Unassigned') }}</dd>
                        </div>
                        @if ($deadline->agency_name)
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ __('Agency') }}</dt>
                                <dd>{{ $deadline->agency_name }}</dd>
                            </div>
                        @endif
                        @if ($deadline->account_reference)
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ __('Account or reference') }}</dt>
                                <dd class="font-mono">{{ $deadline->account_reference }}</dd>
                            </div>
                        @endif
                        @if ($deadline->expected_amount_cents !== null && $o->status !== DeadlineStatus::Done)
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ __('Expected amount') }}</dt>
                                <dd class="tabular-nums">{{ $this->money($deadline->expected_amount_cents) }}</dd>
                            </div>
                        @endif
                        @if ($o->status === DeadlineStatus::Done)
                            <div>
                                <dt class="text-xs text-muted-foreground">{{ __('Completed by') }}</dt>
                                <dd data-test="occurrence-completed-by">{{ $o->completedBy?->name ?? '—' }} · {{ DeadlineDates::medium($o->completed_on) }}</dd>
                            </div>
                            @if ($o->amount_cents !== null)
                                <div>
                                    <dt class="text-xs text-muted-foreground">{{ __('Amount') }}</dt>
                                    <dd class="tabular-nums">{{ $this->money($o->amount_cents) }}</dd>
                                </div>
                            @endif
                            @if ($o->confirmation_number)
                                <div>
                                    <dt class="text-xs text-muted-foreground">{{ __('Confirmation number') }}</dt>
                                    <dd class="font-mono">{{ $o->confirmation_number }}</dd>
                                </div>
                            @endif
                        @endif
                        @if ($o->status === DeadlineStatus::Skipped && $o->skipped_reason)
                            <div class="sm:col-span-2">
                                <dt class="text-xs text-muted-foreground">{{ __('Why it wasn’t required') }}</dt>
                                <dd>{{ $o->skipped_reason }}</dd>
                            </div>
                        @endif
                    </dl>

                    @if ($deadline->portal_url)
                        <flux:button size="sm" variant="filled" icon="arrow-top-right-on-square" href="{{ $deadline->portal_url }}" target="_blank" rel="noopener noreferrer" data-test="occurrence-portal-link">
                            {{ __('Open the filing site') }}
                        </flux:button>
                    @endif

                    @if ($deadline->instructions)
                        <div class="rounded-lg bg-muted p-3 text-sm">
                            <p class="mb-1 text-xs font-medium text-muted-foreground">{{ __('How to do it') }}</p>
                            <div class="whitespace-pre-line">{{ $deadline->instructions }}</div>
                        </div>
                    @endif

                    @if ($o->notes)
                        <div class="text-sm">
                            <p class="mb-1 text-xs font-medium text-muted-foreground">{{ __('Notes') }}</p>
                            <div class="whitespace-pre-line">{{ $o->notes }}</div>
                        </div>
                    @endif

                    @if ($o->attachments->isNotEmpty())
                        <div class="space-y-1">
                            <p class="text-xs font-medium text-muted-foreground">{{ __('Files') }}</p>
                            @foreach ($o->attachments as $attachment)
                                <x-attachment-link :attachment="$attachment" :company="$company" />
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canManage)
                        <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                            @if ($o->isOpen())
                                <flux:button variant="ghost" size="sm" wire:click="setMode('skip')" data-test="occurrence-skip-mode">{{ __('Not required') }}</flux:button>
                                <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="setMode('edit')" data-test="occurrence-edit-mode">{{ __('Edit') }}</flux:button>
                                <flux:button variant="primary" size="sm" icon="check" wire:click="setMode('complete')" data-test="occurrence-complete-mode">{{ __('Mark done') }}</flux:button>
                            @else
                                <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="setMode('edit')" data-test="occurrence-edit-mode">{{ __('Edit') }}</flux:button>
                                <flux:button variant="filled" size="sm" icon="arrow-uturn-left" wire:click="reopen" wire:confirm="{{ __('Reopen this deadline? Its completion details are cleared.') }}" data-test="occurrence-reopen">{{ __('Reopen') }}</flux:button>
                            @endif
                        </div>
                    @endif
                @endif

                @if ($mode === 'complete')
                    <form wire:submit="complete" class="space-y-4" data-test="occurrence-complete-form">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:input type="date" wire:model="completed_on" :label="__('Done on')" required data-test="occurrence-completed-on" />
                            <flux:select wire:model="completed_by_user_id" :label="__('Completed by')" data-test="occurrence-completed-by-select">
                                @foreach ($this->assignees as $member)
                                    <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            @if ($deadline->kind->involvesPayment())
                                <flux:input wire:model="amount" :label="__('Amount paid')" placeholder="0.00" inputmode="decimal" data-test="occurrence-amount" />
                            @endif
                            <flux:input wire:model="confirmation_number" :label="__('Confirmation number')" data-test="occurrence-confirmation" />
                        </div>
                        <flux:textarea wire:model="notes" :label="__('Notes')" rows="2" data-test="occurrence-notes" />

                        <x-attachment-dropzone model="newAttachments" :label="__('Attach the confirmation or receipt')" />
                        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                        @if (count($newAttachments) > 0)
                            <flux:text class="text-xs">{{ trans_choice(':count file ready to attach|:count files ready to attach', count($newAttachments), ['count' => count($newAttachments)]) }}</flux:text>
                        @endif

                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="setMode('view')">{{ __('Back') }}</flux:button>
                            <flux:button variant="primary" type="submit" icon="check" data-test="occurrence-complete-save">{{ __('Mark done') }}</flux:button>
                        </div>
                    </form>
                @endif

                @if ($mode === 'skip')
                    <form wire:submit="skip" class="space-y-4" data-test="occurrence-skip-form">
                        <flux:input wire:model="skipped_reason" :label="__('Why isn’t it required?')" :placeholder="__('e.g. No payroll this period')" data-test="occurrence-skip-reason" />
                        @error('skipped_reason') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="setMode('view')">{{ __('Back') }}</flux:button>
                            <flux:button variant="primary" type="submit" data-test="occurrence-skip-save">{{ __('Mark not required') }}</flux:button>
                        </div>
                    </form>
                @endif

                @if ($mode === 'edit')
                    <form wire:submit="save" class="space-y-4" data-test="occurrence-edit-form">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <flux:select wire:model="assigned_user_id" :label="__('Assigned to')" data-test="occurrence-assignee">
                                <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                                @foreach ($this->assignees as $member)
                                    <flux:select.option value="{{ $member->id }}">{{ $member->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:input type="date" wire:model="due_date" :label="__('Due date')" :description="__('For an extension, or a holiday the rule doesn’t know.')" required data-test="occurrence-due-date" />
                        </div>
                        <flux:textarea wire:model="notes" :label="__('Notes')" rows="3" data-test="occurrence-edit-notes" />
                        <x-attachment-dropzone model="newAttachments" :label="__('Attach files')" />
                        @error('newAttachments.*') <flux:text class="text-sm text-red-600">{{ $message }}</flux:text> @enderror
                        <flux:text class="text-xs">{{ __('Edited deadlines keep their changes when the schedule is changed.') }}</flux:text>
                        <div class="flex justify-end gap-2">
                            <flux:button variant="ghost" wire:click="setMode('view')">{{ __('Back') }}</flux:button>
                            <flux:button variant="primary" type="submit" data-test="occurrence-edit-save">{{ __('Save') }}</flux:button>
                        </div>
                    </form>
                @endif
            </div>
        @endif
    </flux:modal>

    <x-edit-lock.takeover-modal />
</div>
