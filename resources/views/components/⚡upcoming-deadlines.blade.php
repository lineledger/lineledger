<?php

use App\Enums\Activity;
use App\Enums\PermissionAction;
use App\Models\Company;
use App\Models\DeadlineOccurrence;
use App\Models\User;
use App\Services\Deadlines\DeadlineFeed;
use App\Support\Access\CurrentViewer;
use App\Support\Deadlines\DeadlineDates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/*
 * Dashboard card: deadlines that are overdue or due in the next two weeks,
 * the viewer's own first. Hidden from members who can't open Deadlines, and
 * when nothing is coming up.
 */
new class extends Component
{
    /** Rows shown before "View all". */
    public const int LIMIT = 6;

    public const int LOOKAHEAD_DAYS = 14;

    public ?Company $company = null;

    /** Show everyone's deadlines, not just the viewer's (and unassigned ones). */
    public bool $everyone = false;

    public function mount(): void
    {
        $this->company = app()->bound('current_company') ? app('current_company') : null;
    }

    #[Computed]
    public function visible(): bool
    {
        return $this->company !== null && CurrentViewer::permits(Activity::Deadlines, PermissionAction::View);
    }

    /**
     * @return Collection<int, DeadlineOccurrence>
     */
    #[Computed]
    public function occurrences(): Collection
    {
        $user = Auth::user();

        if (! $this->visible || ! $user instanceof User) {
            return collect();
        }

        $feed = DeadlineFeed::for($this->company);

        if (! $this->everyone) {
            return $feed->needingAttention($user, self::LOOKAHEAD_DAYS, $feed->mayComplete($user), self::LIMIT + 1);
        }

        return $feed->query()
            ->open()
            ->where('due_date', '<=', $feed->today()->addDays(self::LOOKAHEAD_DAYS)->toDateString())
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(self::LIMIT + 1)
            ->get();
    }

    #[Computed]
    public function hasAny(): bool
    {
        if (! $this->visible) {
            return false;
        }

        $feed = DeadlineFeed::for($this->company);

        return $feed->query()->open()
            ->where('due_date', '<=', $feed->today()->addDays(self::LOOKAHEAD_DAYS)->toDateString())
            ->exists();
    }
}; ?>

<div>
    @if ($this->hasAny)
        @php($today = DeadlineFeed::dateOf($company))
        <section class="mb-6 rounded-xl border border-border p-5" data-test="upcoming-deadlines">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">{{ __('Upcoming deadlines') }}</flux:heading>
                <div class="flex items-center gap-3">
                    <flux:switch wire:model.live="everyone" :label="__('Everyone’s')" data-test="upcoming-deadlines-everyone" />
                    <a href="{{ route('deadlines.index', ['company' => $company, 'view' => 'list']) }}" wire:navigate class="text-sm text-accent hover:underline">{{ __('View all') }}</a>
                </div>
            </div>

            @if ($this->occurrences->isEmpty())
                <flux:text class="text-sm">{{ __('Nothing of yours is due in the next two weeks.') }}</flux:text>
            @else
                <ul class="divide-y divide-border">
                    @foreach ($this->occurrences->take($this::LIMIT) as $o)
                        @php($state = $o->stateOn($today))
                        <li wire:key="upcoming-{{ $o->id }}">
                            <a href="{{ route('deadlines.index', ['company' => $company, 'view' => 'list', 'occurrence' => $o->id]) }}" wire:navigate
                                class="-mx-2 flex items-center gap-3 rounded-md px-2 py-2 hover:bg-muted" data-test="upcoming-deadline">
                                <span class="w-16 shrink-0 text-xs tabular-nums text-muted-foreground sm:w-24 sm:text-sm">{{ DeadlineDates::shortWithWeekday($o->due_date) }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium sm:truncate">{{ $o->deadline->title }}</span>
                                    <span class="block truncate text-xs text-muted-foreground">
                                        {{ $o->periodLabel() }}@if ($o->periodLabel()) · @endif{{ $o->assignee?->name ?? __('Unassigned') }}
                                    </span>
                                </span>
                                <flux:badge size="sm" :color="$state->color()">{{ $state->label() }}</flux:badge>
                            </a>
                        </li>
                    @endforeach
                </ul>
                @if ($this->occurrences->count() > $this::LIMIT)
                    <a href="{{ route('deadlines.index', ['company' => $company, 'view' => 'list']) }}" wire:navigate class="mt-2 inline-block text-sm text-accent hover:underline">{{ __('More deadlines…') }}</a>
                @endif
            @endif
        </section>
    @endif
</div>
