<?php

use App\Actions\Banking\RecordStatementLine;
use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\ExpenseStatus;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Enums\TaxAppliesTo;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\BillPaymentPoster;
use App\Services\Posting\BillPoster;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->where('is_active', true)->orderBy('code')->firstOrFail();
    $this->card = Account::query()->where('subtype', AccountSubtype::CreditCard->value)->orderBy('code')->firstOrFail();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->action = app(RecordStatementLine::class);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function recordableStatementLine(int $amount, string $description = 'L SOCIO DIGITAL FEE/FRA', string $date = '2026-06-10', ?Account $account = null): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => ($account ?? test()->bank)->id,
        'txn_date' => $date,
        'amount_cents' => $amount,
        'description' => $description,
        'match_status' => StatementLineMatchStatus::Unmatched->value,
    ]);
}

function openBillForRecordTest(Contact $vendor, int $cents, string $date = '2026-06-01'): Bill
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'BILL-'.fake()->unique()->numerify('####'),
        'bill_date' => $date,
        'due_date' => $date,
        'lines' => [[
            'account_id' => test()->expense->id,
            'description' => 'Services',
            'quantity' => '1',
            'unit_price_cents' => $cents,
        ]],
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('records an outflow with a vendor as a posted expense to that vendor', function () {
    $vendor = Contact::factory()->vendor()->create();
    $line = recordableStatementLine(-252000);

    $entry = $this->action->handle($line, $this->expense->id, $vendor->id);

    $expense = Expense::query()->where('payee_contact_id', $vendor->id)->firstOrFail();
    $bankLeg = $entry->lines()->where('account_id', $this->bank->id)->firstOrFail();
    $line->refresh();

    expect($expense->status)->toBe(ExpenseStatus::Posted)
        ->and($expense->payment_account_id)->toBe($this->bank->id)
        ->and($expense->journal_entry_id)->toBe($entry->id)
        ->and($expense->lines->first()->account_id)->toBe($this->expense->id)
        ->and($entry->source_type)->toBe(Expense::class)
        ->and($bankLeg->credit_cents)->toBe(252000)
        ->and($bankLeg->contact_id)->toBe($vendor->id)
        ->and($line->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->created_journal_entry_id)->toBe($entry->id)
        ->and($line->matched_journal_line_id)->toBe($bankLeg->id)
        ->and($line->suggested_contact_id)->toBe($vendor->id)
        ->and($line->suggestion_source)->toBe(StatementSuggestionSource::User);
});

it('remembers the category as the vendor default only when they had none', function () {
    $fresh = Contact::factory()->vendor()->create();
    $settled = Contact::factory()->vendor()->create(['default_expense_account_id' => $this->expenseB->id]);

    $this->action->handle(recordableStatementLine(-1000), $this->expense->id, $fresh->id);
    $this->action->handle(recordableStatementLine(-2000), $this->expense->id, $settled->id);

    expect($fresh->fresh()->default_expense_account_id)->toBe($this->expense->id)
        ->and($settled->fresh()->default_expense_account_id)->toBe($this->expenseB->id);
});

it('records an outflow against an open bill as a bill payment', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bill = openBillForRecordTest($vendor, 252000);
    $line = recordableStatementLine(-252000);

    $entry = $this->action->handle($line, null, $vendor->id, $bill->id);

    $bankLeg = $entry->lines()->where('account_id', $this->bank->id)->firstOrFail();
    $line->refresh();

    expect(BillPayment::query()->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(0)
        ->and($entry->source_type)->toBe(BillPayment::class)
        ->and($bill->fresh()->balanceCents())->toBe(0)
        ->and($bill->fresh()->status)->toBe(BillStatus::Paid)
        ->and($bankLeg->credit_cents)->toBe(252000)
        ->and($line->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->matched_journal_line_id)->toBe($bankLeg->id)
        ->and($line->suggested_bill_id)->toBe($bill->id);
});

it('keeps an inflow with a contact as a journal entry with the contact on the contra leg', function () {
    $customer = Contact::factory()->customer()->create();
    $line = recordableStatementLine(10000, 'REFUND');

    $entry = $this->action->handle($line, $this->income->id, $customer->id);

    expect($entry->source_type)->toBe(BankStatementImport::class)
        ->and($entry->lines()->where('account_id', $this->income->id)->value('contact_id'))->toBe($customer->id)
        ->and($entry->lines()->where('account_id', $this->bank->id)->value('contact_id'))->toBeNull()
        ->and(Expense::query()->count())->toBe(0);
});

