<?php

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\DeletableDocumentType;
use App\Enums\DeletedTransactionState;
use App\Enums\InvoiceStatus;
use App\Exceptions\Deletion\DeletionBlockedException;
use App\Exceptions\Posting\PeriodLockedException;
use App\Exceptions\Posting\PostedDocumentDeletionException;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\DeletedTransaction;
use App\Models\Deposit;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\SalesReceipt;
use App\Services\Deletion\TransactionDeleter;
use App\Services\Posting\DepositPoster;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\EntryNumberGenerator;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\PeriodLockOverride;
use App\Services\Posting\ReceiptPoster;
use App\Services\Posting\SalesReceiptPoster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->actingAs(actAsCompanyOwner($this->company));
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
    $this->deleter = app(TransactionDeleter::class);
});

/**
 * Everything a delete must put back exactly: each account's posted total and
 * cached balance, each contact's AR and AP, each invoice's paid cache.
 *
 * @return array<string, mixed>
 */
function deletionLedgerState(Company $company): array
{
    return [
        'posted' => DB::table('journal_lines as l')->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.company_id', $company->id)->where('e.is_posted', true)
            ->groupBy('l.account_id')->orderBy('l.account_id')
            ->selectRaw('l.account_id, SUM(l.debit_cents) - SUM(l.credit_cents) AS net')
            ->pluck('net', 'l.account_id')->map(fn ($v) => (int) $v)->filter()->all(),
        'cached' => Account::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->pluck('balance_cents', 'id')->map(fn ($v) => (int) $v)->filter()->all(),
        'contacts' => Contact::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->get(['id', 'ar_balance_cents', 'ap_balance_cents'])->map(fn ($c) => [(int) $c->ar_balance_cents, (int) $c->ap_balance_cents])->all(),
        'invoices' => Invoice::withoutGlobalScopes()->where('company_id', $company->id)->orderBy('id')->get(['id', 'amount_paid_cents', 'status'])->map(fn ($i) => [(int) $i->amount_paid_cents, $i->status->value])->all(),
    ];
}

