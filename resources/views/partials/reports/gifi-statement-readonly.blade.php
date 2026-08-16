{{--
    Read-only GIFI-format financial statement (Schedule 100 balance sheet +
    Schedule 125 income statement) from a GifiStatementBuilder result. Used by the
    T5013 report. The interactive (account-reassignment) variant lives in the GIFI
    Statement page itself.

    @param array  $report      GifiStatementBuilder::build() output
    @param string $bsHeading   heading for the balance-sheet block
    @param string $isHeading   heading for the income-statement block
--}}
<div class="mb-8">
    <flux:heading size="lg" class="mb-3">{{ $bsHeading ?? __('Balance Sheet') }}</flux:heading>
    <div class="overflow-hidden rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('GIFI') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @foreach ($report['bs']['halves'] as $halfKey => $half)
                    @if ($half['sections'] !== [] || ($halfKey === 'equity' && $half['net_income'] !== 0))
                        <tr class="bg-muted/50"><td colspan="3" class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $half['label'] }}</td></tr>
                        @foreach ($half['sections'] as $section)
                            <tr><td colspan="3" class="px-4 pt-2 text-xs font-medium text-muted-foreground">{{ $section['label'] }}</td></tr>
                            @foreach ($section['lines'] as $line)
                                <tr>
                                    <td class="px-4 py-2 font-mono">{{ $line['code'] }}</td>
                                    <td class="px-4 py-2">{{ $line['label'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ number_format($line['amount'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        @if ($halfKey === 'equity' && $half['net_income'] !== 0)
                            <tr>
                                <td class="px-4 py-2"></td>
                                <td class="px-4 py-2 italic">{{ __('Net income for the year') }}</td>
                                <td class="px-4 py-2 text-right font-mono">{{ number_format($half['net_income'] / 100, 2) }}</td>
                            </tr>
                        @endif
                        <tr class="bg-muted/40 font-semibold">
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right">{{ __('Total') }} {{ $half['label'] }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($half['total'] / 100, 2) }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base font-semibold">
                    <td class="px-4 py-2"></td>
                    <td class="px-4 py-2 text-right">{{ __('Total assets') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($report['bs']['total_assets'] / 100, 2) }}</td>
                </tr>
                <tr class="text-base font-semibold">
                    <td class="px-4 py-2"></td>
                    <td class="px-4 py-2 text-right">{{ __('Total liabilities & equity') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($report['bs']['total_le'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<div class="mb-8">
    <flux:heading size="lg" class="mb-3">{{ $isHeading ?? __('Income Statement') }}</flux:heading>
    <div class="overflow-hidden rounded-lg border border-border">
        <table class="w-full text-sm">
            <thead class="bg-muted">
                <tr>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('GIFI') }}</th>
                    <th class="px-4 py-2 text-left font-medium text-muted-foreground">{{ __('Description') }}</th>
                    <th class="px-4 py-2 text-right font-medium text-muted-foreground">{{ __('Amount') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @php $anyIs = false; @endphp
                @foreach ($report['is']['halves'] as $half)
                    @if ($half['sections'] !== [])
                        @php $anyIs = true; @endphp
                        <tr class="bg-muted/50"><td colspan="3" class="px-4 py-1.5 text-xs font-semibold uppercase tracking-wide text-muted-foreground">{{ $half['label'] }}</td></tr>
                        @foreach ($half['sections'] as $section)
                            @foreach ($section['lines'] as $line)
                                <tr>
                                    <td class="px-4 py-2 font-mono">{{ $line['code'] }}</td>
                                    <td class="px-4 py-2">{{ $line['label'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono">{{ number_format($line['amount'] / 100, 2) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                        <tr class="bg-muted/40 font-semibold">
                            <td class="px-4 py-2"></td>
                            <td class="px-4 py-2 text-right">{{ __('Total') }} {{ $half['label'] }}</td>
                            <td class="px-4 py-2 text-right font-mono">{{ number_format($half['total'] / 100, 2) }}</td>
                        </tr>
                    @endif
                @endforeach
                @unless ($anyIs)
                    <tr><td colspan="3" class="px-4 py-8 text-center text-muted-foreground">{{ __('No income or expense activity in this period.') }}</td></tr>
                @endunless
            </tbody>
            <tfoot class="bg-muted">
                <tr class="text-base font-semibold">
                    <td class="px-4 py-2"></td>
                    <td class="px-4 py-2 text-right">{{ __('Net income (loss)') }}</td>
                    <td class="px-4 py-2 text-right font-mono">{{ number_format($report['is']['net_income'] / 100, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
