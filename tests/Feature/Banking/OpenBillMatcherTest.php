<?php

use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Posting\PostingValidationException;
use App\Models\Account;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Banking\Import\OpenBillMatcher;
use App\Services\Posting\BillPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->import = BankStatementImport::factory()->create(['account_id' => $this->bank->id]);
    $this->matcher = app(OpenBillMatcher::class);
    $this->vendor = Contact::factory()->vendor()->create();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function matcherStatementLine(int $amount, ?int $contactId = null): BankStatementLine
{
    return BankStatementLine::factory()->create([
        'bank_statement_import_id' => test()->import->id,
        'account_id' => test()->bank->id,
        'txn_date' => '2026-06-10',
        'amount_cents' => $amount,
        'description' => 'PAYMENT',
        'match_status' => StatementLineMatchStatus::Unmatched->value,
        'suggested_contact_id' => $contactId,
    ]);
}

function openBillForMatcherTest(Contact $vendor, int $cents, bool $post = true): Bill
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => 'BILL-'.fake()->unique()->numerify('####'),
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-15',
        'lines' => [['account_id' => test()->expense->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);

    if ($post) {
        app(BillPoster::class)->post($bill);
    }

    return $bill->fresh();
}

it('offers the single open bill whose balance equals the outflow', function () {
    $bill = openBillForMatcherTest($this->vendor, 252000);
    openBillForMatcherTest($this->vendor, 99900);

    $line = matcherStatementLine(-252000, $this->vendor->id);

    expect($this->matcher->candidates($line, $this->vendor->id)->pluck('id')->all())->toBe([$bill->id])
        ->and($this->matcher->forLine($line, $this->vendor->id)?->id)->toBe($bill->id);
});

it('returns no single match when several bills share the amount, but lists them all', function () {
    openBillForMatcherTest($this->vendor, 5000);
    openBillForMatcherTest($this->vendor, 5000);

    $line = matcherStatementLine(-5000, $this->vendor->id);

    expect($this->matcher->candidates($line, $this->vendor->id))->toHaveCount(2)
        ->and($this->matcher->forLine($line, $this->vendor->id))->toBeNull();
});

it('matches on the remaining balance of a partially paid bill', function () {
    $bill = openBillForMatcherTest($this->vendor, 10000);
    $bill->forceFill(['amount_paid_cents' => 4000, 'status' => BillStatus::Partial->value])->save();

    expect($this->matcher->forLine(matcherStatementLine(-6000, $this->vendor->id), $this->vendor->id)?->id)->toBe($bill->id)
        ->and($this->matcher->forLine(matcherStatementLine(-10000, $this->vendor->id), $this->vendor->id))->toBeNull();
});

it('ignores draft and paid bills, inflows, and other vendors', function () {
    openBillForMatcherTest($this->vendor, 7000, post: false); // draft
    $paid = openBillForMatcherTest($this->vendor, 8000);
    $paid->forceFill(['amount_paid_cents' => 8000, 'status' => BillStatus::Paid->value])->save();
    $other = Contact::factory()->vendor()->create();
    openBillForMatcherTest($other, 9000);

    expect($this->matcher->candidates(matcherStatementLine(-7000, $this->vendor->id), $this->vendor->id))->toHaveCount(0)
        ->and($this->matcher->candidates(matcherStatementLine(-8000, $this->vendor->id), $this->vendor->id))->toHaveCount(0)
        ->and($this->matcher->candidates(matcherStatementLine(-9000, $this->vendor->id), $this->vendor->id))->toHaveCount(0)
        ->and($this->matcher->candidates(matcherStatementLine(9000, $other->id), $other->id))->toHaveCount(0);
});

it('finds a company-wide match for a line with no payee only when it is unambiguous', function () {
    $bill = openBillForMatcherTest($this->vendor, 4200);

    expect($this->matcher->forLine(matcherStatementLine(-4200))?->id)->toBe($bill->id);

    openBillForMatcherTest(Contact::factory()->vendor()->create(), 4200);

    expect($this->matcher->forLine(matcherStatementLine(-4200)))->toBeNull();
});