function deletionInvoice(int $cents = 10000, ?string $date = null, bool $post = true): Invoice
{
    $date ??= now()->toDateString();
    $invoice = Invoice::create([
        'contact_id' => test()->customer->id,
        'invoice_no' => app(DocumentNumberGenerator::class)->next(test()->company, Invoice::class, 'invoice_no', 'INV'),
        'invoice_date' => $date,
        'due_date' => $date,
    ]);
    $invoice->lines()->create([
        'account_id' => test()->income->id, 'description' => 'Services', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents, 'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);

    if ($post) {
        app(InvoicePoster::class)->post($invoice);
    }

    return $invoice->fresh();
}

function deletionReceipt(int $cents, ?Invoice $appliedTo = null): CustomerReceipt
{
    $receipt = CustomerReceipt::create([
        'contact_id' => test()->customer->id,
        'receipt_no' => app(DocumentNumberGenerator::class)->next(test()->company, CustomerReceipt::class, 'receipt_no', 'REC'),
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => test()->undeposited->id,
        'amount_cents' => $cents,
    ]);

    if ($appliedTo !== null) {
        $receipt->applications()->create(['invoice_id' => $appliedTo->id, 'amount_cents' => $cents]);
    }

    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    return $receipt->fresh();
}

function deletionSalesReceipt(int $cents = 5000): SalesReceipt
{
    $receipt = SalesReceipt::create([
        'contact_id' => test()->customer->id,
        'sales_receipt_no' => app(DocumentNumberGenerator::class)->next(test()->company, SalesReceipt::class, 'sales_receipt_no', 'SR'),
        'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => test()->undeposited->id,
        'status' => 'draft',
    ]);
    $receipt->lines()->create([
        'account_id' => test()->income->id, 'description' => 'Walk-in', 'quantity' => '1',
        'unit_price_cents' => $cents, 'line_subtotal_cents' => $cents, 'line_tax_cents' => 0, 'line_total_cents' => $cents, 'line_order' => 0,
    ]);
    app(SalesReceiptPoster::class)->post($receipt->fresh('lines'));

    return $receipt->fresh();
}

it('deletes a posted invoice as if it was never entered, and keeps an audited record', function () {
    $before = deletionLedgerState($this->company);
    $invoice = deletionInvoice();
    $entryId = $invoice->journal_entry_id;

    $tombstone = $this->deleter->delete($invoice, 'Entered by mistake');

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeFalse()
        ->and(JournalEntry::query()->whereKey($entryId)->exists())->toBeFalse()
        ->and(JournalLine::query()->where('journal_entry_id', $entryId)->exists())->toBeFalse()
        ->and($tombstone->document_type)->toBe(DeletableDocumentType::Invoice)
        ->and($tombstone->state_at_deletion)->toBe(DeletedTransactionState::Posted)
        ->and($tombstone->document_number)->toBe($invoice->invoice_no)
        ->and($tombstone->reason)->toBe('Entered by mistake')
        ->and($tombstone->snapshot['journal_entries'][0]['lines'])->toHaveCount(2);

    $audit = AccountingAuditLog::query()->where('action', AuditAction::InvoiceDeleted->value)->sole();
    expect($audit->auditable_type)->toBe(DeletedTransaction::class)
        ->and((int) $audit->auditable_id)->toBe($tombstone->id)
        ->and($audit->payload['document_number'])->toBe($invoice->invoice_no);

    // The posted row about the entry survives, with its link to the deleted entry cleared.
    expect(AccountingAuditLog::query()->where('action', AuditAction::InvoicePosted->value)->sole()->journal_entry_id)->toBeNull();

    expect(Artisan::call('audit:verify', ['company' => $this->company->id, '--full' => true, '--no-alert' => true]))->toBe(0)
        ->and(Artisan::call('integrity:check', ['company' => $this->company->id, '--no-alert' => true, '--no-log' => true]))->toBe(0);
});

it('deletes a voided invoice together with the reversal its void wrote', function () {
    $before = deletionLedgerState($this->company);
    $invoice = deletionInvoice();
    app(InvoicePoster::class)->void($invoice);
    $reversalId = JournalEntry::query()->findOrFail($invoice->journal_entry_id)->reversed_by_entry_id;

    $tombstone = $this->deleter->delete($invoice->fresh());

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(JournalEntry::query()->whereKey([$invoice->journal_entry_id, $reversalId])->exists())->toBeFalse()
        ->and($tombstone->state_at_deletion)->toBe(DeletedTransactionState::Voided)
        ->and($tombstone->snapshot['journal_entries'])->toHaveCount(2);
});

it('hard-deletes a draft and records it', function () {
    $invoice = deletionInvoice(post: false);

    $tombstone = $this->deleter->delete($invoice);

    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeFalse()
        ->and(DB::table('invoice_lines')->where('invoice_id', $invoice->id)->exists())->toBeFalse()
        ->and($tombstone->state_at_deletion)->toBe(DeletedTransactionState::Draft);
});

it('never hands out a deleted invoice number or entry number again, but accepts one typed by hand', function () {
    deletionInvoice();
    $latest = deletionInvoice();
    $entryNo = JournalEntry::query()->findOrFail($latest->journal_entry_id)->entry_no;

    $this->deleter->delete($latest);

    $next = app(DocumentNumberGenerator::class)->next($this->company, Invoice::class, 'invoice_no', 'INV');
    $nextEntry = app(EntryNumberGenerator::class)->next($this->company);

    expect($next)->not->toBe($latest->invoice_no)
        ->and($next > $latest->invoice_no)->toBeTrue()
        ->and($nextEntry)->not->toBe($entryNo)
        ->and($nextEntry > $entryNo)->toBeTrue()
        ->and(app(DocumentNumberGenerator::class)->isTaken($this->company, Invoice::class, 'invoice_no', $latest->invoice_no))->toBeFalse();
});

it('refuses to delete an invoice a payment is applied to, then deletes the payment and reopens the invoice', function () {
    $invoice = deletionInvoice(10000);
    $before = deletionLedgerState($this->company);
    $receipt = deletionReceipt(10000, $invoice);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Paid);

    $preview = $this->deleter->preview($invoice->fresh());
    expect($preview->allowed())->toBeFalse()
        ->and($preview->blockers[0]->message)->toContain($receipt->receipt_no)
        ->and($preview->blockers[0]->url)->toContain('/receipts/'.$receipt->id);

    expect(fn () => $this->deleter->delete($invoice->fresh()))->toThrow(DeletionBlockedException::class);

    $this->deleter->delete($receipt);

    expect($invoice->fresh()->status)->toBe(InvoiceStatus::Posted)
        ->and((int) $invoice->fresh()->amount_paid_cents)->toBe(0)
        ->and(deletionLedgerState($this->company))->toBe($before);
});

it('refuses to delete a sales receipt a deposit holds', function () {
    $receipt = deletionSalesReceipt();
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->firstOrFail();
    $deposit = Deposit::create(['deposit_no' => 'DEP-1', 'deposit_date' => now()->toDateString(), 'bank_account_id' => $bank->id]);
    $deposit->lines()->create(['sales_receipt_id' => $receipt->id, 'account_id' => $this->undeposited->id, 'amount_cents' => 5000, 'line_order' => 0]);
    app(DepositPoster::class)->post($deposit->fresh('lines'));

    expect(fn () => $this->deleter->delete($receipt))->toThrow(DeletionBlockedException::class);
    expect(SalesReceipt::query()->whereKey($receipt->id)->exists())->toBeTrue();
});

it('deletes a posted sales receipt and puts the ledger back', function () {
    $before = deletionLedgerState($this->company);
    $receipt = deletionSalesReceipt();

    $this->deleter->delete($receipt);

    expect(deletionLedgerState($this->company))->toBe($before)
        ->and(SalesReceipt::withTrashed()->whereKey($receipt->id)->exists())->toBeFalse();
});

it('raises the period lock for a transaction in a closed period, and lets an override through', function () {
    $invoice = deletionInvoice(date: now()->subMonths(2)->toDateString());
    $this->company->forceFill(['lock_date' => now()->subMonth()->toDateString()])->save();

    expect(fn () => $this->deleter->delete($invoice->fresh()))->toThrow(PeriodLockedException::class);

    [, $crossed] = PeriodLockOverride::run($this->company->fresh(), fn () => $this->deleter->delete($invoice->fresh()));

    expect($crossed)->not->toBe([])
        ->and(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeFalse();
});

it('only deletes drafts while the organization turns deleting off', function () {
    $this->company->setTransactionDeletionAllowed(false);

    expect(fn () => $this->deleter->delete(deletionInvoice()))->toThrow(DeletionBlockedException::class);

    $this->deleter->delete(deletionInvoice(post: false));

    expect(DeletedTransaction::query()->count())->toBe(1);
});

it('refuses through the model to delete a posted document outside the deleter', function () {
    $invoice = deletionInvoice();

    expect(fn () => $invoice->forceDelete())->toThrow(PostedDocumentDeletionException::class);
});

it('has integrity:check report a document and its journal entry left without each other', function () {
    $invoice = deletionInvoice();
    $other = deletionInvoice();

    // Around the deleter: the invoice row goes, its entry stays …
    DB::table('invoice_lines')->where('invoice_id', $invoice->id)->delete();
    DB::table('invoices')->where('id', $invoice->id)->delete();
    // … and an entry goes, its invoice stays posted.
    DB::table('journal_lines')->where('journal_entry_id', $other->journal_entry_id)->delete();
    DB::table('journal_entries')->where('id', $other->journal_entry_id)->delete();

    expect(Artisan::call('integrity:check', ['company' => $this->company->id, '--no-alert' => true, '--no-log' => true]))->toBe(1);

    $output = Artisan::output();

    expect($output)->toContain('Journal entries whose invoice no longer exists (1)')
        ->and($output)->toContain('Posted or voided invoices with no journal entry (1): '.$other->invoice_no);
});
