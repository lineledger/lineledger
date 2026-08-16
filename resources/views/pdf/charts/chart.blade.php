@extends('pdf.reports._layout', [
    'title' => $title ?? 'Chart',
    'period' => ($period ?? null) ?: null,
])

@section('content')
    <div style="text-align: center; margin-top: 10px;">
        <img src="{{ $imageData }}" style="width: 100%; height: auto;" alt="">
    </div>
@endsection
