<?php

use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TaxCode;

/**
 * Tenant isolation is enforced by the global CompanyScope (via BelongsToCompany)
 * keyed on the bound `current_company`. These regressions prove that (a) every
 * company-scoped financial model hides another tenant's rows from ordinary
 * reads, and (b) the deliberate withoutGlobalScopes() bypasses in the document
 * Save actions still re-filter by company_id, so a caller can't inject another
 * tenant's tax code (or receipt) into their own document.
 */
afterEach(function () {
    app()->forgetInstance('current_company');
});

it('hides another company rows from ordinary reads on every scoped financial model', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    app()->instance('current_company', $companyA);

    $contact = Contact::create(['display_name' => 'A Customer', 'is_customer' => true]);

    $taxCode = TaxCode::create([
        'code' => 'A13',
        'name' => 'A 13%',
        'rate_basis_points' => 1300,
        'applies_to' => TaxAppliesTo::Both->value,
        'is_active' => true,
    ]);

    $invoice = Invoice::create([
        'contact_id' => $contact->id,
        'invoice_no' => 'INV-ISO',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $entry = JournalEntry::create([
        'entry_no' => 'JE-ISO',
        'entry_date' => now()->toDateString(),
        'memo' => 'iso',
    ]);

    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $receipt = CustomerReceipt::create([
        'contact_id' => $contact->id,
        'receipt_no' => 'REC-ISO',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $undeposited->id,
        'amount_cents' => 5000,
    ]);

    $accountAId = Account::query()->value('id');

    app()->forgetInstance('current_company');

    // Switch into the OTHER tenant; none of company A's rows may be reachable.
    app()->instance('current_company', $companyB);

    $cases = [
        Contact::class => $contact->id,
        TaxCode::class => $taxCode->id,
        Invoice::class => $invoice->id,
        JournalEntry::class => $entry->id,
        CustomerReceipt::class => $receipt->id,
        Account::class => $accountAId,
    ];

    foreach ($cases as $class => $id) {
        expect($class::find($id))->toBeNull("{$class} leaked across tenants via find()");
        expect($class::query()->whereKey($id)->exists())->toBeFalse("{$class} leaked across tenants via query()");
    }
});

it('ignores a foreign tax code injected into SaveInvoice, but applies a same-company one', function () {
    $companyA = Company::factory()->create();
    $companyB = Company::factory()->create();

    // A tax code that belongs to company B.
    app()->instance('current_company', $companyB);
    $foreignTax = TaxCode::create([
        'code' => 'BGST',
        'name' => 'B GST 13%',
        'rate_basis_points' => 1300,
        'applies_to' => TaxAppliesTo::Both->value,
        'is_active' => true,
    ]);
    app()->forgetInstance('current_company');

    app()->instance('current_company', $companyA);

    $customer = Contact::create(['display_name' => 'A Customer', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $base = [
        'contact_id' => $customer->id,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ];
    $lineBase = [
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
    ];

    // Inject company B's tax code: its 13% rate must NOT be applied, and the
    // dangling foreign id must not be persisted on the line.
    $injected = app(SaveInvoice::class)->handle($base + [
        'lines' => [$lineBase + ['tax_code_id' => $foreignTax->id]],
    ]);
    $injectedLine = $injected->lines()->first();

    expect($injectedLine->line_tax_cents)->toBe(0);
    expect($injectedLine->tax_code_id)->toBeNull();

    // A genuine same-company tax code still applies normally (no regression).
    $ownTax = TaxCode::create([
        'code' => 'AGST',
        'name' => 'A GST 13%',
        'rate_basis_points' => 1300,
        'applies_to' => TaxAppliesTo::Both->value,
        'is_active' => true,
    ]);
    $ok = app(SaveInvoice::class)->handle($base + [
        'invoice_no' => 'INV-OK',
        'lines' => [$lineBase + ['tax_code_id' => $ownTax->id]],
    ]);
    $okLine = $ok->lines()->first();

    expect($okLine->tax_code_id)->toBe($ownTax->id);
    expect($okLine->line_tax_cents)->toBe(1300);
});
