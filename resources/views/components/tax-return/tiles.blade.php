{{-- A tax return's headline figures: the tax collected and the input tax
     credits (each including their adjustments), the other adjustments, and the
     net owing. Shared by the edit form's live preview and the show page. --}}
@props(['collected', 'paid', 'other', 'net', 'testPrefix' => 'tax-return'])

<div {{ $attributes->class('mt-4 mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4') }}>
    <div class="rounded-lg border border-border p-4">
        <div class="text-xs uppercase text-muted-foreground">{{ __('Collected') }}</div>
        <div class="text-2xl font-mono font-semibold" data-test="{{ $testPrefix }}-collected">{{ number_format($collected / 100, 2) }}</div>
    </div>
    <div class="rounded-lg border border-border p-4">
        <div class="text-xs uppercase text-muted-foreground">{{ __('Paid (ITCs)') }}</div>
        <div class="text-2xl font-mono font-semibold" data-test="{{ $testPrefix }}-paid">{{ number_format($paid / 100, 2) }}</div>
    </div>
    <div class="rounded-lg border border-border p-4">
        <div class="text-xs uppercase text-muted-foreground">{{ __('Adjustments') }}</div>
        <div class="text-2xl font-mono font-semibold" data-test="{{ $testPrefix }}-other">{{ number_format($other / 100, 2) }}</div>
    </div>
    <div class="rounded-lg border border-border p-4">
        <div class="text-xs uppercase text-muted-foreground">{{ __('Net owing') }}</div>
        <div class="text-2xl font-mono font-semibold" data-test="{{ $testPrefix }}-net">{{ number_format($net / 100, 2) }}</div>
    </div>
</div>
