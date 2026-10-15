@props([
    'key' => 'accounts',
    'options' => [],
])

{{--
    The account list behind every <x-account-combo options="{key}"> on the page,
    emitted ONCE (a large chart repeated per row would bloat the HTML). Place it
    inside the Livewire component, outside the row loop. Accepts Account models,
    value/label or id/label arrays, or an id => label map — see
    App\Support\Accounting\AccountComboOptions. The JSON is \u-escaped for
    <, >, & and quotes, so account names can't break out of the element.
--}}
<script type="application/json" data-account-options="{{ $key }}">{!! \App\Support\Accounting\AccountComboOptions::toJson($options) !!}</script>
