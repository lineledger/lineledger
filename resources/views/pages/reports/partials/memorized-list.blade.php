@props(['reports'])

<div class="overflow-hidden rounded-lg border border-border">
    <table class="w-full text-sm">
        <tbody class="divide-y divide-border">
            @foreach ($reports as $report)
                @php $url = $this->runUrl($report); @endphp
                <tr wire:key="mem-{{ $report->id }}" data-test="memorized-row">
                    <td class="px-4 py-2">
                        <div class="font-medium">{{ $report->name }}</div>
                        <div class="text-xs text-muted-foreground">{{ $this->reportLabel($report) }}</div>
                    </td>
                    <td class="px-4 py-2 text-right">
                        <div class="flex items-center justify-end gap-2">
                            @php $schedule = $this->scheduleForReport($report->id); @endphp
                            @if ($schedule)
                                <flux:badge :color="$schedule->is_active ? 'sky' : 'amber'" size="sm" data-test="schedule-badge">
                                    {{ $schedule->is_active ? __('Scheduled').' · '.mb_strtolower($schedule->frequency->label()) : __('Schedule paused') }}
                                </flux:badge>
                                <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="deleteSchedule({{ $schedule->id }})" wire:confirm="{{ __('Remove this email schedule?') }}" data-test="schedule-delete" />
                            @endif
                            @if ($url && $this->schedulable($report))
                                <flux:button size="xs" variant="ghost" icon="clock" wire:click="openSchedule({{ $report->id }})" data-test="schedule-report">{{ __('Schedule') }}</flux:button>
                            @endif
                            @if ($url)
                                <flux:button size="xs" variant="primary" icon="play" :href="$url" wire:navigate data-test="memorized-run">{{ __('Run') }}</flux:button>
                            @else
                                <flux:tooltip :content="__('This report is no longer available for this company\'s current settings.')">
                                    <flux:badge color="zinc" size="sm" data-test="memorized-unavailable">{{ __('Unavailable') }}</flux:badge>
                                </flux:tooltip>
                            @endif
                            <flux:button size="xs" variant="ghost" icon="trash" wire:click="delete({{ $report->id }})" wire:confirm="{{ __('Delete this memorized report?') }}" data-test="memorized-delete" />
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
