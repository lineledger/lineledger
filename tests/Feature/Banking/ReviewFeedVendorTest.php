<?php

use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Models\Account;
use App\Models\BankRule;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\BillPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function reviewVendorLine(int $amount, ?int $suggestedAccountId = null, ?int $contactId = null, ?string $reason = null, string $description = 'L SOCIO DIGITAL FEE/FRA REF 8812'): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => $amount,
        'description' => $description,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'suggested_account_id' => $suggestedAccountId,
        'suggested_contact_id' => $contactId,
        'suggestion_source' => $suggestedAccountId ? StatementSuggestionSource::History->value : null,
        'match_reason' => $reason,
    ]);
}

function reviewVendorBill(Contact $vendor, int $cents): Bill
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'BILL-500',
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-20',
        'lines' => [['account_id' => test()->expense->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('renders a pre-filled suggestion with its reason and a Confirm label', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'L Socio Digital']);
    reviewVendorLine(-252000, $this->expense->id, $vendor->id, 'Looks like "L SOCIO DIGITAL", which you filed before.');

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->assertSeeHtml('data-test="review-reason"')
        ->assertSeeHtml('data-state="suggested"')
        ->assertSee('Looks like')
        ->assertSee('Confirm')
        ->assertSee('L Socio Digital');
});

it('accept records an expense to the chosen vendor and drops the row', function () {
    $vendor = Contact::factory()->vendor()->create();
    $line = reviewVendorLine(-252000);

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set("categories.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->call('accept', $line->id)
        ->assertHasNoErrors();

    expect(Expense::query()->where('payee_contact_id', $vendor->id)->count())->toBe(1)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and(substr_count($page->html(), 'data-test="review-row"'))->toBe(0);
});

it('accept records a bill payment when the open bill offer is kept', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bill = reviewVendorBill($vendor, 252000);
    $line = reviewVendorLine(-252000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSeeHtml('data-test="review-record-as"')
        ->assertSee('BILL-500')
        ->assertSet("lineBill.{$line->id}", $bill->id)
        ->call('accept', $line->id)
        ->assertHasNoErrors();

    expect(BillPayment::query()->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(0)
        ->and($bill->fresh()->balanceCents())->toBe(0);
});

it('bulk categorize passes the vendor through from the bulk bar', function () {
    $vendor = Contact::factory()->vendor()->create();
    $a = reviewVendorLine(-1000);
    $b = reviewVendorLine(-2000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set('selected', [$a->id, $b->id])
        ->set('bulkCategory', $this->expense->id)
        ->set('bulkContactId', $vendor->id)
        ->call('bulkCategorize')
        ->assertHasNoErrors();

    expect(Expense::query()->where('payee_contact_id', $vendor->id)->count())->toBe(2);
});

it('Always do this from the feed creates a rule and marks the row as covered', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'L Socio Digital']);
    $line = reviewVendorLine(-252000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set("categories.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSeeHtml('data-test="review-make-rule"')
        ->call('createRule', $line->id)
        ->assertSeeHtml('data-test="review-rule-exists"');

    $rule = BankRule::query()->firstOrFail();

    expect(BankRule::query()->count())->toBe(1)
        ->and($rule->action_contact_id)->toBe($vendor->id)
        ->and($line->fresh()->suggestion_source)->toBe(StatementSuggestionSource::Rule);
});

it('pre-fills the category from the vendor default in the feed', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Rogers', 'default_expense_account_id' => $this->expenseB->id]);
    $line = reviewVendorLine(-5000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSet("categories.{$line->id}", $this->expenseB->id)
        ->assertSee('Pre-filled from');

    expect($line->fresh()->suggested_account_id)->toBe($this->expenseB->id);
});

it('accept records the tax picked on the row, tax-inclusive of the statement amount', function () {
    $vendor = Contact::factory()->vendor()->create();
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $line = reviewVendorLine(-25200);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set("categories.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSeeHtml('data-test="review-tax"')
        ->set("lineTax.{$line->id}", [$gst->id])
        ->assertSee('GST');

    expect($line->fresh()->suggested_tax_code_id)->toBe($gst->id);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set("categories.{$line->id}", $this->expense->id)
        ->call('accept', $line->id)
        ->assertHasNoErrors();

    $expense = Expense::query()->where('payee_contact_id', $vendor->id)->firstOrFail();

    expect($expense->amount_cents)->toBe(25200)
        ->and($expense->lines->first()->amount_cents)->toBe(24000)
        ->and($expense->lines->first()->tax_cents)->toBe(1200);
});

it('pre-fills the tax from the vendor default when the row has none', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Rogers', 'default_tax_code_id' => $gst->id]);
    $line = reviewVendorLine(-5000);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSet("lineTax.{$line->id}", [$gst->id])
        ->assertSee('Tax pre-filled from');

    expect($line->fresh()->suggested_tax_code_id)->toBe($gst->id);
});

it('split parts carry their own tax and the split is recorded to the row vendor', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Split Co']);
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $line = reviewVendorLine(-10000);

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $vendor->id)
        ->call('openSplit', $line->id)
        ->assertSee('Recorded as an expense to Split Co')
        ->assertSeeHtml('data-test="split-tax"')
        ->set('splits', [
            ['account_id' => $this->expense->id, 'amount' => '60.00', 'tax_code_ids' => [$gst->id]],
            ['account_id' => $this->expenseB->id, 'amount' => '40.00', 'tax_code_ids' => []],
        ])
        ->call('saveSplit')
        ->assertHasNoErrors();

    $expense = Expense::query()->where('payee_contact_id', $vendor->id)->firstOrFail();
    $taxed = $expense->lines->firstWhere('account_id', $this->expense->id);

    expect($expense->amount_cents)->toBe(10000)
        ->and($taxed->amount_cents)->toBe(5714)
        ->and($taxed->tax_cents)->toBe(286)
        ->and($expense->lines->firstWhere('account_id', $this->expenseB->id)->tax_cents)->toBe(0)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and(substr_count($page->html(), 'data-test="review-row"'))->toBe(0);
});

