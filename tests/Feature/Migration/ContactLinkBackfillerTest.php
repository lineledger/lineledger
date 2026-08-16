<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Migration\ContactLinkBackfiller;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Build a posted invoice whose AR journal line is left untagged — the import drift this fixes.
 */
function driftedInvoice(Company $company, Contact $customer): array
{
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $invoice = Invoice::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-DRIFT-'.$customer->id,
        'invoice_date' => CarbonImmutable::now()->subDays(10),
        'due_date' => CarbonImmutable::now()->subDays(10),
        'subtotal_cents' => 5000, 'tax_cents' => 0, 'total_cents' => 5000, 'amount_paid_cents' => 0,
    ]);

    $entry = JournalEntry::create([
        'company_id' => $company->id,
        'entry_no' => 'JE-DRIFT-'.$customer->id,
        'entry_date' => $invoice->invoice_date,
        'memo' => 'Invoice',
    ]);
    $entry->lines()->create(['account_id' => $ar->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'line_order' => 0]); // untagged
    $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    // Reconstruction links the entry to the document but the AR line never got the customer.
    $entry->forceFill(['source_type' => Invoice::class, 'source_id' => $invoice->id])->save();

    return [$invoice, $entry, $ar];
}

it('tags an untagged AR control line from its source invoice', function () {
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Drift Co', 'is_customer' => true]);
    [, $entry, $ar] = driftedInvoice($this->company, $customer);

    $result = app(ContactLinkBackfiller::class)->backfill($this->company->id);

    expect($result['updated'])->toBe(1);

    $arLine = $entry->lines()->where('account_id', $ar->id)->first();
    expect((int) $arLine->contact_id)->toBe($customer->id);
});

it('is idempotent — a second run changes nothing', function () {
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Drift Co', 'is_customer' => true]);
    driftedInvoice($this->company, $customer);

    app(ContactLinkBackfiller::class)->backfill($this->company->id);
    $second = app(ContactLinkBackfiller::class)->backfill($this->company->id);

    expect($second['updated'])->toBe(0);
});

it('does not touch lines that already carry a contact', function () {
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Drift Co', 'is_customer' => true]);
    $other = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Someone Else', 'is_customer' => true]);
    [, $entry, $ar] = driftedInvoice($this->company, $customer);

    // Pre-tag the AR line to a different contact; the backfill must leave it alone.
    $entry->lines()->where('account_id', $ar->id)->update(['contact_id' => $other->id]);

    $result = app(ContactLinkBackfiller::class)->backfill($this->company->id);

    expect($result['updated'])->toBe(0);
    expect((int) $entry->lines()->where('account_id', $ar->id)->first()->contact_id)->toBe($other->id);
});
