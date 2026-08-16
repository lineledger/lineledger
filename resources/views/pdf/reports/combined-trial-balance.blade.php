@extends('pdf.reports._combined-layout', [
    'title' => 'Combined Trial Balance',
    'period' => 'as of '.$asOf,
])

@section('content')
<table class="data">
    <thead>
        <tr><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr>
    </thead>
    <tbody>
        @foreach ($report['companies'] as $sectionRow)
            <tr class="section"><td colspan="3">{{ $sectionRow['company']['name'] }}</td></tr>
            @foreach ($sectionRow['rows'] as $row)
                <tr>
                    <td style="padding-left: 16px;">{{ $row['code'] }} — {{ $row['name'] }}</td>
                    <td class="num">{{ $row['debit'] ? number_format($row['debit'] / 100, 2) : '' }}</td>
                    <td class="num">{{ $row['credit'] ? number_format($row['credit'] / 100, 2) : '' }}</td>
                </tr>
            @endforeach
            <tr class="subtotal">
                <td style="text-align:right;">Subtotal</td>
                <td class="num">{{ number_format($sectionRow['total_debit'] / 100, 2) }}</td>
                <td class="num">{{ number_format($sectionRow['total_credit'] / 100, 2) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr class="total">
            <td style="text-align:right;">Total</td>
            <td class="num">{{ number_format($report['total_debit'] / 100, 2) }}</td>
            <td class="num">{{ number_format($report['total_credit'] / 100, 2) }}</td>
        </tr>
    </tfoot>
</table>
@endsection
