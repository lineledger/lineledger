<x-mail::layout>
{{-- Header: the sending company, not the platform. --}}
<x-slot:header>
<x-mail::header url="">
{{ $companyName }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
# {{ $reportLabel }}

{{ $introMessage }}

{{ __('The report is attached to this email.') }}

{{-- Footer: company name only — no platform branding. --}}
<x-slot:footer>
<x-mail::footer>
{{ $companyName }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
