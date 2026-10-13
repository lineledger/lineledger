<?php

use App\Actions\Banking\SaveCheque;
use App\Actions\Documents\PromoteInboxItem;
use App\Actions\Purchasing\SaveBill;
use App\Actions\Purchasing\SaveExpense;
use App\Enums\AccountSubtype;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\InboxItemSource;
use App\Enums\InboxItemStatus;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Models\JournalLine;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\Posting\BillPoster;
use App\Services\Posting\ChequePoster;
use App\Services\Posting\ExpensePoster;
use Livewire\Livewire;

/**
 * A purchase line can carry two taxes (e.g. GST + QST). Each selected tax gets
 * its own amount input; the amount typed for a tax is stored in that tax's slot
 * and posts to that tax's own agency. Amounts are keyed by tax code, so one
 * follows its code when unticking the other moves it between slots.
 */
beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $this->gstPayable = $this->gst->agency->payableAccount;

    $taxPayable = fn (string $code, string $name): Account => Account::create([
        'code' => $code,
        'name' => $name,
        'subtype' => AccountSubtype::TaxPayable->value,
        'type' => AccountSubtype::TaxPayable->type()->value,
        'normal_balance' => AccountSubtype::TaxPayable->type()->normalBalance()->value,
    ]);

    // A second recoverable tax remitted to its own agency.
    $this->qstPayable = $taxPayable('2215', 'QST Payable');
    $this->qst = TaxCode::create([
        'code' => 'QST', 'name' => 'QST', 'rate_basis_points' => 998,
        'agency_id' => TaxAgency::create(['name' => 'Revenu Québec', 'payable_account_id' => $this->qstPayable->id, 'is_active' => true])->id,
        'is_recoverable' => true, 'applies_to' => TaxAppliesTo::Both->value, 'is_active' => true,
    ]);

    // BC PST: its own agency, and not an input credit.
    $this->pstPayable = $taxPayable('2216', 'PST Payable (BC)');
    $this->pst = TaxCode::create([
        'code' => 'PST-BC', 'name' => 'PST BC (7%)', 'rate_basis_points' => 700,
        'agency_id' => TaxAgency::create(['name' => 'BC Ministry of Finance', 'payable_account_id' => $this->pstPayable->id, 'is_active' => true])->id,
        'is_recoverable' => false, 'applies_to' => TaxAppliesTo::Both->value, 'is_active' => true,
    ]);

    $this->vendor = Contact::factory()->vendor()->create();
    $this->employee = Contact::create(['display_name' => 'Dana Employee', 'is_employee' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Net debit cents per account on one journal entry.
 *
 * @return array<int, int>
 */
function perTaxOverrideLegs(?int $journalEntryId): array
{
    return JournalLine::query()
        ->where('journal_entry_id', $journalEntryId)
        ->get()
        ->groupBy('account_id')
        ->map(fn ($lines) => (int) $lines->sum(fn (JournalLine $l) => (int) $l->debit_cents - (int) $l->credit_cents))
        ->all();
}

/**
 * A receipt staged in the inbox, read but not yet promoted.
 */
function perTaxOverrideInboxItem(int $amountCents): InboxItem
{
    $item = InboxItem::create([
        'source' => InboxItemSource::Upload,
        'status' => InboxItemStatus::NeedsReview,
        'original_filename' => 'receipt.jpg',
        'mime' => 'image/jpeg',
        'created_by_user_id' => test()->user->id,
    ]);
    $item->forceFill([
        'extracted' => ['vendor' => 'Fournitures Lafleur', 'amount_cents' => $amountCents, 'currency' => 'CAD', 'date' => now()->toDateString()],
    ])->save();

    return $item->fresh();
}

/*
 * Two taxes, two amounts: each stored in its slot, each posted to its agency
 */

it('stores a typed amount per tax on a cheque line and posts each to its own agency', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_name', 'Fournitures Lafleur')
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->qst->id])
        ->set('lines.0.tax_overrides.'.$this->gst->id, '4.00')
        ->set('lines.0.tax_overrides.'.$this->qst->id, '9.00')
        ->assertSet('lines.0.tax_cents', 400)
        ->assertSet('lines.0.secondary_tax_cents', 900)
        ->assertSet('lines.0.total', 11300)
        ->call('postCheque')
        ->assertHasNoErrors();

    $cheque = Cheque::query()->firstOrFail();
    $line = $cheque->lines()->firstOrFail();

    expect($line->tax_code_id)->toBe($this->gst->id)
        ->and($line->tax_cents)->toBe(400)
        ->and($line->tax_override_cents)->toBe(400)
        ->and($line->secondary_tax_code_id)->toBe($this->qst->id)
        ->and($line->secondary_tax_cents)->toBe(900)
        ->and($line->secondary_tax_override_cents)->toBe(900)
        ->and($cheque->amount_cents)->toBe(11300);

    expect(perTaxOverrideLegs($cheque->journal_entry_id))->toEqual([
        $this->expense->id => 10000,
        $this->gstPayable->id => 400,   // GST input tax credit
        $this->qstPayable->id => 900,   // QST input tax credit, its own agency
        $this->bank->id => -11300,
    ]);
});

