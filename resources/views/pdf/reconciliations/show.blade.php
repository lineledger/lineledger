@extends('pdf.reports._layout', [
    'title' => 'Bank Reconciliation',
    'period' => $rec->statement_date->toDateString(),
    'metaLines' => array_filter([
        ($rec->account?->code ?? '').' — '.($rec->account?->name ?? ''),
        'Status: '.$rec->status->label(),
        $rec->completed_at ? 'Completed: '.$rec->completed_at->toDateTimeString().($rec->completedBy ? ' — '.$rec->completedBy->name : '') : null,
    ]),
])

@section('content')
<table class="data" style="margin-bottom: 14px;">
    <thead>
        <tr><th colspan="2">Summary</th></tr>
    </thead>
    <tbody>
        <tr><td>Beginning balance</td><td class="num">{{ number_format($rec->beginning_balance_cents / 100, 2) }}</td></tr>
        <tr><td>Ending balance</td><td class="num">{{ number_format($rec->ending_balance_cents / 100, 2) }}</td></tr>
        <tr><td>Service charge</td><td class="num">{{ number_format($rec->service_charge_cents / 100, 2) }}</td></tr>
        <tr><td>Interest earned</td><td class="num">{{ number_format($rec->interest_earned_cents / 100, 2) }}</td></tr>
        <tr><td>Cleared deposits ({{ $detail['deposits']->count() }})</td><td class="num">{{ number_format($detail['deposits_total_cents'] / 100, 2) }}</td></tr>
        <tr><td>Cleared payments ({{ $detail['payments']->count() }})</td><td class="num">{{ number_format($detail['payments_total_cents'] / 100, 2) }}</td></tr>
        <tr class="subtotal"><td>Cleared balance</td><td class="num">{{ number_format($clearedBalance / 100, 2) }}</td></tr>
        <tr class="total"><td>Difference</td><td class="num @if ($difference !== 0) neg @endif">{{ number_format($difference / 100, 2) }}</td></tr>
    </tbody>
</table>

<h2 style="font-size: 13px; margin: 14px 0 4px 0;">Deposits and Other Credits</h2>
<table class="data">
    <thead>
        <tr>
            <th>Date</th>
            <th>Entry #</th>
            <th>Memo</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($detail['deposits'] as $line)
            <tr>
                <td>{{ $line->journalEntry->entry_date->toDateString() }}</td>
                <td>{{ $line->journalEntry->entry_no }}</td>
                <td>{{ $line->memo ?? $line->journalEntry->memo }}</td>
                <td class="num">{{ number_format(((int) $line->debit_cents) / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="text-align:center; color:#6b7280;">Nothing cleared on this side.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td colspan="3" style="text-align:right;">Subtotal</td><td class="num">{{ number_format($detail['deposits_total_cents'] / 100, 2) }}</td></tr>
    </tfoot>
</table>

<h2 style="font-size: 13px; margin: 14px 0 4px 0;">{{ $company->jurisdiction->chequeLabel('section') }}</h2>
<table class="data">
    <thead>
        <tr>
            <th>Date</th>
            <th>Entry #</th>
            <th>Memo</th>
            <th class="num">Amount</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($detail['payments'] as $line)
            <tr>
                <td>{{ $line->journalEntry->entry_date->toDateString() }}</td>
                <td>{{ $line->journalEntry->entry_no }}</td>
                <td>{{ $line->memo ?? $line->journalEntry->memo }}</td>
                <td class="num">{{ number_format(((int) $line->credit_cents) / 100, 2) }}</td>
            </tr>
        @empty
            <tr><td colspan="4" style="text-align:center; color:#6b7280;">Nothing cleared on this side.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td colspan="3" style="text-align:right;">Subtotal</td><td class="num">{{ number_format($detail['payments_total_cents'] / 100, 2) }}</td></tr>
    </tfoot>
</table>
@endsection
