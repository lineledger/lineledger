@php use App\Support\Statutory\StatutoryFormat as F; @endphp
<table class="fs" style="margin-left: 8mm; width: 92%;">
    <tr><td></td><th class="num cy">{{ $cy }}<br>£</th>@if ($hasPrior)<th class="num" style="font-weight:normal;">{{ $py }}<br>£</th>@endif</tr>
    @foreach ($rows as $i => $row)
        @php $lastRow = $i === count($rows) - 1; @endphp
        <tr @if ($i === 0) class="gap" @endif>
            <td>{{ $row['label'] }}</td>
            <td class="num cy {{ ! $total && $lastRow ? 'double' : '' }}">{{ F::amount($row['current']) }}</td>
            @if ($hasPrior)<td class="num {{ ! $total && $lastRow ? 'double' : '' }}">{{ F::amount($row['prior']) }}</td>@endif
        </tr>
    @endforeach
    @if ($total)
        <tr class="total">
            <td></td>
            <td class="num cy line-top double">{{ F::amount(array_sum(array_column($rows, 'current'))) }}</td>
            @if ($hasPrior)<td class="num line-top double" style="font-weight:normal;">{{ F::amount(array_sum(array_column($rows, 'prior'))) }}</td>@endif
        </tr>
    @endif
</table>