function reviewVendorBills(Contact $vendor, array $cents): array
{
    $bills = [];

    foreach ($cents as $i => $amount) {
        $bill = app(SaveBill::class)->handle([
            'contact_id' => $vendor->id,
            'bill_no' => 'BILL-'.(600 + $i),
            'bill_date' => '2026-06-0'.($i + 1),
            'due_date' => '2026-06-2'.($i + 1),
            'lines' => [['account_id' => test()->expense->id, 'quantity' => '1', 'unit_price_cents' => $amount]],
        ]);

        app(BillPoster::class)->post($bill);
        $bills[] = $bill->fresh();
    }

    return $bills;
}

it('the Pay bills picker saves an allocation and Accept records one payment across the bills', function () {
    $vendor = Contact::factory()->vendor()->create();
    [$a, $b, $c] = reviewVendorBills($vendor, [40000, 25000, 20000]);
    $line = reviewVendorLine(-72500);

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSeeHtml('data-test="review-pay-bills"')
        ->call('openPayBills', $line->id)
        ->assertSet('payBillsTargetCents', 72500)
        // Oldest-first pre-fill: 400.00 + 250.00 + 75.00 (partial).
        ->assertSet('payBillsRows.0.apply', '400.00')
        ->assertSet('payBillsRows.1.apply', '250.00')
        ->assertSet('payBillsRows.2.apply', '75.00')
        ->call('savePayBills')
        ->assertHasNoErrors()
        ->assertSet("lineBill.{$line->id}", 'allocations')
        ->assertSee('Pay 3 bills (725.00)');

    expect($line->fresh()->suggestedBillAllocations())->toHaveCount(3);

    $page->call('accept', $line->id)->assertHasNoErrors();

    expect(BillPayment::query()->count())->toBe(1)
        ->and(BillPayment::query()->firstOrFail()->applications()->count())->toBe(3)
        ->and($a->fresh()->balanceCents())->toBe(0)
        ->and($c->fresh()->balanceCents())->toBe(12500)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);
});

it('the picker refuses a total that differs from the line or exceeds a bill', function () {
    $vendor = Contact::factory()->vendor()->create();
    reviewVendorBills($vendor, [1000, 2000]);
    $line = reviewVendorLine(-2500);

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->call('selectLineContact', $line->id, $vendor->id)
        ->call('openPayBills', $line->id)
        ->set('payBillsRows.0.apply', '10.00')
        ->set('payBillsRows.1.apply', '10.00')
        ->call('savePayBills')
        ->assertHasErrors('payBillsRows')
        ->set('payBillsRows.1.apply', '25.00')
        ->call('savePayBills')
        ->assertHasErrors('payBillsRows');

    expect($line->fresh()->suggestedBillAllocations())->toBe([]);
});

it('Record as shows a pipeline allocation and Accept pays those bills', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Two Bills Co']);
    [$a, $b] = reviewVendorBills($vendor, [3000, 2000]);
    $line = reviewVendorLine(-5000, $this->expense->id, $vendor->id, 'Matches 2 open bills from Two Bills Co totalling 50.00.');
    $line->forceFill(['suggested_bill_allocations' => [['bill_id' => $a->id, 'amount_cents' => 3000], ['bill_id' => $b->id, 'amount_cents' => 2000]]])->save();

    Livewire::test('pages::banking.review', ['company' => $this->company])
        ->assertSeeHtml('data-test="review-record-as"')
        ->assertSee('Pay 2 bills (50.00)')
        ->call('accept', $line->id)
        ->assertHasNoErrors();

    expect(BillPayment::query()->count())->toBe(1)
        ->and($a->fresh()->balanceCents())->toBe(0)
        ->and($b->fresh()->balanceCents())->toBe(0)
        ->and(Expense::query()->count())->toBe(0);
});

it('ranks employees after vendors with a role chip, and Accept pays a reimbursement', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Dana Supplies']);
    $employee = Contact::factory()->create(['display_name' => 'Dana Employee', 'is_employee' => true]);
    $claim = app(SaveBill::class)->handle([
        'contact_id' => $employee->id,
        'bill_type' => BillType::Reimbursement->value,
        'bill_no' => 'REIM-77',
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-01',
        'lines' => [['account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 12000]],
    ]);
    app(BillPoster::class)->post($claim);
    $line = reviewVendorLine(-12000, description: 'E-TRANSFER DANA');

    $page = Livewire::test('pages::banking.review', ['company' => $this->company])
        ->set("lineContact.{$line->id}.query", 'Dana');

    $options = $page->instance()->lineContactOptions($line->id);

    expect($options->pluck('id')->all())->toBe([$vendor->id, $employee->id]);

    $page->assertSeeHtml('data-test="review-contact-role"')
        ->call('selectLineContact', $line->id, $employee->id)
        ->assertSet("lineBill.{$line->id}", $claim->id)
        ->assertSee('REIM-77')
        ->call('accept', $line->id)
        ->assertHasNoErrors();

    $payment = BillPayment::query()->firstOrFail();

    expect($payment->payment_type)->toBe(BillType::Reimbursement)
        ->and($claim->fresh()->balanceCents())->toBe(0);
});
