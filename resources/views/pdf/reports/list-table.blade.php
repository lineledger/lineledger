@extends('pdf.reports._layout', [
    'title' => $title,
    'period' => $period ?? null,
])

@section('content')
<table class="data">
    <thead>
        <tr>
            @foreach ($headers as $header)
                <th @if (! empty($header['num'])) class="num" @endif>{{ $header['label'] }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                @foreach ($row as $cell)
                    <td @if (! empty($cell['num'])) class="num" @endif>{{ $cell['value'] }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($headers) }}" style="text-align:center; color:#6b7280;">{{ $emptyMessage ?? 'Nothing to report.' }}</td></tr>
        @endforelse
    </tbody>
    @if (! empty($totals) && count($rows) > 0)
        <tfoot>
            <tr>
                @foreach ($totals as $cell)
                    <td @if (! empty($cell['num'])) class="num" @endif>{{ $cell['value'] }}</td>
                @endforeach
            </tr>
        </tfoot>
    @endif
</table>
@endsection
