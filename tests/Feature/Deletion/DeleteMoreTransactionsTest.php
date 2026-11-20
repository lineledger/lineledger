<?php

use App\Actions\Accounting\ReverseJournalEntry;
use App\Actions\Purchasing\SaveExpense;
use App\Actions\Purchasing\SavePurchaseOrder;
use App\Actions\Purchasing\SaveVendorCredit;
use App\Actions\Sales\ConvertEstimateToSalesOrder;
use App\Actions\Sales\SaveEstimate;
use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\DeletedTransactionState;
use App\Enums\EstimateStatus;
use App\Enums\InboxItemStatus;
use App\Enums\StatementLineMatchStatus;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\BankStatementImport;
use App\Models\BankStatementLine;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Cheque;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CreditMemo;
use App\Models\CustomerReceipt;
use App\Models\Deposit;
use App\Models\Estimate;
use App\Models\Expense;
use App\Models\InboxItem;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Transfer;
use App\Models\VendorCredit;
use App\Services\Deletion\TransactionDeleter;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\ExpensePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\ReceiptPoster;
use App\Services\Posting\VendorCreditPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->owner = actAsCompanyOwner($this->company);
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->vendor = Contact::create(['display_name' => 'Supplier', 'is_vendor' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->deleter = app(TransactionDeleter::class);
    $this->date = now()->toDateString();
});

afterEach(function () {
    app()->forgetInstance('current_api_key');
});

/** POST to the API (which posts by default) and return the created model. */
function moreDeletionPost(string $uri, array $payload, string $class): mixed
{
    $id = test()->postJson("/api/v1/{$uri}", $payload, test()->h)->assertStatus(201)->json('data.id');
    app()->instance('current_company', test()->company);

    return $class::query()->findOrFail($id);
}

function moreDeletionBill(int $cents = 10000): Bill
{
    return moreDeletionPost('bills', [
        'contact_id' => test()->vendor->id, 'bill_date' => test()->date,
        'lines' => [['description' => 'Widgets', 'quantity' => '1', 'unit_price_cents' => $cents, 'account_id' => test()->expense->id]],
    ], Bill::class);
}

