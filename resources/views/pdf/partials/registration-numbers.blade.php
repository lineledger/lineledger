{{-- Shared document footer registration lines (VAT/GST + company number). --}}
@if ($settings->show_tax_number && filled($company->tax_number))
    <div class="taxno">{{ __($company->jurisdiction->taxNumberDocumentLabel()) }} {{ $company->tax_number }}</div>
@endif
@if (filled($company->company_registration_number) && $company->jurisdiction->companyRegistrationNumberLabel() !== null)
    <div class="taxno">{{ __($company->jurisdiction->companyRegistrationNumberLabel()) }} {{ $company->company_registration_number }}</div>
@endif
