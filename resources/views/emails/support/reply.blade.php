<x-mail::message>
# {{ __('You have a reply to your support ticket') }}

{{ __('Our team replied to your ticket ":subject".', ['subject' => $ticketSubject]) }}

<x-mail::panel>
{{ $replyBody }}
</x-mail::panel>

<x-mail::button :url="$actionUrl">
{{ __('View & reply') }}
</x-mail::button>

{{ __('Thanks,') }}<br>
{{ config('app.name') }}
</x-mail::message>
