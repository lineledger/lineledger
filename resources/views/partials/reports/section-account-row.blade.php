{{--
    A single account row with a move dropdown. Expects:
      $account       Account
      $sections      Collection<ReportSection> (move targets for this group)
      $currentTarget int|string  the section id this account sits in, or 'unassigned'
      $activities    ?array  optional cash-flow activity options; when present an
                             extra "Activity" dropdown re-routes the account across
                             the Operating/Investing/Financing anchors
--}}
@php($activities = $activities ?? null)
@php($currentActivity = $activities ? \App\Support\Reporting\CashFlowBucket::for($account) : null)
<div wire:key="rs-acct-{{ $account->id }}" class="flex items-center justify-between gap-2 px-4 py-2 text-sm" data-test="account-row" data-account="{{ $account->id }}">
    <span class="truncate">{{ $account->code }} — {{ $account->name }}</span>
    <div class="flex items-center gap-2">
        @isset($activities)
            <select
                wire:key="rs-activity-{{ $account->id }}-{{ $currentActivity }}"
                class="rounded-md border border-border bg-card px-2 py-1 text-xs"
                wire:change="moveAccountToActivity({{ $account->id }}, $event.target.value)"
                data-test="move-activity-select"
            >
                @foreach ($activities as $activity)
                    <option value="{{ $activity['value'] }}" @selected($currentActivity === $activity['value'])>{{ $activity['label'] }}</option>
                @endforeach
            </select>
        @endisset
        <select
            wire:key="rs-sel-{{ $account->id }}-{{ $currentTarget }}"
            class="rounded-md border border-border bg-card px-2 py-1 text-xs"
            wire:change="moveAccount({{ $account->id }}, $event.target.value)"
            data-test="move-account-select"
        >
            @foreach ($sections as $target)
                <option value="{{ $target->id }}" @selected($currentTarget === $target->id)>{{ $target->name }}</option>
            @endforeach
            <option value="unassigned" @selected($currentTarget === 'unassigned')>{{ __('Unassigned') }}</option>
        </select>
    </div>
</div>
