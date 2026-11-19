<?php

use App\Actions\Deadlines\ArchiveDeadline;
use App\Enums\Activity;
use App\Enums\DeadlineState;
use App\Enums\DeadlineStatus;
use App\Enums\PermissionAction;
use App\Livewire\Attributes\GuardsEditLock;
use App\Livewire\Attributes\Requires;
use App\Livewire\Concerns\ShowsEditLock;
use App\Models\Company;
use App\Models\Deadline;
use App\Models\DeadlineOccurrence;
use App\Services\Deadlines\DeadlineRuleDescriber;
use App\Support\Deadlines\DeadlineDates;
use App\Support\Money;
use App\Support\Reporting\ReportDatePresets;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Deadline')] class extends Component {
    use ShowsEditLock;

    public Company $company;

    public Deadline $deadline;

    protected function editLockRecord(): ?Model
    {
        return $this->deadline;
    }

    public function mount(Company $company, Deadline $deadline): void
    {
        abort_unless($deadline->company_id === $company->id, 404);

        $this->company = $company;
        $this->deadline = $deadline;
    }

    #[On('deadline-occurrence-changed')]
    public function refreshOccurrences(): void
    {
        unset($this->years);
    }

    #[Requires(Activity::Deadlines, PermissionAction::Modify)]
    #[GuardsEditLock]
    public function pause(ArchiveDeadline $action): void
    {
        $this->deadline = $action->pause($this->company, $this->deadline);
        unset($this->years);
        Flux::toast(variant: 'success', text: __('Paused. No new due dates are added until you resume it.'));
    }

    #[Requires(Activity::Deadlines, PermissionAction::Modify)]
    #[GuardsEditLock]
    public function resume(ArchiveDeadline $action): void
    {
        $this->deadline = $action->resume($this->company, $this->deadline);
        unset($this->years);
        Flux::toast(variant: 'success', text: __('Resumed.'));
    }

    #[Requires(Activity::Deadlines, PermissionAction::Delete)]
    #[GuardsEditLock]
    public function archive(ArchiveDeadline $action): void
    {
        $this->deadline = $action->archive($this->company, $this->deadline);
        unset($this->years);
        Flux::toast(variant: 'success', text: __('Archived. Its history is kept.'));
    }

    #[Requires(Activity::Deadlines, PermissionAction::Delete)]
    #[GuardsEditLock]
    public function unarchive(ArchiveDeadline $action): void
    {
        $this->deadline = $action->restore($this->company, $this->deadline);
        unset($this->years);
        Flux::toast(variant: 'success', text: __('Restored.'));
    }

    #[Computed]
    public function today(): CarbonImmutable
    {
        return \App\Services\Deadlines\DeadlineFeed::dateOf($this->company);
    }

    /**
     * Every occurrence, newest fiscal year first.
     *
     * @return Collection<string, Collection<int, DeadlineOccurrence>>
     */
    #[Computed]
    public function years(): Collection
    {
        $startMonth = max(1, min(12, (int) ($this->company->fiscal_year_start_month ?: 1)));

        return DeadlineOccurrence::query()
            ->with(['assignee', 'completedBy', 'attachments'])
            ->where('deadline_id', $this->deadline->id)
            ->orderBy('occurrence_key')
            ->get()
            ->groupBy(function (DeadlineOccurrence $o) use ($startMonth): string {
                $start = ReportDatePresets::fiscalYearStart($o->period_start ?? $o->due_date, $startMonth);
                $end = $start->addYear()->subDay();

                return $start->year === $end->year
                    ? (string) $start->year
                    : DeadlineDates::monthYear($start).' – '.DeadlineDates::monthYear($end);
            })
            ->reverse();
    }

    #[Computed]
    public function ruleSentence(): string
    {
        return DeadlineRuleDescriber::describe($this->deadline);
    }

    public function money(?int $cents): string
    {
        return $cents === null ? '' : Money::fromCents($cents, (string) $this->company->currency_code)->format();
    }
}; ?>