it('deletes each posted type and puts the ledger back exactly', function (Closure $make) {
    $before = deletionLedgerState($this->company);
    $document = $make();

    expect($document->journal_entry_id)->not->toBeNull();

    $record = $this->deleter->delete($document->fresh());

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and($document::query()->withoutGlobalScopes()->whereKey($document->id)->exists())->toBeFalse()
        ->and(JournalEntry::query()->whereKey($document->journal_entry_id)->exists())->toBeFalse()
        ->and($record->state_at_deletion)->toBe(DeletedTransactionState::Posted);

    expect(Artisan::call('audit:verify', ['company' => $this->company->id, '--full' => true, '--no-alert' => true]))->toBe(0)
        ->and(Artisan::call('integrity:check', ['company' => $this->company->id, '--no-alert' => true, '--no-log' => true]))->toBe(0);
})->with([
    'credit memo' => fn () => moreDeletionPost('credit-memos', [
        'contact_id' => test()->customer->id, 'credit_memo_date' => test()->date,
        'lines' => [['description' => 'Credit', 'quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => test()->income->id]],
    ], CreditMemo::class),
    'bill' => fn () => moreDeletionBill(),
    'vendor credit' => fn () => tap(app(SaveVendorCredit::class)->handle([
        'contact_id' => test()->vendor->id, 'vendor_credit_date' => test()->date,
        'lines' => [['account_id' => test()->expense->id, 'description' => 'Return', 'quantity' => 1, 'unit_price_cents' => 3000]],
    ]), fn (VendorCredit $c) => app(VendorCreditPoster::class)->post($c))->fresh(),
    'expense' => fn () => tap(app(SaveExpense::class)->handle([
        'payment_account_id' => test()->bank->id, 'expense_date' => test()->date, 'payee_name' => 'Coffee',
        'lines' => [['account_id' => test()->expense->id, 'description' => 'Coffee', 'amount_cents' => 1200]],
    ]), fn (Expense $e) => app(ExpensePoster::class)->post($e))->fresh(),
    'cheque' => fn () => moreDeletionPost('cheques', [
        'bank_account_id' => test()->bank->id, 'cheque_no' => '2001', 'cheque_date' => test()->date, 'payee_name' => 'Hydro',
        'lines' => [['account_id' => test()->expense->id, 'description' => 'Power', 'amount_cents' => 7500]],
    ], Cheque::class),
    'deposit' => fn () => moreDeletionPost('deposits', [
        'bank_account_id' => test()->bank->id, 'deposit_date' => test()->date,
        'lines' => [['account_id' => test()->income->id, 'description' => 'Sale', 'amount_cents' => 25000]],
    ], Deposit::class),
    'transfer' => fn () => moreDeletionPost('transfers', [
        'from_account_id' => test()->bank->id,
        'to_account_id' => Account::query()->where('subtype', AccountSubtype::Bank->value)->whereKeyNot(test()->bank->id)->value('id')
            ?? Account::create(['code' => '1099', 'name' => 'Savings', 'type' => test()->bank->type, 'subtype' => AccountSubtype::Bank, 'normal_balance' => test()->bank->normal_balance])->id,
        'transfer_date' => test()->date, 'from_amount_cents' => 4000, 'to_amount_cents' => 4000,
    ], Transfer::class),
]);

it('deletes a bill payment, reopening the bill it paid', function () {
    $bill = moreDeletionBill(4000);
    $before = deletionLedgerState($this->company);
    $payment = moreDeletionPost('bill-payments', [
        'contact_id' => $this->vendor->id, 'payment_date' => $this->date, 'paid_from_account_id' => $this->bank->id,
        'amount_cents' => 4000, 'applications' => [['bill_id' => $bill->id, 'amount_cents' => 4000]],
    ], BillPayment::class);

    expect($bill->fresh()->status)->toBe(BillStatus::Paid);
    expect(fn () => $this->deleter->delete($bill->fresh()))->toThrow(DeletionBlockedException::class);

    $this->deleter->delete($payment);

    expect($bill->fresh()->status)->toBe(BillStatus::Posted)
        ->and(deletionLedgerState($this->company))->toBe($before);
});

it('deletes a deposit, so the receipt it held can be deposited again', function () {
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id, 'receipt_no' => 'REC-1', 'receipt_date' => $this->date,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'), 'amount_cents' => 900,
    ]);
    app(ReceiptPoster::class)->post($receipt);
    $deposit = moreDeletionPost('deposits', [
        'bank_account_id' => $this->bank->id, 'deposit_date' => $this->date,
        'lines' => [['customer_receipt_id' => $receipt->id, 'amount_cents' => 900]],
    ], Deposit::class);

    expect(fn () => $this->deleter->delete($receipt->fresh()))->toThrow(DeletionBlockedException::class);

    $this->deleter->delete($deposit);

    expect(DB::table('deposit_lines')->where('customer_receipt_id', $receipt->id)->exists())->toBeFalse();
    $this->deleter->delete($receipt->fresh());
    expect(CustomerReceipt::withTrashed()->whereKey($receipt->id)->exists())->toBeFalse();
});

it('keeps a deleted cheque number spent on its bank account', function () {
    $cheque = moreDeletionPost('cheques', [
        'bank_account_id' => $this->bank->id, 'cheque_no' => '3001', 'cheque_date' => $this->date, 'payee_name' => 'Rent',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Rent', 'amount_cents' => 100000]],
    ], Cheque::class);

    $this->deleter->delete($cheque);

    expect(app(DocumentNumberGenerator::class)->nextChequeNumber($this->company, $this->bank->id))->toBe('3002');
});

