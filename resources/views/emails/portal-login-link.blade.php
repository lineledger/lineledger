<x-mail::layout>
{{-- Header: the employing company, not the platform. --}}
<x-slot:header>
<x-mail::header :url="$actionUrl">
{{ $companyName }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
# {{ __('Sign in to your pay portal') }}

{{ __('Click the button below to securely view your pay statements and tax slips from :company.', ['company' => $companyName]) }}

<x-mail::button :url="$actionUrl">
{{ __('View my pay') }}
</x-mail::button>

{{ __('This link expires in :minutes minutes and can only be used once.', ['minutes' => $ttlMinutes]) }}

{{ __('If you did not request this, you can safely ignore this email.') }}

{{-- Plain-text fallback for the button, same as Laravel's default. --}}
<x-slot:subcopy>
{{ __('If you’re having trouble clicking the ":actionText" button, copy and paste the URL below into your web browser:', ['actionText' => __('View my pay')]) }}

<span class="break-all">[{{ $actionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>

{{-- Footer: company name only — no platform branding. --}}
<x-slot:footer>
<x-mail::footer>
{{ $companyName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
