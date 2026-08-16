{{--
    One GIFI line on the statement. Click to expand the accounts mapped to it;
    each account can be moved to another GIFI line inline.

    @param array $line     ['code', 'label', 'amount', 'accounts' => [['id','code','name','amount'], ...]]
    @param array $options  grouped reassignment options (section label => [['value','label'], ...])
--}}
<tr wire:key="gifi-line-{{ $line['code'] }}" x-data="{ open: false }" class="cursor-pointer hover:bg-muted/30" @click="open = !open" data-test="gifi-line">
    <td class="px-4 py-2 font-mono">{{ $line['code'] }}</td>
    <td class="px-4 py-2">
        <span class="inline-flex items-center gap-1">
            <flux:icon.chevron-right class="size-3 transition-transform" x-bind:class="open && 'rotate-90'" />
            {{ $line['label'] }}
            <span class="text-xs text-muted-foreground">({{ count($line['accounts']) }})</span>
        </span>
    </td>
    <td class="px-4 py-2 text-right font-mono">{{ number_format($line['amount'] / 100, 2) }}</td>
</tr>
<tr wire:key="gifi-line-detail-{{ $line['code'] }}" x-show="open" x-cloak>
    <td colspan="3" class="bg-muted/20 px-4 py-2">
        <div class="space-y-1">
            @foreach ($line['accounts'] as $account)
                <div class="flex items-center justify-between gap-3 py-1" data-test="gifi-member-account">
                    <span class="min-w-0 flex-1 truncate text-sm">
                        <span class="font-mono text-muted-foreground">{{ $account['code'] }}</span>
                        {{ $account['name'] }}
                    </span>
                    <span class="font-mono text-sm">{{ number_format($account['amount'] / 100, 2) }}</span>
                    @if ($account['id'])
                        <select
                            wire:change="reassign({{ $account['id'] }}, $event.target.value)"
                            @click.stop
                            class="rounded-md border border-input bg-background px-2 py-1 text-xs"
                            data-test="gifi-reassign-select"
                        >
                            @foreach ($options as $sectionLabel => $opts)
                                <optgroup label="{{ $sectionLabel }}">
                                    @foreach ($opts as $opt)
                                        <option value="{{ $opt['value'] }}" @selected($opt['value'] === $line['code'])>{{ $opt['label'] }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                            <option value="">{{ __('— Remove from statement —') }}</option>
                        </select>
                    @endif
                </div>
            @endforeach
        </div>
    </td>
</tr>