it('stores a typed amount per tax on a bill line and posts each to its own agency', function () {
    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->qst->id])
        ->set('lines.0.tax_overrides.'.$this->gst->id, '4.00')
        ->set('lines.0.tax_overrides.'.$this->qst->id, '9.00')
        ->assertSet('lines.0.tax', 400)
        ->assertSet('lines.0.secondary_tax', 900)
        ->call('postBill')
        ->assertHasNoErrors();

    $bill = Bill::query()->firstOrFail();
    $line = $bill->lines()->firstOrFail();

    expect($line->line_tax_cents)->toBe(400)
        ->and($line->tax_override_cents)->toBe(400)
        ->and($line->secondary_tax_cents)->toBe(900)
        ->and($line->secondary_tax_override_cents)->toBe(900)
        ->and($line->line_total_cents)->toBe(11300)
        ->and($bill->total_cents)->toBe(11300);

    $legs = perTaxOverrideLegs($bill->journal_entry_id);

    expect($legs[$this->gstPayable->id])->toBe(400)
        ->and($legs[$this->qstPayable->id])->toBe(900)
        ->and($legs[$this->expense->id])->toBe(10000)
        ->and(array_sum($legs))->toBe(0);
});

it('stores a typed amount per tax from the expense, reimbursement and inbox forms', function () {
    Livewire::test('pages::expenses.form', ['company' => $this->company])
        ->set('payee_name', 'Fournitures Lafleur')
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->qst->id])
        ->set('lines.0.tax_overrides.'.$this->qst->id, '9.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $expenseLine = Expense::query()->firstOrFail()->lines()->firstOrFail();

    expect($expenseLine->tax_override_cents)->toBeNull()          // GST left blank: calculated
        ->and($expenseLine->tax_cents)->toBe(500)
        ->and($expenseLine->secondary_tax_override_cents)->toBe(900)
        ->and($expenseLine->secondary_tax_cents)->toBe(900);

    Livewire::test('pages::reimbursements.form', ['company' => $this->company])
        ->set('contact_id', $this->employee->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->qst->id])
        ->set('lines.0.tax_overrides.'.$this->gst->id, '4.00')
        ->set('lines.0.tax_overrides.'.$this->qst->id, '9.00')
        ->call('saveDraft')
        ->assertHasNoErrors();

    $reimbursementLine = Bill::query()->where('bill_type', BillType::Reimbursement->value)->firstOrFail()->lines()->firstOrFail();

    expect($reimbursementLine->tax_override_cents)->toBe(400)
        ->and($reimbursementLine->secondary_tax_override_cents)->toBe(900)
        ->and($reimbursementLine->line_total_cents)->toBe(11300);

    $item = perTaxOverrideInboxItem(11300);

    Livewire::test('pages::inbox.show', ['company' => $this->company, 'item' => $item])
        ->set('documentType', 'bill')
        ->set('contactId', $this->vendor->id)
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->qst->id])
        ->set('lines.0.tax_overrides.'.$this->gst->id, '4.00')
        ->set('lines.0.tax_overrides.'.$this->qst->id, '9.00')
        ->assertSet('lines.0.total', 11300)
        ->call('promote')
        ->assertHasNoErrors();

    $inboxLine = Bill::query()->where('bill_type', BillType::Vendor->value)->firstOrFail()->lines()->firstOrFail();

    expect($inboxLine->tax_override_cents)->toBe(400)
        ->and($inboxLine->secondary_tax_override_cents)->toBe(900)
        ->and($inboxLine->line_total_cents)->toBe(11300);
});

/*
 * Keyed by code: an amount follows its tax between slots
 */

