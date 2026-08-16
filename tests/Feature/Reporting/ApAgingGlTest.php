<?php

use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Services\Posting\BillPoster;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->vendor = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme Supply', 'is_vendor' => true, 'is_active' => true]);
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    // One genuinely-open bill ($100) and a partial payment booked as a GJ to AP ($40),
    // so the aging must reconcile the open-bill total down to the GL AP balance.
    $bill = Bill::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-1',
        'bill_date' => CarbonImmutable::now()->subDays(10),
        'due_date' => CarbonImmutable::now()->subDays(10),
    ]);
    $bill->lines()->create([
        'account_id' => $this->expense->id,
        'description' => 'x',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(BillPoster::class)->post($bill);

    // GJ payment: DR AP / CR Bank, tagged to the vendor (lowers their AP by $40).
    $payment = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-PAY',
        'entry_date' => CarbonImmutable::now()->subDays(5),
        'memo' => 'GJ partial payment',
    ]);
    $payment->lines()->create(['account_id' => $this->ap->id, 'contact_id' => $this->vendor->id, 'debit_cents' => 4000, 'credit_cents' => 0, 'line_order' => 0]);
    $payment->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 4000, 'line_order' => 1]);
    app(JournalPoster::class)->post($payment);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('folds the GL AP balance into each vendor row so the total ties to the ledger', function () {
    $report = Livewire::test('pages::reports.ap-aging', ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true)
        ->instance()
        ->report();

    $row = collect($report['rows'])->firstWhere('contact_id', $this->vendor->id);

    // Open bill 10000, less the 4000 GJ payment to AP = 6000, ties to GL AP.
    expect($row['total'])->toBe(6000)
        ->and($report['totals']['total'])->toBe(6000);
});

it('shows a vendor with GL-only AP activity and no open bill', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $glVendor = Contact::create(['company_id' => $this->company->id, 'display_name' => 'GL Only Vendor', 'is_vendor' => true, 'is_active' => true]);

    // A GJ accrual that credits AP for a vendor with no bill document.
    $entry = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-ACCRUE',
        'entry_date' => CarbonImmutable::now()->subDays(2),
        'memo' => 'AP accrual',
    ]);
    $entry->lines()->create(['account_id' => $this->ap->id, 'contact_id' => $glVendor->id, 'debit_cents' => 0, 'credit_cents' => 2500, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->expense->id, 'debit_cents' => 2500, 'credit_cents' => 0, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    $report = Livewire::test('pages::reports.ap-aging', ['company' => $this->company])
        ->instance()
        ->report();

    $row = collect($report['rows'])->firstWhere('contact_id', $glVendor->id);

    expect($row)->not->toBeNull()
        ->and($row['total'])->toBe(2500);
});

it('hides credit balances by default and shows them when owing-only is off', function () {
    // A vendor sitting on a net debit (we overpaid them) — DR AP with no bill.
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $creditVendor = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Overpaid Vendor', 'is_vendor' => true, 'is_active' => true]);

    $entry = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-OVERPAY',
        'entry_date' => CarbonImmutable::now()->subDays(2),
        'memo' => 'Overpayment',
    ]);
    $entry->lines()->create(['account_id' => $this->ap->id, 'contact_id' => $creditVendor->id, 'debit_cents' => 3000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 3000, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    $owingOnly = Livewire::test('pages::reports.ap-aging', ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true)
        ->instance()
        ->report();

    expect(collect($owingOnly['rows'])->pluck('name'))->not->toContain('Overpaid Vendor');

    $all = Livewire::test('pages::reports.ap-aging', ['company' => $this->company])
        ->set('excludeUnappliedCredits', false)
        ->instance()
        ->report();

    expect(collect($all['rows'])->pluck('name'))->toContain('Overpaid Vendor')
        ->and($all['totals']['total'])->toBe(3000); // 6000 owed − 3000 overpayment, ties to GL AP
});

it('plugs unattributed AP (no vendor) into a catch-all row so the total ties to the GL', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();

    // An AP adjustment with no contact_id — cannot attach to a vendor row.
    $entry = JournalEntry::create([
        'company_id' => $this->company->id,
        'entry_no' => 'JE-NOCONTACT',
        'entry_date' => CarbonImmutable::now()->subDays(2),
        'memo' => 'AP adjustment',
    ]);
    $entry->lines()->create(['account_id' => $this->ap->id, 'debit_cents' => 0, 'credit_cents' => 1200, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 1200, 'credit_cents' => 0, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry);

    $report = Livewire::test('pages::reports.ap-aging', ['company' => $this->company])
        ->set('excludeUnappliedCredits', false)
        ->instance()
        ->report();

    $unattributed = collect($report['rows'])->firstWhere('contact_id', 0);

    expect($unattributed)->not->toBeNull()
        ->and($unattributed['total'])->toBe(1200)         // the contactless AP credit
        ->and($report['totals']['total'])->toBe(7200);    // 6000 + 1200, ties to GL AP
});
