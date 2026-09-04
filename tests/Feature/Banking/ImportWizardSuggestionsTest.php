<?php

use App\Actions\Purchasing\SaveBill;
use App\Enums\AccountSubtype;
use App\Enums\AccountType;
use App\Enums\BankRuleMatchType;
use App\Enums\CompanyRole;
use App\Enums\StatementLineMatchStatus;
use App\Enums\StatementSuggestionSource;
use App\Models\Account;
use App\Models\BankReconciliation;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->firstOrFail();
    $this->expenseB = Account::query()->where('type', AccountType::Expense->value)->orderBy('code')->skip(1)->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

const WIZARD_SOCIO_CSV = "Date,Description,Amount\n2026-02-03,\"Pre-Authorized Payment, L SOCIO DIGITAL FEE/FRA    ,\",-2520.00\n";

/** Drive the wizard through upload + analyze and return the component. */
function importWizardWith(string $csv)
{
    $file = UploadedFile::fake()->createWithContent('statement.csv', $csv);

    return Livewire::test('pages::banking.import', ['company' => test()->company])
        ->set('account_id', test()->bank->id)
        ->set('upload', $file)
        ->call('startImport');
}

/** The single line of the most recent import. */
function wizardImportedLine(): BankStatementLine
{
    return BankStatementImport::query()->latest('id')->firstOrFail()->lines()->firstOrFail();
}

/** A prior month's categorization of the same payee (what history learns from). */
function wizardSeedHistory(string $description, int $accountId, ?int $contactId = null): void
{
    $prior = BankStatementImport::factory()->committed()->create(['account_id' => test()->bank->id]);

    BankStatementLine::factory()->create([
        'bank_statement_import_id' => $prior->id,
        'account_id' => test()->bank->id,
        'txn_date' => '2026-01-03',
        'amount_cents' => -252000,
        'description' => $description,
        'match_status' => StatementLineMatchStatus::Created->value,
        'suggested_account_id' => $accountId,
        'suggested_contact_id' => $contactId,
    ]);
}

function wizardOpenBill(Contact $vendor, int $cents, string $no = 'BILL-100'): Bill
{
    $bill = app(SaveBill::class)->handle([
        'contact_id' => $vendor->id,
        'bill_no' => $no,
        'bill_date' => '2026-01-20',
        'due_date' => '2026-02-15',
        'lines' => [['account_id' => test()->expense->id, 'quantity' => '1', 'unit_price_cents' => $cents]],
    ]);

    app(BillPoster::class)->post($bill);

    return $bill->fresh();
}

it('shows a remembered suggestion pre-filled with its reason and a Confirm button', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'L Socio Digital']);
    wizardSeedHistory('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812', $this->expense->id, $vendor->id);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    expect($line->match_status)->toBe(StatementLineMatchStatus::Unmatched)
        ->and($line->suggested_account_id)->toBe($this->expense->id)
        ->and($line->suggested_contact_id)->toBe($vendor->id)
        ->and($line->suggestion_source)->toBe(StatementSuggestionSource::History);

    $component
        ->assertSeeHtml('data-state="suggested"')
        ->assertSeeHtml('data-test="line-confirm-'.$line->id.'"')
        ->assertSeeHtml('data-test="line-change-'.$line->id.'"')
        ->assertSeeHtml('data-test="import-suggested-count"')
        ->assertSee('Looks like')
        ->assertSee('L Socio Digital');
});

it('confirms a suggestion, which posts it on commit as an expense to the remembered vendor', function () {
    $vendor = Contact::factory()->vendor()->create();
    wizardSeedHistory('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812', $this->expense->id, $vendor->id);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component->call('confirmSuggestion', $line->id);
    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);

    $component->call('commitImport')
        ->assertRedirect(route('banking.reconcile', ['company' => $this->company->slug, 'account' => $this->bank->id]));

    $line->refresh();
    $rec = BankReconciliation::query()->forAccount($this->bank->id)->inProgress()->firstOrFail();

    expect(Expense::query()->where('payee_contact_id', $vendor->id)->count())->toBe(1)
        ->and($line->created_journal_entry_id)->not->toBeNull()
        ->and($rec->markedLineIds())->toContain((int) $line->matched_journal_line_id);
});

it('warns before importing over an unconfirmed suggestion and can confirm them all', function () {
    wizardSeedHistory('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812', $this->expense->id);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component->call('commitImport')
        ->assertSet('unconfirmedCount', 1)
        ->assertNoRedirect();

    expect(BankStatementImport::query()->latest('id')->firstOrFail()->isCommitted())->toBeFalse();

    $component->call('confirmAllAndCommit')
        ->assertRedirect(route('banking.reconcile', ['company' => $this->company->slug, 'account' => $this->bank->id]));

    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created)
        ->and($line->fresh()->created_journal_entry_id)->not->toBeNull();
});

