{{--
    Renders one anchor group's sections + an Unassigned bucket, with move
    dropdowns and per-section controls. Expects:
      $groupKey   string
      $sections   Collection<ReportSection> for this group (with `accounts`)
      $unassigned Collection<Account> not in any of this group's sections
      $activities ?array  optional cash-flow activity move options; when present
                          each row also gets an "Activity" dropdown
--}}
@php($activities = $activities ?? null)
<div class="space-y-3">
    @forelse ($sections as $section)
        <div wire:key="rs-section-{{ $section->id }}" class="rounded-lg border border-border" data-test="section-card" data-section="{{ $section->id }}">
            <div class="flex items-center justify-between border-b border-border px-4 py-2">
                <span class="font-medium">{{ $section->name }}</span>
                <div class="flex items-center gap-1">
                    <flux:button variant="ghost" size="xs" icon="chevron-up" wire:click="moveSectionUp({{ $section->id }})" />
                    <flux:button variant="ghost" size="xs" icon="chevron-down" wire:click="moveSectionDown({{ $section->id }})" />
                    <flux:button variant="ghost" size="xs" icon="pencil" wire:click="openEditSection({{ $section->id }})" data-test="edit-section-button" />
                    <flux:button variant="ghost" size="xs" icon="trash" wire:click="deleteSection({{ $section->id }})" wire:confirm="{{ __('Remove this section? Its accounts move to Unassigned.') }}" data-test="delete-section-button" />
                </div>
            </div>
            <div class="divide-y divide-border">
                @forelse ($section->accounts->sortBy('code') as $account)
                    @include('partials.reports.section-account-row', ['account' => $account, 'sections' => $sections, 'currentTarget' => $section->id, 'activities' => $activities])
                @empty
                    <div class="px-4 py-2 text-sm text-muted-foreground">{{ __('No accounts. Assign some below.') }}</div>
                @endforelse
            </div>
        </div>
    @empty
        <flux:text class="text-muted-foreground">{{ __('No sections yet — create one to group these accounts.') }}</flux:text>
    @endforelse

    <div class="rounded-lg border border-dashed border-border" data-test="unassigned-bucket">
        <div class="border-b border-border px-4 py-2 text-sm font-medium text-muted-foreground">{{ __('Unassigned') }}</div>
        <div class="divide-y divide-border">
            @forelse ($unassigned as $account)
                @include('partials.reports.section-account-row', ['account' => $account, 'sections' => $sections, 'currentTarget' => 'unassigned', 'activities' => $activities])
            @empty
                <div class="px-4 py-2 text-sm text-muted-foreground">{{ __('All accounts are assigned to a section.') }}</div>
            @endforelse
        </div>
    </div>
</div>
