{{--
    Shared printed-document header (logo + company contact fields).

    Expects:
      $company       — App\Models\Company
      $settings      — App\Models\InvoiceSetting (or a defaults-hydrated instance)
      $documentLogo  — resolved base64 data URI, already gated for show_logo (null to hide)
      $logoMaxHeight — int px max-height for the logo

    Relies on the including template defining the `.company-name` and `.muted` CSS classes.
--}}
@php
    $companyAddress = collect([
        $company->address_line1,
        $company->address_line2,
        collect([$company->address_city, $company->address_region, $company->address_postal_code])->filter()->implode(', '),
    ])->filter()->values();
@endphp
@if ($documentLogo)
    <img src="{{ $documentLogo }}" style="max-height: {{ $logoMaxHeight }}px; max-width: 260px; margin-bottom: 6px;" alt="{{ $company->name }}">
@endif
@if ($settings->show_company_name)
    <div class="company-name">{{ $company->name }}</div>
@endif
@if ($settings->show_legal_name && filled($company->legal_name))
    <div class="muted">{{ $company->legal_name }}</div>
@endif
@if ($settings->show_company_address)
    @foreach ($companyAddress as $line)
        <div class="muted">{{ $line }}</div>
    @endforeach
@endif
@if ($settings->show_company_phone && filled($company->phone))
    <div class="muted">{{ $company->phone }}</div>
@endif
@if ($settings->show_company_email && filled($company->email))
    <div class="muted">{{ $company->email }}</div>
@endif
@if ($settings->show_company_website && filled($company->website))
    <div class="muted">{{ $company->website }}</div>
@endif