it('can import without the unconfirmed suggestions, leaving them in For Review', function () {
    wizardSeedHistory('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812', $this->expense->id);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component->call('commitImport')->assertNoRedirect();
    $component->call('commitWithoutSuggestions')
        ->assertRedirect(route('banking.reconcile', ['company' => $this->company->slug, 'account' => $this->bank->id]));

    expect(BankStatementImport::query()->latest('id')->firstOrFail()->isCommitted())->toBeTrue()
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull()
        ->and(BankStatementLine::query()->forReview()->whereKey($line->id)->exists())->toBeTrue();
});

it('records the outflow as an expense to the vendor picked on the row', function () {
    $vendor = Contact::factory()->vendor()->create();

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->set("lineCategory.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id);

    expect($line->fresh()->suggested_contact_id)->toBe($vendor->id)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);

    $component->call('commitImport')
        ->assertRedirect(route('banking.reconcile', ['company' => $this->company->slug, 'account' => $this->bank->id]));

    $expense = Expense::query()->where('payee_contact_id', $vendor->id)->firstOrFail();
    $rec = BankReconciliation::query()->forAccount($this->bank->id)->inProgress()->firstOrFail();

    expect($expense->amount_cents)->toBe(252000)
        ->and($rec->markedLineIds())->toContain((int) $line->fresh()->matched_journal_line_id);
});

it('offers Pay bill when the vendor has an open bill for the amount and records a bill payment', function () {
    $vendor = Contact::factory()->vendor()->create();
    $bill = wizardOpenBill($vendor, 252000);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->set("lineCategory.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSeeHtml('data-test="line-record-as-'.$line->id.'"')
        ->assertSee('BILL-100')
        ->assertSet("lineBill.{$line->id}", $bill->id);

    expect($line->fresh()->suggested_bill_id)->toBe($bill->id);

    $component->call('commitImport')
        ->assertRedirect(route('banking.reconcile', ['company' => $this->company->slug, 'account' => $this->bank->id]));

    expect(BillPayment::query()->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(0)
        ->and($bill->fresh()->balanceCents())->toBe(0);
});

it('offers no Pay bill option when the vendor has no open bill for the amount', function () {
    $vendor = Contact::factory()->vendor()->create();
    wizardOpenBill($vendor, 99900);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->set("lineCategory.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertDontSeeHtml('data-test="line-record-as-'.$line->id.'"');
});

it('Always do this creates a rule that pre-fills the next import', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'L Socio Digital']);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->set("lineCategory.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->call('createRule', $line->id)
        ->assertSeeHtml('data-test="line-rule-exists-'.$line->id.'"');

    $rule = BankRule::query()->firstOrFail();

    expect(BankRule::query()->count())->toBe(1)
        ->and($rule->match_type)->toBe(BankRuleMatchType::MerchantKey)
        ->and($rule->action_account_id)->toBe($this->expense->id)
        ->and($rule->action_contact_id)->toBe($vendor->id);

    $component->call('commitImport')->assertRedirect();

    // Next month: a different date, reference number and amount, same payee.
    // (The same amount would first be flagged as a possible match to the
    // expense just posted — the ledger matcher runs before suggestions.)
    $next = importWizardWith("Date,Description,Amount\n2026-03-03,\"PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 9901\",-2531.00\n");
    $line2 = wizardImportedLine();

    expect($line2->suggested_account_id)->toBe($this->expense->id)
        ->and($line2->suggested_contact_id)->toBe($vendor->id)
        ->and($line2->suggestion_source)->toBe(StatementSuggestionSource::Rule)
        ->and($line2->match_status)->toBe(StatementLineMatchStatus::Unmatched);

    $next->assertSeeHtml('data-test="line-confirm-'.$line2->id.'"')->assertSee('Categorized by rule');
});

it('disables Always do this when a rule already matches the line', function () {
    BankRule::create([
        'name' => 'Socio',
        'match_type' => BankRuleMatchType::Contains->value,
        'match_pattern' => 'socio',
        'action_account_id' => $this->expenseB->id,
        'is_active' => true,
        'priority' => 0,
    ]);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->assertSeeHtml('data-test="line-confirm-'.$line->id.'"')
        ->call('editSuggestion', $line->id)
        ->assertSeeHtml('data-test="line-rule-exists-'.$line->id.'"')
        ->assertDontSeeHtml('data-test="line-make-rule-'.$line->id.'"');
});

it('pre-fills the category from the vendor default and shows the hint', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Rogers', 'default_expense_account_id' => $this->expenseB->id]);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSet("lineCategory.{$line->id}", $this->expenseB->id)
        ->assertSee('Pre-filled from')
        ->assertSeeHtml('data-test="line-confirm-'.$line->id.'"');

    expect($line->fresh()->suggested_account_id)->toBe($this->expenseB->id)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched);
});

