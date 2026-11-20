<?php

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\PredefinedRole;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\DeletedTransaction;
use App\Models\Invoice;
use App\Models\SalesReceipt;
use App\Services\Deletion\TransactionDeleter;
use App\Services\EditLocks\EditLockManager;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->owner = actAsCompanyOwner($this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();
});

function pageInvoice(string $date = 'today', bool $post = true): Invoice
{
    $date = $date === 'today' ? now()->toDateString() : $date;
    $invoice = Invoice::create(['contact_id' => test()->customer->id, 'invoice_no' => 'INV-'.uniqid(), 'invoice_date' => $date, 'due_date' => $date]);
    $invoice->lines()->create([
        'account_id' => test()->income->id, 'description' => 'Consulting', 'quantity' => '1', 'unit_price_cents' => 7500,
        'line_subtotal_cents' => 7500, 'line_tax_cents' => 0, 'line_total_cents' => 7500, 'line_order' => 0,
    ]);

    if ($post) {
        app(InvoicePoster::class)->post($invoice);
    }

    return $invoice->fresh();
}

it('deletes a posted invoice from its page and lands on the invoice list', function () {
    $invoice = pageInvoice();

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->assertSee(__('Delete'))
        ->call('prepareDelete')
        ->assertSet('deletionPreview.blockers', [])
        ->set('deleteReason', 'Wrong customer')
        ->call('confirmDelete')
        ->assertRedirect(route('invoices.index', ['company' => $this->company->slug]));

    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeFalse()
        ->and(DeletedTransaction::query()->sole()->reason)->toBe('Wrong customer');
});

it('lists what blocks the delete, with a link, and leaves the invoice alone', function () {
    $invoice = pageInvoice();
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id, 'receipt_no' => 'REC-77', 'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id, 'amount_cents' => 7500,
    ]);
    $receipt->applications()->create(['invoice_id' => $invoice->id, 'amount_cents' => 7500]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice->fresh()])
        ->call('prepareDelete')
        ->assertSee('REC-77')
        ->assertSeeHtml('data-test="delete-transaction-blockers"')
        ->assertDontSeeHtml('data-test="delete-transaction-confirm"')
        ->call('confirmDelete')
        ->assertNoRedirect();

    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();
});

it('refuses Delete to a role without the Delete permission', function () {
    $invoice = pageInvoice();
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);

    Livewire::actingAs($sales)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->call('confirmDelete')
        ->assertForbidden();

    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();
});

it('offers Delete only on drafts while the organization turns it off', function () {
    $this->company->setTransactionDeletionAllowed(false);

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => pageInvoice()])
        ->assertDontSeeHtml('data-test="delete-transaction-button"');

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => pageInvoice(post: false)])
        ->assertSeeHtml('data-test="delete-transaction-button"');
});

it('asks for the password to delete in a closed period, then records the override', function () {
    $invoice = pageInvoice(now()->subMonths(2)->toDateString());
    $this->company->forceFill(['lock_date' => now()->subMonth()->toDateString()])->save();

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company->fresh(), 'invoice' => $invoice])
        ->call('confirmDelete')
        ->assertSet('periodLockRetryAction', 'confirmDelete')
        ->set('periodLockPassword', 'password')
        ->call('confirmPeriodLockOverride')
        ->assertRedirect(route('invoices.index', ['company' => $this->company->slug]));

    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeFalse()
        ->and(AccountingAuditLog::query()->where('action', AuditAction::PeriodLockOverridden->value)->exists())->toBeTrue();
});

it('waits while someone else is editing, and leaves no edit lock behind once deleted', function () {
    $invoice = pageInvoice();
    $jane = editLockMember($this->company);
    $locks = app(EditLockManager::class);
    $held = $locks->acquire($invoice, $jane);

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->call('confirmDelete')
        ->assertDispatched('toast-show')
        ->assertNoRedirect();

    expect(Invoice::query()->whereKey($invoice->id)->exists())->toBeTrue();

    $locks->release($held->token, $jane);

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice->fresh()])
        ->call('confirmDelete');

    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeFalse()
        ->and(DB::table('edit_locks')->where('lockable_type', Invoice::class)->where('lockable_id', $invoice->id)->exists())->toBeFalse();
});

it('deletes a draft sales receipt and a posted receipt from their pages', function () {
    $salesReceipt = SalesReceipt::create([
        'contact_id' => $this->customer->id, 'sales_receipt_no' => 'SR-9', 'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id, 'status' => 'draft',
    ]);

    Livewire::actingAs($this->owner)
        ->test('pages::sales-receipts.show', ['company' => $this->company, 'receipt' => $salesReceipt])
        ->call('confirmDelete')
        ->assertRedirect(route('sales-receipts.index', ['company' => $this->company->slug]));

    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id, 'receipt_no' => 'REC-9', 'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id, 'amount_cents' => 1000,
    ]);
    app(ReceiptPoster::class)->post($receipt);

    Livewire::actingAs($this->owner)
        ->test('pages::receipts.show', ['company' => $this->company, 'receipt' => $receipt->fresh()])
        ->call('confirmDelete')
        ->assertRedirect(route('receipts.index', ['company' => $this->company->slug]));

    expect(SalesReceipt::withTrashed()->whereKey($salesReceipt->id)->exists())->toBeFalse()
        ->and(CustomerReceipt::withTrashed()->whereKey($receipt->id)->exists())->toBeFalse()
        ->and(DeletedTransaction::query()->count())->toBe(2);
});

it('sends an old link to the record kept of it, and shows it on the report and in the audit log', function () {
    $invoice = pageInvoice();
    $voided = pageInvoice();
    app(InvoicePoster::class)->void($voided);
    $record = app(TransactionDeleter::class)->delete($invoice, 'Duplicate');

    $this->get(route('invoices.show', ['company' => $this->company->slug, 'invoice' => $invoice->id]))
        ->assertRedirect(route('deleted-transactions.show', ['company' => $this->company->slug, 'deletedTransaction' => $record->id]));

    $this->get(route('deleted-transactions.show', ['company' => $this->company->slug, 'deletedTransaction' => $record->id]))
        ->assertOk()
        ->assertSee($invoice->invoice_no)
        ->assertSee('Duplicate')
        ->assertSee('Consulting')
        ->assertSee(AuditAction::InvoiceDeleted->value);

    $this->get(route('reports.voided-deleted', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee($invoice->invoice_no)
        ->assertSee(__('Voided'))
        ->assertSee(__('Deleted'));

    $this->get(route('reports.audit-log', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee(__(':document :no (deleted)', ['document' => __('Invoice'), 'no' => $invoice->invoice_no]));
});

it('keeps a 404 for someone who may not see the record', function () {
    $invoice = pageInvoice();
    app(TransactionDeleter::class)->delete($invoice);

    $this->actingAs(predefinedRoleMember($this->company, PredefinedRole::Sales))
        ->get(route('invoices.show', ['company' => $this->company->slug, 'invoice' => $invoice->id]))
        ->assertNotFound();
});