it('batches offers for many lines, keyed by line id', function () {
    $a = openBillForMatcherTest($this->vendor, 1000);
    $b = openBillForMatcherTest($this->vendor, 2000);
    $lines = collect([
        $l1 = matcherStatementLine(-1000, $this->vendor->id),
        $l2 = matcherStatementLine(-2000, $this->vendor->id),
        $l3 = matcherStatementLine(-3000, $this->vendor->id),
        matcherStatementLine(1000, $this->vendor->id),
    ]);

    $offers = $this->matcher->forLines($lines);

    expect(array_keys($offers))->toBe([$l1->id, $l2->id])
        ->and($offers[$l1->id]->first()->id)->toBe($a->id)
        ->and($offers[$l2->id]->first()->id)->toBe($b->id)
        ->and($offers)->not->toHaveKey($l3->id);
});

it('re-validates at post time', function () {
    $bill = openBillForMatcherTest($this->vendor, 1000);
    $other = Contact::factory()->vendor()->create();

    expect(fn () => $this->matcher->assertPayable(matcherStatementLine(1000), $this->vendor, $bill))
        ->toThrow(PostingValidationException::class, 'money going out')
        ->and(fn () => $this->matcher->assertPayable(matcherStatementLine(-1000), $other, $bill))
        ->toThrow(PostingValidationException::class, 'different vendor')
        ->and(fn () => $this->matcher->assertPayable(matcherStatementLine(-999), $this->vendor, $bill))
        ->toThrow(PostingValidationException::class, 'no longer matches');

    $this->matcher->assertPayable(matcherStatementLine(-1000), $this->vendor, $bill);
    expect(true)->toBeTrue();
});

