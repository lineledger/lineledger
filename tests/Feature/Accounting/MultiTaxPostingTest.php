<?php

use App\Actions\Purchasing\SaveExpense;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\Country;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\TaxCode;
use App\Services\Posting\BillPoster;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\TaxCalculator;

afterEach(fn () => app()->forgetInstance('current_company'));

function acct(Company $company, string $code): Account
{
    return Account::withoutGlobalScopes()->where('company_id', $company->id)->where('code', $code)->firstOrFail();
}

function debitsTo(int $entryId, int $accountId): int
{
    return (int) JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entryId)->where('account_id', $accountId)->sum('debit_cents');
}

function creditsTo(int $entryId, int $accountId): int
{
    return (int) JournalLine::withoutGlobalScopes()->where('journal_entry_id', $entryId)->where('account_id', $accountId)->sum('credit_cents');
}

it('computes a primary and secondary tax separately on one line', function () {
    $company = Company::factory()->forCountry(Country::Canada, 'QC')->create();
    app()->instance('current_company', $company);

    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $qst = TaxCode::where('code', 'QST-QC')->firstOrFail();

    $totals = app(TaxCalculator::class)->line('1', 10000, $gst, secondaryTaxCode: $qst);

    // 5% GST + 9.975% QST on $100 = $5.00 + $9.98, tracked separately.
    expect($totals['tax_cents'])->toBe(500);
    expect($totals['secondary_tax_cents'])->toBe(998);
    expect($totals['total_cents'])->toBe(11498);
});

it('posts a GST + QST invoice to the federal and provincial payable accounts separately', function () {
    $company = Company::factory()->forCountry(Country::Canada, 'QC')->create();
    app()->instance('current_company', $company);

    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $qst = TaxCode::where('code', 'QST-QC')->firstOrFail();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $customer = Contact::create(['display_name' => 'QC Customer', 'is_customer' => true]);

    $totals = app(TaxCalculator::class)->line('1', 10000, $gst, secondaryTaxCode: $qst);

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-QC-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
        'secondary_tax_code_id' => $qst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'secondary_tax_cents' => $totals['secondary_tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    $entry = app(InvoicePoster::class)->post($invoice);

    expect(creditsTo($entry->id, acct($company, '2200')->id))->toBe(500);   // GST/HST Payable (CRA)
    expect(creditsTo($entry->id, acct($company, '2210')->id))->toBe(998);   // QST Payable (Revenu Québec)
    expect(creditsTo($entry->id, $income->id))->toBe(10000);
    expect(debitsTo($entry->id, acct($company, '1100')->id))->toBe(11498);  // AR
    expect($entry->fresh()->isBalanced())->toBeTrue();
});

it('claims GST as an input credit and grosses up non-recoverable PST on a bill', function () {
    $company = Company::factory()->forCountry(Country::Canada, 'BC')->create();
    app()->instance('current_company', $company);

    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $pst = TaxCode::where('code', 'PST-BC')->firstOrFail();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $vendor = Contact::create(['display_name' => 'BC Vendor', 'is_vendor' => true]);

    $totals = app(TaxCalculator::class)->line('1', 10000, $gst, secondaryTaxCode: $pst);
    expect($totals['tax_cents'])->toBe(500);            // GST recoverable
    expect($totals['secondary_tax_cents'])->toBe(700);  // PST non-recoverable

    $bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-BC-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $bill->lines()->create([
        'account_id' => $expense->id,
        'description' => 'Supplies',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'tax_code_id' => $gst->id,
        'secondary_tax_code_id' => $pst->id,
        'line_subtotal_cents' => $totals['subtotal_cents'],
        'line_tax_cents' => $totals['tax_cents'],
        'secondary_tax_cents' => $totals['secondary_tax_cents'],
        'line_total_cents' => $totals['total_cents'],
        'line_order' => 0,
    ]);

    $entry = app(BillPoster::class)->post($bill);

    expect(debitsTo($entry->id, acct($company, '2200')->id))->toBe(500);     // GST input tax credit
    expect(debitsTo($entry->id, acct($company, '2210')->id))->toBe(0);       // PST never hits its payable
    expect(debitsTo($entry->id, $expense->id))->toBe(10700);                 // 10000 + 700 PST gross-up
    expect(creditsTo($entry->id, acct($company, '2000')->id))->toBe(11200);  // AP = 10000 + 1200
    expect($entry->fresh()->isBalanced())->toBeTrue();
});

it('posts a tax-inclusive GST + QST expense to both payables with the bank credited the gross', function () {
    $company = Company::factory()->forCountry(Country::Canada, 'QC')->create();
    app()->instance('current_company', $company);

    $gst = TaxCode::where('code', 'GST')->firstOrFail();
    $qst = TaxCode::where('code', 'QST-QC')->firstOrFail();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();

    $doc = app(SaveExpense::class)->handle([
        'payment_account_id' => $bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'QC Supplier',
        'lines' => [[
            'account_id' => $expense->id,
            'amount_cents' => 11498, // gross
            'tax_code_id' => $gst->id,
            'secondary_tax_code_id' => $qst->id,
            'amount_includes_tax' => true,
        ]],
    ]);

    $entry = app(ExpensePoster::class)->post($doc);

    expect($doc->fresh()->amount_cents)->toBe(11498)
        ->and(debitsTo($entry->id, $expense->id))->toBe(10000)
        ->and(debitsTo($entry->id, acct($company, '2200')->id))->toBe(500)
        ->and(debitsTo($entry->id, acct($company, '2210')->id))->toBe(998)
        ->and(creditsTo($entry->id, $bank->id))->toBe(11498)
        ->and($entry->fresh()->isBalanced())->toBeTrue();
});