it('keeps an amount with its tax when unticking the first tax moves it to the primary slot', function () {
    $component = Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_name', 'Fournitures Lafleur')
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.amount', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->qst->id])
        ->set('lines.0.tax_overrides.'.$this->gst->id, '4.00')
        ->set('lines.0.tax_overrides.'.$this->qst->id, '9.00')
        ->set('lines.0.tax_code_ids', [$this->qst->id]);

    // QST is now the primary tax, and it carries its own 9.00 — not GST's 4.00.
    expect($component->get('lines.0.tax_code_id'))->toBe($this->qst->id)
        ->and($component->get('lines.0.secondary_tax_code_id'))->toBeNull()
        ->and($component->get('lines.0.tax_overrides'))->toBe([$this->qst->id => '9.00'])
        ->and($component->get('lines.0.tax_cents'))->toBe(900)
        ->and($component->get('lines.0.secondary_tax_cents'))->toBe(0)
        ->and($component->get('lines.0.total'))->toBe(10900);

    // Re-ticking GST brings back its calculated amount, not the stale 4.00.
    $component->set('lines.0.tax_code_ids', [$this->qst->id, $this->gst->id]);

    expect($component->get('lines.0.secondary_tax_cents'))->toBe(500);

    $component->call('saveDraft')->assertHasNoErrors();

    $line = Cheque::query()->firstOrFail()->lines()->firstOrFail();

    expect($line->tax_code_id)->toBe($this->qst->id)
        ->and($line->tax_override_cents)->toBe(900)
        ->and($line->secondary_tax_code_id)->toBe($this->gst->id)
        ->and($line->secondary_tax_override_cents)->toBeNull()
        ->and($line->secondary_tax_cents)->toBe(500);
});

it('keeps an amount with its tax on a bill line too', function () {
    $component = Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->expense->id)
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->pst->id])
        ->set('lines.0.tax_overrides.'.$this->gst->id, '4.00')
        ->set('lines.0.tax_overrides.'.$this->pst->id, '6.50')
        ->set('lines.0.tax_code_ids', [$this->pst->id]);

    expect($component->get('lines.0.tax_code_id'))->toBe($this->pst->id)
        ->and($component->get('lines.0.tax'))->toBe(650)
        ->and($component->get('lines.0.secondary_tax'))->toBe(0)
        ->and($component->get('lines.0.total'))->toBe(10650);
});

/*
 * The inputs: none without a tax, one per selected tax
 */

it('renders an amount input only for each selected tax', function (string $page, string $amountField) {
    $component = Livewire::test($page, ['company' => $this->company])
        ->set('lines.0.account_id', $this->expense->id)
        ->set($amountField, '100.00');

    $html = $component->html();

    expect($html)->not->toContain('<flux:')
        ->and($html)->toContain('data-test="line-tax"')
        ->and($html)->not->toContain('data-test="line-tax-override"');

    $component->set('lines.0.tax_code_ids', [$this->gst->id]);
    $html = $component->html();

    expect($html)->not->toContain('<flux:')
        ->and(substr_count($html, 'data-test="line-tax-override"'))->toBe(1)
        ->and($html)->toContain('data-tax-code="'.$this->gst->id.'"')
        ->and($html)->toContain('wire:model.live.debounce.500ms="lines.0.tax_overrides.'.$this->gst->id.'"')
        ->and($html)->toContain('placeholder="5.00"');

    $component->set('lines.0.tax_code_ids', [$this->gst->id, $this->pst->id]);
    $html = $component->html();

    expect(substr_count($html, 'data-test="line-tax-override"'))->toBe(2)
        ->and($html)->toContain('data-tax-code="'.$this->pst->id.'"')
        ->and($html)->toContain('wire:model.live.debounce.500ms="lines.0.tax_overrides.'.$this->pst->id.'"')
        ->and($html)->toContain('placeholder="7.00"');

    $component->set('lines.0.tax_code_ids', []);

    expect($component->html())->not->toContain('data-test="line-tax-override"');
})->with([
    'cheque' => ['pages::cheques.form', 'lines.0.amount'],
    'expense' => ['pages::expenses.form', 'lines.0.amount'],
    'bill' => ['pages::bills.form', 'lines.0.unit_price'],
    'reimbursement' => ['pages::reimbursements.form', 'lines.0.unit_price'],
]);

/*
 * Editing keeps a stored secondary amount
 */