it('deletes a manual journal entry, and a voided one with its reversal', function () {
    $make = function (): JournalEntry {
        $entry = JournalEntry::create(['entry_no' => app(EntryNumberGenerator::class)->next($this->company), 'entry_date' => $this->date, 'memo' => 'Accrual']);
        $entry->lines()->create(['account_id' => $this->expense->id, 'debit_cents' => 500, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 500, 'line_order' => 1]);

        return app(JournalPoster::class)->post($entry->fresh('lines'));
    };

    $before = deletionLedgerState($this->company);
    $posted = $make();
    $this->deleter->delete($posted);

    $voided = $make();
    $reversal = app(JournalPoster::class)->void($voided);

    // The void's reversal goes only with its original.
    expect(fn () => $this->deleter->delete($reversal->fresh()))->toThrow(DeletionBlockedException::class);

    $record = $this->deleter->delete($voided->fresh());

    expect($record->state_at_deletion)->toBe(DeletedTransactionState::Voided)
        ->and(JournalEntry::query()->whereKey([$voided->id, $reversal->id])->exists())->toBeFalse()
        ->and(deletionLedgerState($this->company))->toBe($before);

    // An accrual reversal pointing at an entry blocks it until it goes first.
    $accrual = $make();
    $draftReversal = app(ReverseJournalEntry::class)->handle($accrual, CarbonImmutable::parse($this->date)->addMonth());

    expect(fn () => $this->deleter->delete($accrual->fresh()))->toThrow(DeletionBlockedException::class);

    $this->deleter->delete($draftReversal->fresh());
    $this->deleter->delete($accrual->fresh());

    expect(deletionLedgerState($this->company))->toBe($before);
});

it('refuses to delete a journal entry another document created', function () {
    $bill = moreDeletionBill();

    expect(fn () => $this->deleter->delete(JournalEntry::query()->findOrFail($bill->journal_entry_id)))->toThrow(DeletionBlockedException::class);
});

it('puts a bank-feed line back for review when the entry it created is deleted', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-900001', 'entry_date' => $this->date, 'memo' => 'Bank fee', 'source_type' => BankStatementImport::class]);
    $entry->lines()->create(['account_id' => $this->expense->id, 'debit_cents' => 300, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 0, 'credit_cents' => 300, 'line_order' => 1]);
    app(JournalPoster::class)->post($entry->fresh('lines'));
    $line = BankStatementLine::factory()->create([
        'company_id' => $this->company->id, 'account_id' => $this->bank->id, 'amount_cents' => -300,
        'bank_statement_import_id' => BankStatementImport::factory()->create(['company_id' => $this->company->id, 'account_id' => $this->bank->id])->id,
        'match_status' => StatementLineMatchStatus::Created, 'created_journal_entry_id' => $entry->id,
    ]);

    $this->deleter->delete($entry->fresh());

    expect($line->fresh()->match_status)->toBe(StatementLineMatchStatus::Unmatched)
        ->and($line->fresh()->created_journal_entry_id)->toBeNull();
});

it('sends an expense the inbox made back to review, with its file', function () {
    $expense = app(SaveExpense::class)->handle([
        'payment_account_id' => $this->bank->id, 'expense_date' => $this->date, 'payee_name' => 'Store',
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Supplies', 'amount_cents' => 2500]],
    ]);
    app(ExpensePoster::class)->post($expense);
    $attachment = Attachment::create([
        'attachable_type' => Expense::class, 'attachable_id' => $expense->id, 'disk' => 'local',
        'path' => 'inbox/receipt.pdf', 'original_filename' => 'receipt.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 10,
    ]);
    $item = InboxItem::create([
        'source' => 'upload', 'status' => InboxItemStatus::Promoted, 'attachment_id' => $attachment->id,
        'original_filename' => 'receipt.pdf', 'mime' => 'application/pdf', 'promoted_document_type' => 'expense', 'promoted_document_id' => $expense->id,
    ]);

    $this->deleter->delete($expense->fresh());

    expect($item->fresh()->status)->toBe(InboxItemStatus::NeedsReview)
        ->and($item->fresh()->promoted_document_id)->toBeNull()
        ->and($attachment->fresh()->attachable_type)->toBe(InboxItem::class)
        ->and((int) $attachment->fresh()->attachable_id)->toBe($item->id);
});

