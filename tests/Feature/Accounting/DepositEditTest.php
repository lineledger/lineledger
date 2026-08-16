<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\DepositStatus;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->undep = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    $this->owner = Account::query()->where('name', 'Owner Contributions')->first();

    // A posted receipt sitting in Undeposited Funds, ready to be deposited.
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->customer = Contact::create(['display_name' => 'Pay Customer', 'is_customer' => true]);

    $inv = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-E-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);
    $inv->lines()->create([
        'account_id' => $income->id, 'description' => 'x', 'quantity' => '1',
        'unit_price_cents' => 15000, 'line_subtotal_cents' => 15000,
        'line_tax_cents' => 0, 'line_total_cents' => 15000, 'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($inv);

    $this->receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-E-1',
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undep->id,
        'amount_cents' => 15000,
    ]);
    $this->receipt->applications()->create(['invoice_id' => $inv->fresh()->id, 'amount_cents' => 15000]);
    app(ReceiptPoster::class)->post($this->receipt->fresh('applications'));
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function postedReceiptDeposit(): Deposit
{
    $deposit = Deposit::create([
        'bank_account_id' => test()->bank->id,
        'deposit_no' => 'DEP-EDIT-1',
        'deposit_date' => now()->toDateString(),
    ]);

    $deposit->lines()->create([
        'customer_receipt_id' => test()->receipt->id,
        'description' => 'Receipt REC-E-1',
        'amount_cents' => 15000,
        'line_order' => 0,
    ]);

    app(DepositPoster::class)->post($deposit->fresh('lines'));

    return $deposit->fresh('lines');
}

it('opens the edit form pre-filled with the deposit and its included receipt', function () {
    $deposit = postedReceiptDeposit();

    Livewire::test('pages::deposits.form', ['company' => $this->company, 'deposit' => $deposit])
        ->assertSet('deposit_no', 'DEP-EDIT-1')
        ->assertSet('bank_account_id', $this->bank->id)
        ->assertSet('availableReceipts.0.receipt_id', $this->receipt->id)
        ->assertSet('availableReceipts.0.included', true)
        ->assertSee('Edit deposit');
});

it('edits a posted deposit and reposts the GL on the same journal entry', function () {
    $deposit = postedReceiptDeposit();
    $entryId = $deposit->journal_entry_id;

    expect($this->bank->fresh()->balance_cents)->toBe(15000);

    Livewire::test('pages::deposits.form', ['company' => $this->company, 'deposit' => $deposit])
        ->call('addOtherLine')
        ->set('otherLines.0.account_id', $this->owner->id)
        ->set('otherLines.0.amount', '50.00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $deposit->refresh();

    expect($deposit->status)->toBe(DepositStatus::Posted)
        ->and($deposit->amount_cents)->toBe(20000)
        ->and($deposit->journal_entry_id)->toBe($entryId)
        ->and($this->bank->fresh()->balance_cents)->toBe(20000);
});

it('releases a receipt back to Undeposited Funds when removed from the deposit', function () {
    $deposit = postedReceiptDeposit();

    expect($this->undep->fresh()->balance_cents)->toBe(0)
        ->and($this->bank->fresh()->balance_cents)->toBe(15000);

    Livewire::test('pages::deposits.form', ['company' => $this->company, 'deposit' => $deposit])
        ->set('availableReceipts.0.included', false)
        ->call('addOtherLine')
        ->set('otherLines.0.account_id', $this->owner->id)
        ->set('otherLines.0.amount', '50.00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $deposit->refresh();

    expect($deposit->amount_cents)->toBe(5000)
        ->and($this->bank->fresh()->balance_cents)->toBe(5000)
        ->and($this->undep->fresh()->balance_cents)->toBe(15000);
});

it('blocks editing a deposit whose bank account is reconciled through that date', function () {
    $deposit = postedReceiptDeposit();

    BankReconciliation::factory()->completed()->create([
        'company_id' => $this->company->id,
        'account_id' => $this->bank->id,
        'statement_date' => now()->addDay()->toDateString(),
    ]);

    Livewire::test('pages::deposits.form', ['company' => $this->company, 'deposit' => $deposit])
        ->set('memo', 'changed')
        ->call('save')
        ->assertHasErrors('deposit')
        ->assertNoRedirect();

    expect($this->bank->fresh()->balance_cents)->toBe(15000);
});

it('forbids editing a voided deposit', function () {
    $deposit = postedReceiptDeposit();
    app(DepositPoster::class)->void($deposit);

    Livewire::test('pages::deposits.form', ['company' => $this->company, 'deposit' => $deposit->fresh()])
        ->assertStatus(403);
});

it('shows an Edit button on a posted deposit', function () {
    $deposit = postedReceiptDeposit();

    Livewire::test('pages::deposits.show', ['company' => $this->company, 'deposit' => $deposit])
        ->assertSeeHtml('data-test="edit-deposit-button"');
});

it('shows a Duplicate button on the deposit page', function () {
    $deposit = postedReceiptDeposit();

    Livewire::test('pages::deposits.show', ['company' => $this->company, 'deposit' => $deposit])
        ->assertSeeHtml('data-test="duplicate-deposit-button"');
});

it('prefills a fresh draft from a source deposit, copying other lines but not receipts', function () {
    $deposit = postedReceiptDeposit();
    $deposit->lines()->create([
        'account_id' => $this->owner->id,
        'description' => 'Owner top-up',
        'amount_cents' => 5000,
        'line_order' => 1,
    ]);

    $component = Livewire::withQueryParams(['from' => $deposit->id])
        ->test('pages::deposits.form', ['company' => $this->company]);

    $component
        ->assertSet('deposit', null)
        ->assertSet('bank_account_id', $this->bank->id)
        ->assertCount('otherLines', 1)
        ->assertSet('otherLines.0.account_id', $this->owner->id)
        ->assertSet('otherLines.0.amount', '50.00');

    // Fresh number, not the source's.
    expect($component->get('deposit_no'))->not->toBe('DEP-EDIT-1')
        ->and($component->get('deposit_date'))->toBe($this->company->currentDateTime()->toDateString());
});

it('ignores from= when the source deposit belongs to another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);

    $otherBank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $otherDeposit = Deposit::create([
        'bank_account_id' => $otherBank->id,
        'deposit_no' => 'DEP-OTHER-1',
        'deposit_date' => now()->toDateString(),
    ]);

    app()->instance('current_company', $this->company);

    Livewire::withQueryParams(['from' => $otherDeposit->id])
        ->test('pages::deposits.form', ['company' => $this->company])
        ->assertCount('otherLines', 0);
});
