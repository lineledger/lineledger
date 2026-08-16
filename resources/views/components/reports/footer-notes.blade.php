@props([
    'reportNotes' => '',
])

{{--
    Footer notes for a report (QuickBooks "Footer" tab). Rendered inside a
    report's Livewire view; the host must use the HasReportNotes concern and
    pass :report-notes="$reportNotes" (server-renders the current value and
    opens the disclosure when notes exist). Notes flow to the PDF via
    exportPdf's 'notes' key and persist through Memorize. wire:ignore.self
    keeps the user's open/closed toggle from being undone by re-renders.
--}}
<details class="mt-4" wire:ignore.self data-test="report-notes-section" @if (trim($reportNotes) !== '') open @endif>
    <summary class="cursor-pointer text-sm text-muted-foreground hover:underline">{{ __('Report notes') }}</summary>
    <div class="mt-2 max-w-2xl">
        <flux:textarea
            wire:model.live.debounce.500ms="reportNotes"
            rows="3"
            maxlength="4000"
            data-test="report-notes"
            :description="__('Shown on screen and on the PDF. Saved when you memorize the report.')"
        >{{ $reportNotes }}</flux:textarea>
    </div>
</details>
