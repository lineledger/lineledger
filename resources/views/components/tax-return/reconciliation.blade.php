{{-- A tax return set beside the agency's payable account in the ledger, the
     way a bookkeeper reconciles one by hand: what the return says is owed, and
     what the ledger will say is owed once filing has posted the adjustments.
     `reconciliation` is the array TaxReturnCalculator builds (also frozen on a
     filed return); every ledger figure is its effect on what is owed, so tax
     collected is positive and ITCs and payments are negative. --}}
@props([
    'reconciliation',
    'account' => null,
    'periodStart' => null,
    'periodEnd' => null,
    'ledgerLines' => null,
])

@php
    $r = $reconciliation;
    $optional = fn (string $key) => (int) ($r[$key] ?? 0);

    $returnRows = array_values(array_filter([
        ['label' => __('Tax collected'), 'cents' => $optional('return_collected_cents'), 'always' => true, 'test' => 'return-collected'],
        ['label' => __('ITCs'), 'cents' => $optional('return_itc_cents'), 'always' => true, 'test' => 'return-itc'],
        ['label' => __('Other adjustments'), 'cents' => $optional('return_other_cents'), 'always' => false, 'test' => 'return-other'],
    ], fn (array $row) => $row['always'] || $row['cents'] !== 0));

    $ledgerRows = array_values(array_filter([
        ['label' => $periodStart ? __('Opening balance, :date', ['date' => $periodStart]) : __('Opening balance'), 'cents' => $optional('opening_cents'), 'always' => true, 'test' => 'opening'],
        ['label' => __('Opening-balance entries'), 'cents' => $optional('opening_entries_cents'), 'always' => false, 'test' => 'opening-entries'],
        ['label' => __('Payments and refunds'), 'cents' => $optional('payments_cents'), 'always' => true, 'test' => 'payments'],
        ['label' => __('Tax collected'), 'cents' => $optional('collected_cents'), 'always' => true, 'test' => 'collected'],
        ['label' => __('ITCs'), 'cents' => $optional('itc_cents'), 'always' => true, 'test' => 'itc'],
        ['label' => __('Left off the return'), 'cents' => $optional('excluded_cents'), 'always' => false, 'test' => 'excluded'],
        ['label' => __('Earlier returns’ adjustments'), 'cents' => $optional('prior_adjustments_cents'), 'always' => false, 'test' => 'prior-adjustments'],
        ['label' => __('Other entries'), 'cents' => $optional('unclassified_cents'), 'always' => false, 'test' => 'unclassified'],
    ], fn (array $row) => $row['always'] || $row['cents'] !== 0));

    $difference = $optional('difference_cents');
    $accepted = $r['accepted_difference_cents'] ?? null;
    $accountLabel = $account ? "{$account->code} — {$account->name}" : __('Tax payable account');
@endphp

<div {{ $attributes->class('rounded-lg border border-border') }} data-test="tax-return-reconciliation">
    <div class="border-b border-border px-4 py-3">
        <flux:heading size="lg">{{ __('Reconciliation to :account', ['account' => $accountLabel]) }}</flux:heading>
    </div>

    <div class="grid grid-cols-1 divide-y divide-border md:grid-cols-2 md:divide-x md:divide-y-0">
        <div class="p-4">
            <div class="mb-2 text-xs font-medium uppercase text-muted-foreground">{{ __('Tax return') }}</div>
            <dl class="space-y-1 text-sm">
                @foreach ($returnRows as $row)
                    <div class="flex justify-between gap-4">
                        <dt>{{ $row['label'] }}</dt>
                        <dd class="font-mono" data-test="reconciliation-{{ $row['test'] }}">{{ number_format($row['cents'] / 100, 2) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-4 border-t border-border pt-1 font-semibold">
                    <dt>{{ __('Net owing') }}</dt>
                    <dd class="font-mono" data-test="reconciliation-return-net">{{ number_format($optional('return_net_cents') / 100, 2) }}</dd>
                </div>
            </dl>
        </div>

        <div class="p-4">
            <div class="mb-2 text-xs font-medium uppercase text-muted-foreground">{{ __('General ledger') }}</div>
            <dl class="space-y-1 text-sm">
                @foreach ($ledgerRows as $row)
                    <div class="flex justify-between gap-4">
                        <dt>{{ $row['label'] }}</dt>
                        <dd class="font-mono" data-test="reconciliation-{{ $row['test'] }}">{{ number_format($row['cents'] / 100, 2) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between gap-4 border-t border-border pt-1">
                    <dt>{{ $periodEnd ? __('Balance, :date', ['date' => $periodEnd]) : __('Balance at period end') }}</dt>
                    <dd class="font-mono" data-test="reconciliation-closing">{{ number_format($optional('closing_cents') / 100, 2) }}</dd>
                </div>
                @if ($optional('to_post_cents') !== 0)
                    <div class="flex justify-between gap-4">
                        <dt>{{ __('Adjustments posted on filing') }}</dt>
                        <dd class="font-mono" data-test="reconciliation-to-post">{{ number_format($optional('to_post_cents') / 100, 2) }}</dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4 border-t border-border pt-1 font-semibold">
                    <dt>{{ __('Balance after filing') }}</dt>
                    <dd class="font-mono" data-test="reconciliation-projected">{{ number_format($optional('projected_cents') / 100, 2) }}</dd>
                </div>
            </dl>
        </div>
    </div>

    <div @class([
        'flex flex-wrap items-center justify-between gap-2 border-t border-border px-4 py-3 text-sm',
        'bg-emerald-50 dark:bg-emerald-950' => $difference === 0,
        'bg-amber-50 dark:bg-amber-950' => $difference !== 0,
    ])>
        <span class="font-semibold">{{ __('Difference') }}</span>
        <span class="font-mono font-semibold" data-test="reconciliation-difference">{{ number_format($difference / 100, 2) }}</span>
        @if ($accepted !== null && $accepted !== 0)
            <span class="w-full text-xs text-muted-foreground">{{ __('Filed with this difference accepted.') }}</span>
        @endif
    </div>

    @if ($ledgerLines && $ledgerLines->isNotEmpty())
        <div class="border-t border-border px-4 py-3">
            <div class="mb-2 text-xs font-medium uppercase text-muted-foreground">{{ __('Also on :account this period — not part of the return', ['account' => $accountLabel]) }}</div>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border">
                    @foreach ($ledgerLines as $line)
                        <tr wire:key="ledger-line-{{ $line['journal_line_id'] }}">
                            <td class="py-1 pr-4 whitespace-nowrap">{{ $line['entry_date']->toDateString() }}</td>
                            <td class="py-1 pr-4 font-mono">{{ $line['entry_no'] }}</td>
                            <td class="py-1 pr-4">{{ $line['doc_label'] }}</td>
                            <td class="py-1 pr-4"><x-tax-return.bucket-badge :bucket="$line['bucket']" /></td>
                            <td class="py-1 text-right font-mono">{{ number_format($line['balance_effect_cents'] / 100, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
