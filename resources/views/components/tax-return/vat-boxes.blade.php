{{-- The HMRC nine-box view of a UK tax return — the figures to copy onto
     HMRC's VAT return. Boxes 1–5 carry pence; Boxes 6–9 are whole pounds. --}}
@props(['boxes', 'symbol' => '£', 'filed' => false])

<div {{ $attributes->class('mb-6 rounded-lg border border-border') }} data-test="vat-boxes">
    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-border px-4 py-3">
        <flux:heading size="lg">{{ __('VAT return (nine boxes)') }}</flux:heading>
        <flux:text class="text-xs text-muted-foreground">
            {{ $filed ? __('As filed.') : __('Worked out live until the return is filed.') }}
        </flux:text>
    </div>
    <dl class="grid grid-cols-1 divide-y divide-border text-sm sm:grid-cols-[auto_1fr_auto] sm:divide-y-0">
        @foreach ($boxes->rows() as $row)
            <div @class([
                'contents',
            ])>
                <dt class="px-4 py-2 font-mono text-muted-foreground sm:border-t sm:border-border">{{ __('Box :n', ['n' => $row['box']]) }}</dt>
                <dd class="px-4 py-2 sm:border-t sm:border-border @if (in_array($row['box'], [3, 5], true)) font-semibold @endif">{{ $row['label'] }}</dd>
                <dd class="px-4 py-2 text-right font-mono sm:border-t sm:border-border @if (in_array($row['box'], [3, 5], true)) font-semibold @endif" data-test="vat-box-{{ $row['box'] }}-amount">
                    {{ $symbol }}{{ $row['whole_pounds'] ? number_format(intdiv($row['cents'], 100)) : number_format($row['cents'] / 100, 2) }}
                </dd>
            </div>
        @endforeach
    </dl>
</div>