it('records a line with no contact as the plain journal entry, unchanged', function () {
    $line = recordableStatementLine(-4500, 'COFFEE');

    $entry = $this->action->handle($line, $this->expense->id);

    expect($entry->source_type)->toBe(BankStatementImport::class)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->fresh()->matched_journal_line_id)->toBe($entry->lines()->where('account_id', $this->bank->id)->value('id'));
});

it('records a credit-card outflow with a vendor as an expense paid from the card', function () {
    $vendor = Contact::factory()->vendor()->create();
    $line = recordableStatementLine(-5000, 'SAAS SUBSCRIPTION', account: $this->card);

    $this->action->handle($line, $this->expense->id, $vendor->id);

    expect(Expense::query()->where('payee_contact_id', $vendor->id)->value('payment_account_id'))->toBe($this->card->id);
});

it('refuses to record the same line twice', function () {
    $line = recordableStatementLine(-4500);
    $this->action->handle($line, $this->expense->id);

    expect(fn () => $this->action->handle($line->fresh(), $this->expense->id))
        ->toThrow(PostingValidationException::class, 'already been added');
});

it('guards against a missing category, the bank account as category, and a bill without a vendor', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bill = openBillForRecordTest($vendor, 1000);

    expect(fn () => $this->action->handle(recordableStatementLine(-1000), null))
        ->toThrow(PostingValidationException::class, 'Choose a category')
        ->and(fn () => $this->action->handle(recordableStatementLine(-1000), $this->bank->id))
        ->toThrow(PostingValidationException::class, 'other than the bank account')
        ->and(fn () => $this->action->handle(recordableStatementLine(-1000), null, null, $bill->id))
        ->toThrow(PostingValidationException::class, 'Choose the vendor')
        ->and(fn () => $this->action->handle(recordableStatementLine(1000), null, $vendor->id, $bill->id))
        ->toThrow(PostingValidationException::class, 'money going out');
});

it('refuses a bill whose balance no longer matches the line', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bill = openBillForRecordTest($vendor, 10000);

    expect(fn () => $this->action->handle(recordableStatementLine(-5000), null, $vendor->id, $bill->id))
        ->toThrow(PostingValidationException::class, 'no longer matches');

    expect(BillPayment::query()->count())->toBe(0);
});

it('refuses a contact that has been removed', function () {
    $vendor = Contact::factory()->vendor()->create();
    $vendor->delete();

    expect(fn () => $this->action->handle(recordableStatementLine(-1000), $this->expense->id, $vendor->id))
        ->toThrow(PostingValidationException::class, 'no longer exists');
});

