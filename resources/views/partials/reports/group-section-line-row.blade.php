{{--
    A single combined-line row with a move dropdown. Expects:
      $line          ReportGroupLine
      $sections      Collection<ReportGroupSection> (move targets for this group)
      $currentTarget int|string  the section id this line sits in, or 'unassigned'
      $activities    ?array  optional cash-flow activity options; when present an
                             extra "Activity" dropdown re-routes the line across
                             the Operating/Investing/Financing anchors
--}}
@php($activities = $activities ?? null)
@php($currentActivity = $activities ? \App\Support\Reporting\CashFlowBucket::forLine($line) : null)
<div wire:key="rgs-line-{{ $line->id }}" class="flex items-center justify-between gap-2 px-4 py-2 text-sm" data-test="line-row" data-line="{{ $line->id }}">
    <span class="truncate">{{ $line->name }}</span>
    <div class="flex items-center gap-2">
        @isset($activities)
            <select
                wire:key="rgs-activity-{{ $line->id }}-{{ $currentActivity }}"
                class="rounded-md border border-border bg-card px-2 py-1 text-xs"
                wire:change="moveLineToActivity({{ $line->id }}, $event.target.value)"
                data-test="move-activity-select"
            >
                @foreach ($activities as $activity)
                    <option value="{{ $activity['value'] }}" @selected($currentActivity === $activity['value'])>{{ $activity['label'] }}</option>
                @endforeach
            </select>
        @endisset
        <select
            wire:key="rgs-sel-{{ $line->id }}-{{ $currentTarget }}"
            class="rounded-md border border-border bg-card px-2 py-1 text-xs"
            wire:change="moveLine({{ $line->id }}, $event.target.value)"
            data-test="move-line-select"
        >
            @foreach ($sections as $target)
                <option value="{{ $target->id }}" @selected($currentTarget === $target->id)>{{ $target->name }}</option>
            @endforeach
            <option value="unassigned" @selected($currentTarget === 'unassigned')>{{ __('Unassigned') }}</option>
        </select>
    </div>
</div>
