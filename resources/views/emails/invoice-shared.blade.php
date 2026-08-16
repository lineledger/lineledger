<x-mail::layout>
{{-- Header: the sending company, not the platform. --}}
<x-slot:header>
<x-mail::header :url="$actionUrl">
{{ $companyName }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
# {{ __('Hello!') }}

{{ $introMessage }}

{{ $detailLine }}

<x-mail::button :url="$actionUrl">
{{ __('View & pay invoice') }}
</x-mail::button>

{{-- Plain-text fallback for the button, same as Laravel's default. --}}
<x-slot:subcopy>
{{ __('If you’re having trouble clicking the ":actionText" button, copy and paste the URL below into your web browser:', ['actionText' => __('View & pay invoice')]) }}

<span class="break-all">[{{ $actionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>

{{-- Footer: company name only — no platform branding. --}}
<x-slot:footer>
<x-mail::footer>
{{ $companyName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