it('leaves no expense behind when the period is locked', function () {
    $vendor = Contact::factory()->vendor()->create();
    $this->company->forceFill(['lock_date' => '2026-06-30'])->save();
    $line = recordableStatementLine(-1000, 'LOCKED', '2026-06-10');

    expect(fn () => $this->action->handle($line, $this->expense->id, $vendor->id))
        ->toThrow(PeriodLockedException::class);

    expect(Expense::query()->count())->toBe(0)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('records the expense tax-inclusive of the statement amount with the chosen codes', function () {
    $vendor = Contact::factory()->vendor()->create();
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $qst = TaxCode::create([
        'code' => 'QST-T', 'name' => 'QST', 'rate_basis_points' => 997.5,
        'agency_id' => $gst->agency_id, 'applies_to' => TaxAppliesTo::Both, 'is_recoverable' => true, 'is_active' => true,
    ]);
    $line = recordableStatementLine(-11498);

    $entry = $this->action->handle($line, $this->expense->id, $vendor->id, taxCodeId: $gst->id, secondaryTaxCodeId: $qst->id);

    $expense = Expense::query()->where('payee_contact_id', $vendor->id)->firstOrFail();
    $expenseLine = $expense->lines->first();

    expect($expenseLine->amount_cents)->toBe(10000)
        ->and($expenseLine->tax_cents)->toBe(500)
        ->and($expenseLine->tax_override_cents)->toBe(500)
        ->and($expenseLine->secondary_tax_cents)->toBe(998)
        ->and($expenseLine->secondary_tax_override_cents)->toBe(998)
        ->and($expense->amount_cents)->toBe(11498)
        ->and($entry->lines()->where('account_id', $this->bank->id)->value('credit_cents'))->toBe(11498)
        ->and($entry->lines()->where('account_id', $this->expense->id)->value('debit_cents'))->toBe(10000)
        ->and($entry->lines()->where('account_id', $gst->agency->payable_account_id)->value('debit_cents'))->toBe(1498)
        ->and($entry->fresh()->isBalanced())->toBeTrue()
        ->and($line->fresh()->suggested_tax_code_id)->toBe($gst->id)
        ->and($line->fresh()->suggested_secondary_tax_code_id)->toBe($qst->id);
});

it('records an outflow with a tax code but no vendor as a payee-less expense', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $line = recordableStatementLine(-10500, 'PARKING METER');

    $entry = $this->action->handle($line, $this->expense->id, null, taxCodeId: $gst->id);

    $expense = Expense::query()->firstOrFail();

    expect($entry->source_type)->toBe(Expense::class)
        ->and($expense->payee_contact_id)->toBeNull()
        ->and($expense->payee_name)->toBe('PARKING METER')
        ->and($expense->lines->first()->amount_cents)->toBe(10000)
        ->and($expense->lines->first()->tax_cents)->toBe(500);
});

it('refuses an inactive, sales-only or duplicated tax code', function () {
    $vendor = Contact::factory()->vendor()->create();
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $retired = TaxCode::create(['code' => 'OLD', 'name' => 'Old', 'rate_basis_points' => 500, 'applies_to' => TaxAppliesTo::Both, 'is_recoverable' => true, 'is_active' => false]);
    $salesOnly = TaxCode::create(['code' => 'SALE', 'name' => 'Sales only', 'rate_basis_points' => 500, 'applies_to' => TaxAppliesTo::SaleOnly, 'is_recoverable' => true, 'is_active' => true]);

    expect(fn () => $this->action->handle(recordableStatementLine(-1000), $this->expense->id, $vendor->id, taxCodeId: $retired->id))
        ->toThrow(PostingValidationException::class, 'no longer available')
        ->and(fn () => $this->action->handle(recordableStatementLine(-1000), $this->expense->id, $vendor->id, taxCodeId: $salesOnly->id))
        ->toThrow(PostingValidationException::class, 'no longer available')
        ->and(fn () => $this->action->handle(recordableStatementLine(-1000), $this->expense->id, $vendor->id, taxCodeId: $gst->id, secondaryTaxCodeId: $gst->id))
        ->toThrow(PostingValidationException::class, 'two different');

    expect(Expense::query()->count())->toBe(0);
});

it('remembers the tax code as the vendor default only when they had none, and ignores tax on the bill path', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $hst = TaxCode::query()->where('code', 'HST-ON')->firstOrFail();
    $fresh = Contact::factory()->vendor()->create();
    $settled = Contact::factory()->vendor()->create(['default_tax_code_id' => $hst->id]);

    $this->action->handle(recordableStatementLine(-1050), $this->expense->id, $fresh->id, taxCodeId: $gst->id);
    $this->action->handle(recordableStatementLine(-1050), $this->expense->id, $settled->id, taxCodeId: $gst->id);

    expect($fresh->fresh()->default_tax_code_id)->toBe($gst->id)
        ->and($settled->fresh()->default_tax_code_id)->toBe($hst->id);

    $bill = openBillForRecordTest($fresh, 5000);
    $line = recordableStatementLine(-5000);
    $this->action->handle($line, null, $fresh->id, $bill->id, taxCodeId: $gst->id);

    expect(BillPayment::query()->count())->toBe(1)
        ->and($line->fresh()->suggested_tax_code_id)->toBeNull();
});