it('keeps a saved secondary tax amount when a cheque is reopened and re-saved', function () {
    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Fournitures Lafleur',
        'lines' => [[
            'account_id' => $this->expense->id,
            'amount_cents' => 10000,
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->qst->id,
            'secondary_tax_override_cents' => 900,
        ]],
    ]);

    $component = Livewire::test('pages::cheques.form', ['company' => $this->company, 'cheque' => $cheque])
        ->assertSet('lines.0.tax_overrides', [$this->qst->id => '9.00'])
        ->assertSet('lines.0.secondary_tax_cents', 900)
        ->assertSet('lines.0.auto_secondary_tax_cents', $this->qst->taxFor(10000));

    expect(substr_count($component->html(), 'data-test="line-tax-override"'))->toBe(2);

    $component->set('memo', 'Re-saved')->call('saveDraft')->assertHasNoErrors();

    $line = $cheque->fresh()->lines()->firstOrFail();

    expect($line->tax_override_cents)->toBeNull()
        ->and($line->tax_cents)->toBe(500)
        ->and($line->secondary_tax_override_cents)->toBe(900)
        ->and($line->secondary_tax_cents)->toBe(900);
});

it('keeps a saved secondary tax amount when a posted bill is reopened and re-saved', function () {
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $this->vendor->id,
        'bill_no' => 'BILL-PT-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'lines' => [[
            'account_id' => $this->expense->id,
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->qst->id,
            'tax_override_cents' => 400,
            'secondary_tax_override_cents' => 900,
        ]],
    ]);
    app(BillPoster::class)->post($bill);

    Livewire::test('pages::bills.form', ['company' => $this->company, 'bill' => $bill->fresh()])
        ->assertSet('lines.0.tax_overrides', [$this->gst->id => '4.00', $this->qst->id => '9.00'])
        ->assertSet('lines.0.tax', 400)
        ->assertSet('lines.0.secondary_tax', 900)
        ->set('memo', 'Re-saved')
        ->call('postBill')
        ->assertHasNoErrors();

    $line = $bill->fresh()->lines()->firstOrFail();

    expect($line->tax_override_cents)->toBe(400)
        ->and($line->secondary_tax_override_cents)->toBe(900)
        ->and($line->line_total_cents)->toBe(11300);

    expect(perTaxOverrideLegs($bill->fresh()->journal_entry_id)[$this->qstPayable->id])->toBe(900);
});

it('keeps a saved secondary tax amount when an expense or reimbursement is reopened', function () {
    $expense = app(SaveExpense::class)->handle([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Fournitures Lafleur',
        'lines' => [[
            'account_id' => $this->expense->id,
            'amount_cents' => 10000,
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->qst->id,
            'secondary_tax_override_cents' => 900,
        ]],
    ]);

    Livewire::test('pages::expenses.form', ['company' => $this->company, 'expense' => $expense])
        ->assertSet('lines.0.tax_overrides', [$this->qst->id => '9.00'])
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($expense->fresh()->lines()->firstOrFail()->secondary_tax_override_cents)->toBe(900);

    $reimbursement = app(SaveBill::class)->handle([
        'contact_id' => $this->employee->id,
        'bill_type' => BillType::Reimbursement->value,
        'bill_no' => 'REIM-PT-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'lines' => [[
            'account_id' => $this->expense->id,
            'quantity' => '1',
            'unit_price_cents' => 10000,
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->qst->id,
            'secondary_tax_override_cents' => 900,
        ]],
    ]);

    Livewire::test('pages::reimbursements.form', ['company' => $this->company, 'bill' => $reimbursement])
        ->assertSet('lines.0.tax_overrides', [$this->qst->id => '9.00'])
        ->assertSet('lines.0.auto_tax', 500)
        ->call('saveDraft')
        ->assertHasNoErrors();

    expect($reimbursement->fresh()->lines()->firstOrFail()->secondary_tax_override_cents)->toBe(900);
});

/*
 * Server guard: a tax amount without a tax code is ignored
 */

it('ignores a cheque tax amount that has no tax code, so it never lands in an expense', function () {
    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2002',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Fournitures Lafleur',
        'lines' => [[
            'account_id' => $this->expense->id,
            'amount_cents' => 10000,
            'tax_override_cents' => 700,
            'secondary_tax_override_cents' => 300,
        ]],
    ]);

    $line = $cheque->lines()->firstOrFail();

    expect($line->tax_override_cents)->toBeNull()
        ->and($line->tax_cents)->toBe(0)
        ->and($line->secondary_tax_override_cents)->toBeNull()
        ->and($line->secondary_tax_cents)->toBe(0)
        ->and($cheque->fresh()->amount_cents)->toBe(10000);

    app(ChequePoster::class)->post($cheque->fresh('lines'));

    expect(perTaxOverrideLegs($cheque->fresh()->journal_entry_id))->toEqual([
        $this->expense->id => 10000,
        $this->bank->id => -10000,
    ]);
});