it('deletes estimates and orders, refusing an order an invoice came from', function () {
    $estimate = app(SaveEstimate::class)->handle([
        'contact_id' => $this->customer->id, 'estimate_date' => $this->date,
        'lines' => [['account_id' => $this->income->id, 'description' => 'Quote', 'quantity' => 1, 'unit_price_cents' => 1000]],
    ]);
    $estimate->forceFill(['status' => EstimateStatus::Accepted])->save();
    $order = app(ConvertEstimateToSalesOrder::class)->handle($estimate->fresh());

    expect($estimate->fresh()->status)->toBe(EstimateStatus::Converted);

    $record = $this->deleter->delete($order->fresh());

    expect($record->state_at_deletion)->toBe(DeletedTransactionState::Unposted)
        ->and($estimate->fresh()->status)->toBe(EstimateStatus::Accepted)
        ->and($estimate->fresh()->converted_sales_order_id)->toBeNull();

    $this->deleter->delete($estimate->fresh());

    $po = app(SavePurchaseOrder::class)->handle([
        'contact_id' => $this->vendor->id, 'po_date' => $this->date,
        'lines' => [['account_id' => $this->expense->id, 'description' => 'Parts', 'quantity' => 1, 'unit_price_cents' => 500]],
    ]);
    $this->deleter->delete($po);

    expect(Estimate::withTrashed()->whereKey($estimate->id)->exists())->toBeFalse()
        ->and(PurchaseOrder::withTrashed()->whereKey($po->id)->exists())->toBeFalse()
        ->and(SalesOrder::withTrashed()->whereKey($order->id)->exists())->toBeFalse();

    $linked = moreDeletionPost('sales-orders', [
        'contact_id' => $this->customer->id, 'order_date' => $this->date,
        'lines' => [['account_id' => $this->income->id, 'description' => 'Order', 'quantity' => '1', 'unit_price_cents' => 800]],
    ], SalesOrder::class);
    $invoice = Invoice::create(['contact_id' => $this->customer->id, 'invoice_no' => 'INV-SO1', 'invoice_date' => $this->date, 'due_date' => $this->date, 'sales_order_id' => $linked->id]);

    expect(fn () => $this->deleter->delete($linked->fresh()))->toThrow(DeletionBlockedException::class);
    expect($invoice->fresh())->not->toBeNull();
});

it('offers Delete on every new show page and deletes from it', function (string $page, string $param, Closure $make, string $index) {
    $document = $make();

    Livewire::actingAs($this->owner)
        ->test($page, ['company' => $this->company, $param => $document])
        ->assertSeeHtml('data-test="delete-transaction-button"')
        ->call('confirmDelete')
        ->assertRedirect(route($index, ['company' => $this->company->slug]));

    expect($document::query()->withoutGlobalScopes()->whereKey($document->id)->exists())->toBeFalse();
})->with([
    'bill' => ['pages::bills.show', 'bill', fn () => moreDeletionBill(), 'bills.index'],
    'cheque' => ['pages::cheques.show', 'cheque', fn () => moreDeletionPost('cheques', [
        'bank_account_id' => test()->bank->id, 'cheque_no' => '4001', 'cheque_date' => test()->date, 'payee_name' => 'X',
        'lines' => [['account_id' => test()->expense->id, 'description' => 'X', 'amount_cents' => 100]],
    ], Cheque::class), 'cheques.index'],
    'deposit' => ['pages::deposits.show', 'deposit', fn () => moreDeletionPost('deposits', [
        'bank_account_id' => test()->bank->id, 'deposit_date' => test()->date,
        'lines' => [['account_id' => test()->income->id, 'description' => 'X', 'amount_cents' => 100]],
    ], Deposit::class), 'deposits.index'],
    'credit memo' => ['pages::credit-memos.show', 'credit_memo', fn () => moreDeletionPost('credit-memos', [
        'contact_id' => test()->customer->id, 'credit_memo_date' => test()->date,
        'lines' => [['description' => 'X', 'quantity' => '1', 'unit_price_cents' => 100, 'account_id' => test()->income->id]],
    ], CreditMemo::class), 'credit-memos.index'],
]);
