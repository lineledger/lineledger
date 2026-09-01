<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Company;
use App\Models\InvoiceSetting;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Invoice settings')] class extends Component {
    public Company $company;

    public ?int $defaultSalesAccountId = null;

    public bool $showLogo = true;

    public bool $showCompanyName = true;

    public bool $showLegalName = false;

    public bool $showCompanyAddress = true;

    public bool $showCompanyPhone = true;

    public bool $showCompanyEmail = false;

    public bool $showCompanyWebsite = false;

    public bool $showTaxNumber = true;

    public bool $showItemColumn = true;

    public bool $showQtyColumn = true;

    public bool $showTaxColumn = true;

    public bool $showServiceDateColumn = true;

    public bool $hideZeroQtyLines = false;

    public string $taxNumber = '';

    public string $footerMessage = '';

    public string $emailFromAddress = '';

    public string $emailFromName = '';

    public string $emailDefaultMessage = '';

    public string $paymentInstructions = '';

    public function mount(Company $company): void
    {
        $this->company = $company;

        $settings = $company->invoiceSettingsOrNew();

        $this->defaultSalesAccountId = $settings->default_sales_account_id;
        $this->showLogo = (bool) $settings->show_logo;
        $this->showCompanyName = (bool) $settings->show_company_name;
        $this->showLegalName = (bool) $settings->show_legal_name;
        $this->showCompanyAddress = (bool) $settings->show_company_address;
        $this->showCompanyPhone = (bool) $settings->show_company_phone;
        $this->showCompanyEmail = (bool) $settings->show_company_email;
        $this->showCompanyWebsite = (bool) $settings->show_company_website;
        $this->showTaxNumber = (bool) $settings->show_tax_number;
        $this->showItemColumn = (bool) $settings->show_item_column;
        $this->showQtyColumn = (bool) $settings->show_qty_column;
        $this->showTaxColumn = (bool) $settings->show_tax_column;
        $this->showServiceDateColumn = (bool) $settings->show_service_date_column;
        $this->hideZeroQtyLines = (bool) $settings->hide_zero_qty_lines;
        $this->taxNumber = (string) ($company->tax_number ?? '');
        $this->footerMessage = (string) ($settings->footer_message ?? '');
        $this->emailFromAddress = (string) ($settings->email_from_address ?? '');
        $this->emailFromName = (string) ($settings->email_from_name ?? '');
        $this->emailDefaultMessage = (string) ($settings->email_default_message ?? '');
        $this->paymentInstructions = (string) ($settings->payment_instructions ?? '');
    }

    public function save(): void
    {
        Gate::authorize('update', $this->company);

        $validated = $this->validate([
            'defaultSalesAccountId' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('company_id', $this->company->id)->where('type', AccountType::Income->value)],
            'showLogo' => ['boolean'],
            'showCompanyName' => ['boolean'],
            'showLegalName' => ['boolean'],
            'showCompanyAddress' => ['boolean'],
            'showCompanyPhone' => ['boolean'],
            'showCompanyEmail' => ['boolean'],
            'showCompanyWebsite' => ['boolean'],
            'showTaxNumber' => ['boolean'],
            'showItemColumn' => ['boolean'],
            'showQtyColumn' => ['boolean'],
            'showTaxColumn' => ['boolean'],
            'showServiceDateColumn' => ['boolean'],
            'hideZeroQtyLines' => ['boolean'],
            'taxNumber' => ['nullable', 'string', 'max:50'],
            'footerMessage' => ['nullable', 'string', 'max:1000'],
            'emailFromAddress' => ['nullable', 'email', 'max:255'],
            'emailFromName' => ['nullable', 'string', 'max:255'],
            'emailDefaultMessage' => ['nullable', 'string', 'max:2000'],
            'paymentInstructions' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($validated) {
            $this->company->update([
                'tax_number' => filled($validated['taxNumber']) ? trim($validated['taxNumber']) : null,
            ]);

            InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
                'default_sales_account_id' => $validated['defaultSalesAccountId'] ?: null,
                'show_logo' => (bool) $validated['showLogo'],
                'show_company_name' => (bool) $validated['showCompanyName'],
                'show_legal_name' => (bool) $validated['showLegalName'],
                'show_company_address' => (bool) $validated['showCompanyAddress'],
                'show_company_phone' => (bool) $validated['showCompanyPhone'],
                'show_company_email' => (bool) $validated['showCompanyEmail'],
                'show_company_website' => (bool) $validated['showCompanyWebsite'],
                'show_tax_number' => (bool) $validated['showTaxNumber'],
                'show_item_column' => (bool) $validated['showItemColumn'],
                'show_qty_column' => (bool) $validated['showQtyColumn'],
                'show_tax_column' => (bool) $validated['showTaxColumn'],
                'show_service_date_column' => (bool) $validated['showServiceDateColumn'],
                'hide_zero_qty_lines' => (bool) $validated['hideZeroQtyLines'],
                'footer_message' => filled($validated['footerMessage']) ? trim($validated['footerMessage']) : null,
                'email_from_address' => filled($validated['emailFromAddress']) ? trim($validated['emailFromAddress']) : null,
                'email_from_name' => filled($validated['emailFromName']) ? trim($validated['emailFromName']) : null,
                'email_default_message' => filled($validated['emailDefaultMessage']) ? trim($validated['emailDefaultMessage']) : null,
                'payment_instructions' => filled($validated['paymentInstructions']) ? trim($validated['paymentInstructions']) : null,
            ]);
        });

        $this->company->refresh();

        Flux::toast(variant: 'success', text: __('Invoice settings saved.'));
    }

    #[Computed]
    public function salesAccountOptions()
    {
        return Account::query()
            ->selectableForItemAccount()
            ->where('type', AccountType::Income->value)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('Invoices')" :subheading="__('Customise the printed / PDF documents your customers receive. These header options apply to every printed document — invoices, estimates, sales orders, receipts, purchase orders and bills.')">
        <form wire:submit="save" class="max-w-2xl space-y-8">
            <div class="space-y-4 rounded-lg border border-border p-4">
                <div>
                    <flux:heading size="sm">{{ __('Header') }}</flux:heading>
                    <flux:subheading>{{ __('Choose what prints at the top of your documents, under the logo. Upload the logo and set its size on your company branding settings.') }}</flux:subheading>
                </div>

                <flux:switch wire:model="showLogo" :label="__('Show document logo')" :description="__('Uses the document logo from your company branding settings.')" data-test="invoice-show-logo" />
                <flux:switch wire:model="showCompanyName" :label="__('Show company name')" data-test="invoice-show-company-name" />
                <flux:switch wire:model="showLegalName" :label="__('Show legal name')" data-test="invoice-show-legal-name" />
                <flux:switch wire:model="showCompanyAddress" :label="__('Show address')" data-test="invoice-show-company-address" />
                <flux:switch wire:model="showCompanyPhone" :label="__('Show phone number')" data-test="invoice-show-company-phone" />
                <flux:switch wire:model="showCompanyEmail" :label="__('Show email address')" data-test="invoice-show-company-email" />
                <flux:switch wire:model="showCompanyWebsite" :label="__('Show website')" data-test="invoice-show-company-website" />
            </div>

            <div class="space-y-4 rounded-lg border border-border p-4">
                <div>
                    <flux:heading size="sm">{{ __('Defaults') }}</flux:heading>
                    <flux:subheading>{{ __('Applied to invoice line items when no account is set on a line.') }}</flux:subheading>
                </div>

                <flux:select wire:model="defaultSalesAccountId" :label="__('Default sales account')" :description="__('Used when a line has no account — including when the Account column is hidden. Items with their own income account still override this.')" data-test="invoice-default-sales-account">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->salesAccountOptions as $acct)
                        <flux:select.option :value="$acct->id">{{ $acct->code }} — {{ $acct->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="space-y-4 rounded-lg border border-border p-4">
                <div>
                    <flux:heading size="sm">{{ __('Line columns') }}</flux:heading>
                    <flux:subheading>{{ __('Optional columns on the line-item table. Description, price, and amount always show.') }}</flux:subheading>
                </div>

                <flux:switch wire:model="showItemColumn" :label="__('Show Item column')" data-test="invoice-show-item-column" />
                <flux:switch wire:model="showQtyColumn" :label="__('Show Quantity column')" data-test="invoice-show-qty-column" />
                <flux:switch wire:model="showTaxColumn" :label="__('Show Tax column')" data-test="invoice-show-tax-column" />
                <flux:switch wire:model="showServiceDateColumn" :label="__('Show Service date column')" data-test="invoice-show-service-date-column" />
                <flux:switch wire:model="hideZeroQtyLines" :label="__('Hide zero-quantity lines')" :description="__('Line items with a quantity of 0 and no amount are left off the printed invoice.')" data-test="invoice-hide-zero-qty-lines" />
            </div>

            <div class="space-y-4 rounded-lg border border-border p-4">
                <div>
                    <flux:heading size="sm">{{ __('Tax & footer') }}</flux:heading>
                    <flux:subheading>{{ __('Shown at the bottom of the invoice.') }}</flux:subheading>
                </div>

                <flux:input wire:model="taxNumber" :label="__('Tax / GST/HST registration number')" maxlength="50" :description="__('Printed as “GST/HST No.” on the invoice footer.')" data-test="invoice-tax-number" />
                <flux:switch wire:model="showTaxNumber" :label="__('Show tax number on invoices')" data-test="invoice-show-tax-number" />

                <flux:textarea wire:model="footerMessage" :label="__('Footer message')" rows="3" :description="__('e.g. payment instructions or a thank-you note.')" data-test="invoice-footer-message" />
            </div>

            <div class="space-y-4 rounded-lg border border-border p-4">
                <div>
                    <flux:heading size="sm">{{ __('Emailing invoices') }}</flux:heading>
                    <flux:subheading>{{ __('Used when you send an invoice to a customer from the invoice page. Emails are sent from our address but appear under your name.') }}</flux:subheading>
                </div>

                <flux:input wire:model="emailFromName" :label="__('Sender name')" maxlength="255" :description="__('Shown as the sender. Defaults to your company name if left blank.')" data-test="invoice-email-from-name" />
                <flux:input type="email" wire:model="emailFromAddress" :label="__('Reply-to email address')" maxlength="255" :description="__('Customer replies go here. Leave blank to use the system default.')" data-test="invoice-email-from-address" />
                <flux:textarea wire:model="emailDefaultMessage" :label="__('Default message to customer')" rows="4" :description="__('Pre-fills the message when you email an invoice. You can edit it per invoice before sending.')" data-test="invoice-email-default-message" />
            </div>

            <div class="space-y-4 rounded-lg border border-border p-4">
                <div>
                    <flux:heading size="sm">{{ __('Payment instructions') }}</flux:heading>
                    <flux:subheading>{{ __('Shown to customers on the online payment page. Use it to offer other ways to pay.') }}</flux:subheading>
                </div>

                <flux:textarea wire:model="paymentInstructions" :label="__('How customers can pay')" rows="4" maxlength="2000" :placeholder="__('e.g. Send an Interac e-Transfer to billing@example.com, or call our office at (555) 123-4567 to arrange payment.')" :description="__('Freeform text. Line breaks are kept.')" data-test="invoice-payment-instructions" />
            </div>

            <flux:button variant="primary" type="submit" data-test="invoice-settings-save">{{ __('Save') }}</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