function reimbursementBillForMatcher(Contact $employee, int $cents): Bill
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $employee->id,
        'bill_type' => BillType::Reimbursement->value,
        'bill_no' => 'REIM-'.fake()->unique()->numerify('####'),
        'bill_date' => '2026-06-01',
        'due_date' => '2026-06-15',
        'lines' => [['account_id' => test()->expense->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('offers an exact-sum set of the vendor open bills when no single bill matches', function () {
    $a = openBillForMatcherTest($this->vendor, 1000);
    $b = openBillForMatcherTest($this->vendor, 2000);
    $c = openBillForMatcherTest($this->vendor, 3000);

    expect($this->matcher->forLine(matcherStatementLine(-3000, $this->vendor->id), $this->vendor->id)?->id)->toBe($c->id)
        ->and($this->matcher->allocationFor(matcherStatementLine(-6000, $this->vendor->id), $this->vendor->id))
        ->toBe([['bill_id' => $a->id, 'amount_cents' => 1000], ['bill_id' => $b->id, 'amount_cents' => 2000], ['bill_id' => $c->id, 'amount_cents' => 3000]])
        ->and($this->matcher->allocationFor(matcherStatementLine(-4000, $this->vendor->id), $this->vendor->id))
        ->toBe([['bill_id' => $a->id, 'amount_cents' => 1000], ['bill_id' => $c->id, 'amount_cents' => 3000]])
        ->and($this->matcher->allocationFor(matcherStatementLine(-7000, $this->vendor->id), $this->vendor->id))->toBeNull();
});

it('stays silent when the sum is ambiguous or the vendor has too many open bills', function () {
    foreach ([1000, 2000, 3000, 4000] as $cents) {
        openBillForMatcherTest($this->vendor, $cents);
    }

    // {1000, 4000} and {2000, 3000} both add up — not offered.
    expect($this->matcher->allocationFor(matcherStatementLine(-5000, $this->vendor->id), $this->vendor->id))->toBeNull();

    $busy = Contact::factory()->vendor()->create();
    foreach (range(1, OpenBillMatcher::MAX_SUBSET_BILLS + 1) as $i) {
        openBillForMatcherTest($busy, 100);
    }

    expect($this->matcher->allocationFor(matcherStatementLine(-300, $busy->id), $busy->id))->toBeNull();
});

it('offers a reimbursement bill for an employee, both kinds for a contact who is both, and nothing across types', function () {
    $employee = Contact::factory()->create(['is_employee' => true]);
    $claim = reimbursementBillForMatcher($employee, 5000);
    $both = Contact::factory()->vendor()->create(['is_employee' => true]);
    $bothClaim = reimbursementBillForMatcher($both, 8000);
    $bothBill = openBillForMatcherTest($both, 8000);

    expect($this->matcher->candidates(matcherStatementLine(-5000, $employee->id), $employee->id)->pluck('id')->all())->toBe([$claim->id])
        ->and($this->matcher->candidates(matcherStatementLine(-8000, $both->id), $both->id)->pluck('id')->sort()->values()->all())->toBe(collect([$bothClaim->id, $bothBill->id])->sort()->values()->all())
        ->and($this->matcher->openBillsFor(matcherStatementLine(-1, $employee->id), $employee)->pluck('id')->all())->toBe([$claim->id]);

    // A plain vendor bill on a pure employee is not offered: their role does not cover it.
    $strayVendorBill = openBillForMatcherTest($employee, 6000);
    expect($this->matcher->candidates(matcherStatementLine(-6000, $employee->id), $employee->id))->toHaveCount(0);
});

it('assertPayableSet re-validates the whole set', function () {
    $a = openBillForMatcherTest($this->vendor, 1000);
    $b = openBillForMatcherTest($this->vendor, 2000);
    $other = Contact::factory()->vendor()->create();
    $foreign = openBillForMatcherTest($other, 1000);
    $line = matcherStatementLine(-3000, $this->vendor->id);

    $ok = $this->matcher->assertPayableSet($line, $this->vendor, [['bill_id' => $a->id, 'amount_cents' => 1000], ['bill_id' => $b->id, 'amount_cents' => 2000]]);
    expect($ok->keys()->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());

    expect(fn () => $this->matcher->assertPayableSet($line, $this->vendor, [['bill_id' => $a->id, 'amount_cents' => 1000], ['bill_id' => $a->id, 'amount_cents' => 2000]]))
        ->toThrow(PostingValidationException::class, 'only be paid once')
        ->and(fn () => $this->matcher->assertPayableSet($line, $this->vendor, [['bill_id' => $a->id, 'amount_cents' => 1500], ['bill_id' => $b->id, 'amount_cents' => 1500]]))
        ->toThrow(PostingValidationException::class, 'remaining balance changed')
        ->and(fn () => $this->matcher->assertPayableSet($line, $this->vendor, [['bill_id' => $foreign->id, 'amount_cents' => 1000], ['bill_id' => $b->id, 'amount_cents' => 2000]]))
        ->toThrow(PostingValidationException::class, 'different vendor')
        ->and(fn () => $this->matcher->assertPayableSet($line, $this->vendor, [['bill_id' => $a->id, 'amount_cents' => 1000], ['bill_id' => $b->id, 'amount_cents' => 1000]]))
        ->toThrow(PostingValidationException::class, 'must add up')
        ->and(fn () => $this->matcher->assertPayableSet($line, $this->vendor, [['bill_id' => $a->id, 'amount_cents' => 0]]))
        ->toThrow(PostingValidationException::class, 'positive amount')
        ->and(fn () => $this->matcher->assertPayableSet($line, $this->vendor, []))
        ->toThrow(PostingValidationException::class, 'at least one');
});

it('refuses to mix a vendor bill and a reimbursement in one payment', function () {
    $both = Contact::factory()->vendor()->create(['is_employee' => true]);
    $claim = reimbursementBillForMatcher($both, 1000);
    $bill = openBillForMatcherTest($both, 2000);

    expect(fn () => $this->matcher->assertPayableSet(matcherStatementLine(-3000, $both->id), $both, [['bill_id' => $claim->id, 'amount_cents' => 1000], ['bill_id' => $bill->id, 'amount_cents' => 2000]]))
        ->toThrow(PostingValidationException::class, 'cannot be paid together');
});

it('refuses a reimbursement when the payable account is missing', function () {
    $employee = Contact::factory()->create(['is_employee' => true]);
    $claim = reimbursementBillForMatcher($employee, 5000);

    Account::withoutGlobalScopes()->where('company_id', $this->company->id)->employeeReimbursementsPayable()->update(['is_system' => false]);

    expect(fn () => $this->matcher->assertPayable(matcherStatementLine(-5000, $employee->id), $employee, $claim))
        ->toThrow(PostingValidationException::class, 'no Employee Reimbursements Payable');
});
