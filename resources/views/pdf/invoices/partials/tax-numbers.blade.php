{{-- The registration numbers an invoice carries: GST/HST or VAT number and
     company number, then the provincial sales tax number (QST, PST) when the
     company has one. Every invoice layout includes this. --}}
@include('pdf.partials.registration-numbers')
@php
    $provincialTaxNumber = $company->provincialTaxNumber();
    $provincialTaxLabel = $company->provincialTaxLabel();
@endphp
@if ($settings->show_tax_number && filled($provincialTaxNumber))
    <div class="taxno">{{ __(':tax No.', ['tax' => $provincialTaxLabel]) }} {{ $provincialTaxNumber }}</div>
@endif
