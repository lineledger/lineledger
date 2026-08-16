<x-mail::message>
# {{ $heading }}

{{ __(':name wrote about ":subject" (:type):', ['name' => $submitterName, 'subject' => $ticketSubject, 'type' => $ticketType]) }}

<x-mail::panel>
{{ $body }}
</x-mail::panel>

<x-mail::button :url="$actionUrl">
{{ __('Open in Site Admin') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