<section class="w-full">
    <x-edit-lock.banner :lock="$this->editLockBanner" />

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <a href="{{ route('deadlines.index', ['company' => $company, 'view' => 'all']) }}" wire:navigate class="text-sm text-muted-foreground hover:underline">← {{ __('Deadlines') }}</a>
            <flux:heading size="xl" level="1" class="mt-1" data-test="page-title">{{ $deadline->title }}</flux:heading>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge size="sm" :color="$deadline->category->color()">{{ $deadline->category->label() }}</flux:badge>
                <flux:badge size="sm" color="zinc">{{ $deadline->kind->label() }}</flux:badge>
                @if ($deadline->isArchived())
                    <flux:badge size="sm" color="zinc" data-test="deadline-archived">{{ __('Archived') }}</flux:badge>
                @elseif (! $deadline->is_active)
                    <flux:badge size="sm" color="amber" data-test="deadline-paused">{{ __('Paused') }}</flux:badge>
                @endif
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if (! $deadline->isArchived())
                @callable($this, 'pause')
                    @if ($deadline->is_active)
                        <flux:button variant="ghost" size="sm" icon="pause" wire:click="pause" data-test="deadline-pause">{{ __('Pause') }}</flux:button>
                    @else
                        <flux:button variant="ghost" size="sm" icon="play" wire:click="resume" data-test="deadline-resume">{{ __('Resume') }}</flux:button>
                    @endif
                @endcallable
                @callable($this, 'archive')
                    <flux:button variant="ghost" size="sm" icon="archive-box" wire:click="archive" wire:confirm="{{ __('Archive this deadline? Open due dates nobody has touched are removed; completed ones are kept.') }}" data-test="deadline-archive">{{ __('Archive') }}</flux:button>
                @endcallable
            @else
                @callable($this, 'unarchive')
                    <flux:button variant="ghost" size="sm" icon="arrow-uturn-left" wire:click="unarchive" data-test="deadline-unarchive">{{ __('Restore') }}</flux:button>
                @endcallable
            @endif
            @reaches('deadlines.create')
                <flux:button variant="ghost" size="sm" icon="document-duplicate" :href="route('deadlines.create', ['company' => $company, 'copy' => $deadline->id])" wire:navigate data-test="deadline-duplicate">{{ __('Duplicate') }}</flux:button>
            @endreaches
            @if (! $deadline->isArchived())
                @reaches('deadlines.edit')
                    <flux:button variant="primary" size="sm" icon="pencil-square" :href="route('deadlines.edit', ['company' => $company, 'deadline' => $deadline])" wire:navigate data-test="deadline-edit">{{ __('Edit') }}</flux:button>
                @endreaches
            @endif
        </div>
    </div>

    <div class="mb-8 grid grid-cols-1 gap-4 lg:grid-cols-3">
        <dl class="grid grid-cols-1 gap-x-6 gap-y-3 rounded-lg border border-border p-4 text-sm sm:grid-cols-2 lg:col-span-2">
            <div class="sm:col-span-2">
                <dt class="text-xs text-muted-foreground">{{ __('Schedule') }}</dt>
                <dd data-test="deadline-rule">{{ $this->ruleSentence }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">{{ __('Assigned to') }}</dt>
                <dd>{{ $deadline->assignee?->name ?? __('Unassigned') }}</dd>
            </div>
            <div>
                <dt class="text-xs text-muted-foreground">{{ __('Email reminders') }}</dt>
                <dd>
                    {{ collect($deadline->leadDays())->map(fn ($d) => $d === 0 ? __('on the day') : trans_choice(':count day before|:count days before', $d, ['count' => $d]))->implode(', ') ?: __('None') }}
                </dd>
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
            @if ($deadline->expected_amount_cents !== null)
                <div>
                    <dt class="text-xs text-muted-foreground">{{ __('Usual amount') }}</dt>
                    <dd class="tabular-nums">{{ $this->money($deadline->expected_amount_cents) }}</dd>
                </div>
            @endif
            @if ($deadline->portal_url)
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">{{ __('Filing site') }}</dt>
                    <dd><a href="{{ $deadline->portal_url }}" target="_blank" rel="noopener noreferrer" class="break-all text-accent hover:underline">{{ $deadline->portal_url }}</a></dd>
                </div>
            @endif
        </dl>

        <div class="rounded-lg border border-border p-4 text-sm">
            <p class="mb-1 text-xs text-muted-foreground">{{ __('How to do it') }}</p>
            <div class="whitespace-pre-line">{{ $deadline->instructions ?: __('No instructions yet.') }}</div>
        </div>
    </div>

    <div class="space-y-6">
        @forelse ($this->years as $label => $rows)
            <div wire:key="year-{{ $label }}">
                <flux:heading size="sm" class="mb-2">{{ __('Fiscal year :label', ['label' => $label]) }}</flux:heading>
                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm" data-test="deadline-history">
                        <thead class="bg-muted">
                            <tr>
                                <th class="px-4 py-2 text-left">{{ __('Period') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Due') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Status') }}</th>
                                <th class="px-4 py-2 text-left">{{ __('Assigned to') }}</th>
                                <th class="px-4 py-2 text-right">{{ __('Amount') }}</th>
                                <th class="hidden px-4 py-2 text-left md:table-cell">{{ __('Notes') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $o)
                                @php($state = $o->stateOn($this->today))
                                <tr class="cursor-pointer border-t border-border hover:bg-muted/50" wire:key="hist-{{ $o->id }}" wire:click="$dispatch('open-deadline-occurrence', { id: {{ $o->id }} })" data-test="deadline-history-row">
                                    <td class="px-4 py-2">{{ $o->periodLabel() ?? '—' }}</td>
                                    <td class="whitespace-nowrap px-4 py-2">{{ DeadlineDates::mediumWithWeekday($o->due_date) }}</td>
                                    <td class="px-4 py-2">
                                        <flux:badge size="sm" :color="$state->color()">{{ $state->label() }}</flux:badge>
                                        @if ($o->status === DeadlineStatus::Done)
                                            <span class="block text-xs text-muted-foreground">{{ DeadlineDates::medium($o->completed_on) }} · {{ $o->completedBy?->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">{{ $o->assignee?->name ?? __('Unassigned') }}</td>
                                    <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ $this->money($o->amount_cents) }}</td>
                                    <td class="hidden max-w-xs truncate px-4 py-2 text-muted-foreground md:table-cell" title="{{ $o->notes }}">
                                        @if ($o->attachments->isNotEmpty())
                                            <flux:icon.paper-clip class="inline size-3.5" />
                                        @endif
                                        {{ $o->notes ?: $o->confirmation_number }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                {{ __('No due dates yet. Check the schedule and the "Track from" date.') }}
            </div>
        @endforelse
    </div>

    <div class="mt-8">
        <livewire:audit-trail :auditable-type="Deadline::class" :auditable-id="$deadline->id" />
    </div>

    <livewire:pages::deadlines.occurrence-dialog :company="$company" />
</section>
