{{--
    Rationale list behind one forecast cell: the documents / post-dated entries
    that make up "Expected in" or "Expected out" for a period.

    Expects: $items (list of ['label','detail','amount_cents','kind']),
             $fmt (closure: cents → money string), $heading (string).
--}}
<div class="space-y-1.5 text-left text-xs" data-test="forecast-items">
    <p class="font-medium">{{ $heading }}</p>
    <ul class="space-y-1">
        @foreach ($items as $item)
            <li class="flex items-start justify-between gap-3">
                <span class="min-w-0">
                    <span class="block truncate">{{ $item['label'] }}</span>
                    @if ($item['detail'] !== '')
                        <span class="block text-[11px] opacity-75">{{ $item['detail'] }}</span>
                    @endif
                </span>
                <span class="shrink-0 tabular-nums">{{ $fmt($item['amount_cents']) }}</span>
            </li>
        @endforeach
    </ul>
</div>
