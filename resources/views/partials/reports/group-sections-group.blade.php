{{--
    Renders one anchor group's sections + an Unassigned bucket of combined lines,
    with move dropdowns and per-section controls. Expects:
      $groupKey   string
      $sections   Collection<ReportGroupSection> for this group (with `lines`)
      $unassigned Collection<ReportGroupLine> not in any of this group's sections
--}}
<div class="space-y-3">
    @forelse ($sections as $section)
        <div wire:key="rgs-section-{{ $section->id }}" class="rounded-lg border border-border" data-test="section-card" data-section="{{ $section->id }}">
            <div class="flex items-center justify-between border-b border-border px-4 py-2">
                <span class="font-medium">{{ $section->name }}</span>
                <div class="flex items-center gap-1">
                    <flux:button variant="ghost" size="xs" icon="chevron-up" wire:click="moveSectionUp({{ $section->id }})" />
                    <flux:button variant="ghost" size="xs" icon="chevron-down" wire:click="moveSectionDown({{ $section->id }})" />
                    <flux:button variant="ghost" size="xs" icon="pencil" wire:click="openEditSection({{ $section->id }})" data-test="edit-section-button" />
                    <flux:button variant="ghost" size="xs" icon="trash" wire:click="deleteSection({{ $section->id }})" wire:confirm="{{ __('Remove this section? Its lines move to Unassigned.') }}" data-test="delete-section-button" />
                </div>
            </div>
            <div class="divide-y divide-border">
                @forelse ($section->lines->sortBy('sort_order') as $line)
                    @include('partials.reports.group-section-line-row', ['line' => $line, 'sections' => $sections, 'currentTarget' => $section->id])
                @empty
                    <div class="px-4 py-2 text-sm text-muted-foreground">{{ __('No lines. Assign some below.') }}</div>
                @endforelse
            </div>
        </div>
    @empty
        <flux:text class="text-muted-foreground">{{ __('No sections yet — create one to group these lines.') }}</flux:text>
    @endforelse

    <div class="rounded-lg border border-dashed border-border" data-test="unassigned-bucket">
        <div class="border-b border-border px-4 py-2 text-sm font-medium text-muted-foreground">{{ __('Unassigned') }}</div>
        <div class="divide-y divide-border">
            @forelse ($unassigned as $line)
                @include('partials.reports.group-section-line-row', ['line' => $line, 'sections' => $sections, 'currentTarget' => 'unassigned'])
            @empty
                <div class="px-4 py-2 text-sm text-muted-foreground">{{ __('All lines are assigned to a section.') }}</div>
            @endforelse
        </div>
    </div>
</div>
