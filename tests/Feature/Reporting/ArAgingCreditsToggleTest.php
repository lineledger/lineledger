<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme Co', 'is_customer' => true, 'is_active' => true]);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // One genuinely-open invoice ($100) and an unapplied on-account credit ($50),
    // both posted so the aging can reconcile against the GL AR balance.
    $invoice = Invoice::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-1',
        'invoice_date' => CarbonImmutable::now()->subDays(10),
        'due_date' => CarbonImmutable::now()->subDays(10),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $receipt = CustomerReceipt::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'receipt_no' => 'RCPT-1',
        'receipt_date' => CarbonImmutable::now()->subDays(5),
        'deposit_to_account_id' => $bank->id,
        'amount_cents' => 5000,
    ]);
    app(ReceiptPoster::class)->post($receipt);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * A customer sitting on an unapplied receipt with no open invoice — a net credit balance.
 */
function creditOnlyCustomer(Company $company): Contact
{
    $customer = Contact::create(['company_id' => $company->id, 'display_name' => 'Credit Co', 'is_customer' => true, 'is_active' => true]);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    $receipt = CustomerReceipt::create([
        'company_id' => $company->id,
        'contact_id' => $customer->id,
        'receipt_no' => 'RCPT-CR',
        'receipt_date' => CarbonImmutable::now()->subDays(3),
        'deposit_to_account_id' => $bank->id,
        'amount_cents' => 3000,
    ]);
    app(ReceiptPoster::class)->post($receipt);

    return $customer;
}

it('hides credit and zero balances by default (owing only)', function () {
    creditOnlyCustomer($this->company);

    $report = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true)
        ->instance()
        ->report();

    $names = collect($report['rows'])->pluck('name');

    expect($names)->toContain('Acme Co')            // owes 5000 net → shown
        ->and($names)->not->toContain('Credit Co')  // net credit → hidden
        ->and($report['totals']['total'])->toBe(5000);
});

it('shows credit balances when the owing-only filter is off', function () {
    creditOnlyCustomer($this->company);

    $report = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('excludeUnappliedCredits', false)
        ->instance()
        ->report();

    $names = collect($report['rows'])->pluck('name');

    expect($names)->toContain('Acme Co')->toContain('Credit Co')
        ->and($report['totals']['total'])->toBe(2000); // 5000 owed − 3000 credit, ties to GL
});

it('reflects a refund cheque journal entry that posts to AR and ties to the GL', function () {
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    // Refund cheque: DR AR / CR Bank, tagged to the customer (raises their AR by $30).
    $refund = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-REFUND',
        'entry_date' => CarbonImmutable::now()->subDays(3),
        'memo' => 'Cheque #900 refund',
    ]);
    $refund->lines()->create(['account_id' => $ar->id, 'contact_id' => $this->customer->id, 'debit_cents' => 3000, 'credit_cents' => 0, 'line_order' => 0]);
    $refund->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 3000, 'line_order' => 1]);
    app(JournalPoster::class)->post($refund);

    $report = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('excludeUnappliedCredits', false)
        ->instance()
        ->report();

    // GL AR = 10000 invoice − 5000 receipt + 3000 refund = 8000, and the report ties to it.
    expect($report['totals']['total'])->toBe(8000);
});

it('nets journal-entry write-offs into the customer balance', function () {
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // A write-off journal entry that credits AR for the customer (e.g. a GJ clearing part of it).
    $writeOff = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-WRITEOFF',
        'entry_date' => CarbonImmutable::now()->subDays(2),
        'memo' => 'General Journal write-off',
    ]);
    $writeOff->lines()->create(['account_id' => $ar->id, 'contact_id' => $this->customer->id, 'debit_cents' => 0, 'credit_cents' => 3000, 'line_order' => 0]);
    $writeOff->lines()->create(['account_id' => $income->id, 'debit_cents' => 3000, 'credit_cents' => 0, 'line_order' => 1]);
    app(JournalPoster::class)->post($writeOff);

    // Owing-only (default): 10000 invoice − 5000 unapplied receipt − 3000 write-off = 2000,
    // still positive so the customer shows, with the write-off netted into the balance.
    $report = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true)
        ->instance()
        ->report();

    $row = collect($report['rows'])->firstWhere('contact_id', $this->customer->id);

    expect($row['total'])->toBe(2000);
});

it('plugs unattributed AR (no customer) into a catch-all row so the total ties to the GL', function () {
    $ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    // An AR adjustment with no contact_id — cannot attach to a customer row.
    $entry = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-NOCONTACT',
        'entry_date' => CarbonImmutable::now()->subDays(2),
        'memo' => 'AR adjustment',
    ]);
    $entry->lines()->create(['account_id' => $ar->id, 'debit_cents' => 1200, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => 1200, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    $report = Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('excludeUnappliedCredits', false)
        ->instance()
        ->report();

    $unattributed = collect($report['rows'])->firstWhere('contact_id', 0);

    expect($unattributed)->not->toBeNull()
        ->and($unattributed['total'])->toBe(1200)         // the contactless AR debit
        ->and($report['totals']['total'])->toBe(6200);    // 10000 − 5000 + 1200, ties to GL AR
});