it('tells you the category will become the vendor default, and commit writes it back', function () {
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Rogers']);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->set("lineCategory.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSee("Will use as Rogers's default account")
        ->call('commitImport')
        ->assertRedirect();

    expect($vendor->fresh()->default_expense_account_id)->toBe($this->expense->id);
});

it('creates a vendor inline from the row picker', function () {
    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->set("lineContact.{$line->id}.query", 'Socio')
        ->call('startNewLineContact', $line->id)
        ->set("lineContact.{$line->id}.new_name", 'L Socio Digital')
        ->call('createLineContact', $line->id);

    $vendor = Contact::query()->where('display_name', 'L Socio Digital')->firstOrFail();

    expect($vendor->is_vendor)->toBeTrue()
        ->and($line->fresh()->suggested_contact_id)->toBe($vendor->id);

    $component->assertSet("lineContact.{$line->id}.id", $vendor->id);
});

it('pre-fills the tax from the vendor default and commit records the expense tax-inclusive', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'Rogers', 'default_tax_code_id' => $gst->id]);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    $component
        ->set("lineCategory.{$line->id}", $this->expense->id)
        ->call('selectLineContact', $line->id, $vendor->id)
        ->assertSet("lineTax.{$line->id}", [$gst->id])
        ->assertSeeHtml('data-test="line-tax-'.$line->id.'"')
        ->assertSee('Tax pre-filled from');

    expect($line->fresh()->suggested_tax_code_id)->toBe($gst->id);

    $component->call('commitImport')->assertRedirect();

    $expense = Expense::query()->where('payee_contact_id', $vendor->id)->firstOrFail();

    expect($expense->amount_cents)->toBe(252000)
        ->and($expense->lines->first()->tax_code_id)->toBe($gst->id)
        ->and($expense->lines->first()->amount_cents)->toBe(240000)
        ->and($expense->lines->first()->tax_cents)->toBe(12000);
});

it('names the tax on a suggested line summary and keeps it through Confirm', function () {
    $gst = TaxCode::query()->where('code', 'GST')->firstOrFail();
    $vendor = Contact::factory()->vendor()->create(['display_name' => 'L Socio Digital', 'default_tax_code_id' => $gst->id]);
    wizardSeedHistory('PRE-AUTHORIZED PAYMENT, L SOCIO DIGITAL FEE/FRA REF 8812', $this->expense->id, $vendor->id);

    $component = importWizardWith(WIZARD_SOCIO_CSV);
    $line = wizardImportedLine();

    expect($line->suggested_tax_code_id)->toBe($gst->id);

    $component
        ->assertSeeHtml('data-test="line-summary-'.$line->id.'"')
        ->assertSee('GST')
        ->call('confirmSuggestion', $line->id)
        ->call('commitImport')
        ->assertRedirect();

    expect(Expense::query()->where('payee_contact_id', $vendor->id)->value('amount_cents'))->toBe(252000)
        ->and(Expense::query()->where('payee_contact_id', $vendor->id)->firstOrFail()->lines->first()->tax_code_id)->toBe($gst->id);
});

it('the Pay bills picker on the wizard persists the allocation and commit records the payment', function () {
    $vendor = Contact::factory()->vendor()->create();
    $a = wizardOpenBill($vendor, 200000, 'BILL-200');
    $b = wizardOpenBill($vendor, 52000, 'BILL-201');

    $component = importWizardWith(WIZARD_SOCIO_CSV); // −2,520.00
    $line = wizardImportedLine();

    $component
        ->call('selectLineContact', $line->id, $vendor->id)
        // 2,000 + 520 is the only exact set, so it is offered straight away…
        ->assertSet("lineBill.{$line->id}", 'allocations')
        ->assertSee('Pay 2 bills (2,520.00)')
        ->assertSeeHtml('data-test="line-pay-bills-'.$line->id.'"')
        // …and the picker lets the user re-cut it: pay the second bill in full and the rest of the first.
        ->call('openPayBills', $line->id)
        ->set('payBillsRows.0.apply', '2,000.00')
        ->set('payBillsRows.1.apply', '520.00')
        ->call('savePayBills')
        ->assertHasNoErrors()
        ->call('confirmSuggestion', $line->id)
        ->call('commitImport')
        ->assertRedirect();

    expect(BillPayment::query()->count())->toBe(1)
        ->and($a->fresh()->balanceCents())->toBe(0)
        ->and($b->fresh()->balanceCents())->toBe(0)
        ->and($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Created);
});