function reimbursementBillForRecordTest(Contact $employee, int $cents): Bill
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $employee->id,
        'bill_type' => BillType::Reimbursement->value,
        'bill_no' => 'REIM-'.fake()->unique()->numerify('####'),
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-01',
        'lines' => [['account_id' => test()->expense->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('records one payment applied across several bills, partials allowed', function () {
    $vendor = Contact::factory()->vendor()->create();
    $a = openBillForRecordTest($vendor, 40000, '2026-06-01');
    $b = openBillForRecordTest($vendor, 25000, '2026-06-02');
    $c = openBillForRecordTest($vendor, 20000, '2026-06-03');
    $line = recordableStatementLine(-72500);

    $entry = $this->action->handle($line, null, $vendor->id, null, billAllocations: [
        ['bill_id' => $a->id, 'amount_cents' => 40000],
        ['bill_id' => $b->id, 'amount_cents' => 25000],
        ['bill_id' => $c->id, 'amount_cents' => 7500],
    ]);

    $payment = BillPayment::query()->firstOrFail();

    expect(BillPayment::query()->count())->toBe(1)
        ->and($payment->applications()->count())->toBe(3)
        ->and($payment->amount_cents)->toBe(72500)
        ->and($a->fresh()->status)->toBe(BillStatus::Paid)
        ->and($b->fresh()->status)->toBe(BillStatus::Paid)
        ->and($c->fresh()->status)->toBe(BillStatus::Partial)
        ->and($c->fresh()->balanceCents())->toBe(12500)
        ->and($entry->lines()->where('account_id', $this->bank->id)->value('credit_cents'))->toBe(72500)
        ->and($line->fresh()->suggested_bill_id)->toBeNull()
        ->and($line->fresh()->suggestedBillAllocations())->toHaveCount(3)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);
});

it('an allocation set takes precedence over the single bill id', function () {
    $vendor = Contact::factory()->vendor()->create();
    $single = openBillForRecordTest($vendor, 3000);
    $a = openBillForRecordTest($vendor, 1000);
    $b = openBillForRecordTest($vendor, 2000);
    $line = recordableStatementLine(-3000);

    $this->action->handle($line, null, $vendor->id, $single->id, billAllocations: [
        ['bill_id' => $a->id, 'amount_cents' => 1000],
        ['bill_id' => $b->id, 'amount_cents' => 2000],
    ]);

    expect($single->fresh()->status)->toBe(BillStatus::Posted)
        ->and($a->fresh()->status)->toBe(BillStatus::Paid)
        ->and($b->fresh()->status)->toBe(BillStatus::Paid)
        ->and($line->fresh()->suggested_bill_id)->toBeNull();
});

it('refuses a set when one bill was paid elsewhere and posts nothing', function () {
    $vendor = Contact::factory()->vendor()->create();
    $a = openBillForRecordTest($vendor, 1000);
    $b = openBillForRecordTest($vendor, 2000);

    // Bill B settled from the Pay Bills screen in the meantime.
    $elsewhere = BillPayment::create([
        'contact_id' => $vendor->id,
        'payment_type' => BillType::Vendor,
        'payment_no' => 'PAY-ELSE',
        'payment_date' => '2026-06-05',
        'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 2000,
    ]);
    $elsewhere->applications()->create(['bill_id' => $b->id, 'amount_cents' => 2000]);
    app(BillPaymentPoster::class)->post($elsewhere->fresh('applications'));

    $line = recordableStatementLine(-3000);

    expect(fn () => $this->action->handle($line, null, $vendor->id, null, billAllocations: [
        ['bill_id' => $a->id, 'amount_cents' => 1000],
        ['bill_id' => $b->id, 'amount_cents' => 2000],
    ]))->toThrow(PostingValidationException::class);

    expect(BillPayment::query()->count())->toBe(1)
        ->and($a->fresh()->status)->toBe(BillStatus::Posted)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched);
});

it('pays an employee reimbursement with the reimbursement payment type against Employee Reimbursements Payable', function () {
    $employee = Contact::factory()->create(['display_name' => 'Dana Employee', 'is_employee' => true]);
    $claim = reimbursementBillForRecordTest($employee, 10000);
    $payable = Account::query()->employeeReimbursementsPayable()->firstOrFail();
    $ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->where('is_system', true)->firstOrFail();
    $apBefore = (int) $ap->fresh()->balance_cents;

    expect($payable->fresh()->balance_cents)->toBe(10000);

    $line = recordableStatementLine(-10000, 'E-TRANSFER DANA');
    $entry = $this->action->handle($line, null, $employee->id, $claim->id);

    $payment = BillPayment::query()->firstOrFail();

    expect($payment->payment_type)->toBe(BillType::Reimbursement)
        ->and($claim->fresh()->status)->toBe(BillStatus::Paid)
        ->and($payable->fresh()->balance_cents)->toBe(0)
        ->and((int) $ap->fresh()->balance_cents)->toBe($apBefore)
        ->and($entry->source_type)->toBe(BillPayment::class)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);
});

it('gives a client-safe error when the reimbursements payable account is missing', function () {
    $employee = Contact::factory()->create(['is_employee' => true]);
    $claim = reimbursementBillForRecordTest($employee, 4000);
    Account::withoutGlobalScopes()->where('company_id', $this->company->id)->employeeReimbursementsPayable()->update(['is_system' => false]);

    expect(fn () => $this->action->handle(recordableStatementLine(-4000), null, $employee->id, $claim->id))
        ->toThrow(PostingValidationException::class, 'no Employee Reimbursements Payable');

    expect(BillPayment::query()->count())->toBe(0);
});