it('ignores a secondary tax amount whose secondary code is missing', function () {
    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2003',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Fournitures Lafleur',
        'lines' => [[
            'account_id' => $this->expense->id,
            'amount_cents' => 10000,
            'tax_code_id' => $this->gst->id,
            'tax_override_cents' => 400,
            'secondary_tax_override_cents' => 300,
        ]],
    ]);

    $line = $cheque->lines()->firstOrFail();

    expect($line->tax_override_cents)->toBe(400)
        ->and($line->secondary_tax_override_cents)->toBeNull()
        ->and($line->secondary_tax_cents)->toBe(0)
        ->and($cheque->fresh()->amount_cents)->toBe(10400);
});

it('ignores a tax amount without a tax code on expenses, bills and promoted inbox items', function () {
    $expense = app(SaveExpense::class)->handle([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Fournitures Lafleur',
        'lines' => [['account_id' => $this->expense->id, 'amount_cents' => 10000, 'tax_override_cents' => 700]],
    ]);

    expect($expense->lines->first()->tax_override_cents)->toBeNull()
        ->and($expense->lines->first()->tax_cents)->toBe(0)
        ->and($expense->amount_cents)->toBe(10000);

    $bill = app(SaveBill::class)->handle([
        'contact_id' => $this->vendor->id,
        'bill_no' => 'BILL-PT-2',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'lines' => [[
            'account_id' => $this->expense->id, 'quantity' => '1', 'unit_price_cents' => 10000,
            'tax_override_cents' => 700, 'secondary_tax_override_cents' => 300,
        ]],
    ]);

    expect($bill->lines->first()->tax_override_cents)->toBeNull()
        ->and($bill->lines->first()->line_tax_cents)->toBe(0)
        ->and($bill->lines->first()->secondary_tax_override_cents)->toBeNull()
        ->and($bill->total_cents)->toBe(10000);

    $item = perTaxOverrideInboxItem(10000);

    $promoted = app(PromoteInboxItem::class)->handle($item, [
        'document_type' => 'expense',
        'payment_account_id' => $this->bank->id,
        'lines' => [['account_id' => $this->expense->id, 'amount_cents' => 10000, 'tax_override_cents' => 700]],
    ]);

    expect($promoted->lines->first()->tax_override_cents)->toBeNull()
        ->and($promoted->amount_cents)->toBe(10000);
});

/*
 * Show pages: one tax row per code
 */

it('lists both tax rows on a posted cheque with GST and PST', function () {
    $cheque = app(SaveCheque::class)->handle([
        'bank_account_id' => $this->bank->id,
        'cheque_no' => '2001',
        'cheque_date' => now()->toDateString(),
        'payee_name' => 'Island Hardware',
        'lines' => [[
            'account_id' => $this->expense->id,
            'amount_cents' => 10000,
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->pst->id,
        ]],
    ]);
    app(ChequePoster::class)->post($cheque->fresh('lines'));

    $html = Livewire::test('pages::cheques.show', ['company' => $this->company, 'cheque' => $cheque->fresh()])->html();

    expect(substr_count($html, 'data-test="cheque-tax-row"'))->toBe(2)
        ->and($html)->toContain('GST (5%) 5.00%')
        ->and($html)->toContain('PST BC (7%) 7.00%');
});

it('lists both tax rows on a posted expense with GST and PST', function () {
    $expense = app(SaveExpense::class)->handle([
        'payment_account_id' => $this->bank->id,
        'expense_date' => now()->toDateString(),
        'payee_name' => 'Island Hardware',
        'lines' => [[
            'account_id' => $this->expense->id,
            'amount_cents' => 10000,
            'tax_code_id' => $this->gst->id,
            'secondary_tax_code_id' => $this->pst->id,
        ]],
    ]);
    app(ExpensePoster::class)->post($expense->fresh('lines'));

    $html = Livewire::test('pages::expenses.show', ['company' => $this->company, 'expense' => $expense->fresh()])->html();

    expect(substr_count($html, 'data-test="expense-tax-row"'))->toBe(2)
        ->and($html)->toContain('GST (5%) 5.00%')
        ->and($html)->toContain('PST BC (7%) 7.00%');
});
