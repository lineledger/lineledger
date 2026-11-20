<?php

use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\DeletedTransactionState;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\DeletedTransaction;
use App\Models\Invoice;
use App\Models\Item;
use App\Services\Posting\DocumentNumberGenerator;
use App\Services\Posting\InvoicePoster;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function purgeTestInvoice(string $no, bool $post = false): Invoice
{
    $invoice = Invoice::create(['contact_id' => test()->customer->id, 'invoice_no' => $no, 'invoice_date' => '2026-02-01', 'due_date' => '2026-02-01']);
    $invoice->lines()->create([
        'account_id' => test()->income->id, 'description' => 'Work', 'quantity' => '1', 'unit_price_cents' => 1000,
        'line_subtotal_cents' => 1000, 'line_tax_cents' => 0, 'line_total_cents' => 1000, 'line_order' => 0,
    ]);

    if ($post) {
        app(InvoicePoster::class)->post($invoice);
    }

    return $invoice->fresh();
}

it('purges a soft-deleted draft, keeping a record and its number', function () {
    $invoice = purgeTestInvoice('INV-000900');
    $invoice->delete();

    $this->artisan('deletions:purge-soft-deleted', ['--dry-run' => true])
        ->expectsOutputToContain('would purge 1 soft-deleted transaction(s)')
        ->assertSuccessful();

    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeTrue();

    $this->artisan('deletions:purge-soft-deleted')
        ->expectsOutputToContain('purged 1 soft-deleted transaction(s), kept 0')
        ->assertSuccessful();

    $record = DeletedTransaction::query()->sole();

    expect(Invoice::withTrashed()->whereKey($invoice->id)->exists())->toBeFalse()
        ->and(DB::table('invoice_lines')->where('invoice_id', $invoice->id)->exists())->toBeFalse()
        ->and($record->state_at_deletion)->toBe(DeletedTransactionState::Legacy)
        ->and($record->deleted_via)->toBe('upgrade')
        ->and($record->document_number)->toBe('INV-000900')
        ->and(AccountingAuditLog::query()->where('action', AuditAction::InvoiceDeleted->value)->exists())->toBeTrue()
        ->and(app(DocumentNumberGenerator::class)->next($this->company, Invoice::class, 'invoice_no', 'INV'))->toBe('INV-000901')
        ->and(Artisan::call('audit:verify', ['company' => $this->company->id, '--full' => true, '--no-alert' => true]))->toBe(0);

    // Idempotent.
    $this->artisan('deletions:purge-soft-deleted')->expectsOutputToContain('purged 0 soft-deleted transaction(s)')->assertSuccessful();
});

it('leaves a soft-deleted document that holds a journal entry or a Stripe payment', function () {
    $posted = purgeTestInvoice('INV-000901', post: true);
    DB::table('invoices')->where('id', $posted->id)->update(['deleted_at' => now()]);
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id, 'receipt_no' => 'REC-STRIPE', 'receipt_date' => '2026-02-01', 'amount_cents' => 500,
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
        'stripe_payment_intent_id' => 'pi_test_1',
    ]);
    $receipt->delete();

    $this->artisan('deletions:purge-soft-deleted')
        ->expectsOutputToContain('it holds a journal entry')
        ->expectsOutputToContain('a Stripe payment')
        ->expectsOutputToContain('purged 0 soft-deleted transaction(s), kept 2')
        ->assertSuccessful();

    expect(Invoice::withTrashed()->whereKey($posted->id)->exists())->toBeTrue()
        ->and(CustomerReceipt::withTrashed()->whereKey($receipt->id)->exists())->toBeTrue();
});

it('deletes unused soft-deleted list records and brings used ones back inactive', function () {
    $unused = Contact::create(['display_name' => 'Ghost', 'is_customer' => true]);
    $unused->delete();
    purgeTestInvoice('INV-000902');
    $this->customer->delete();
    $item = Item::factory()->create(['company_id' => $this->company->id]);
    $item->delete();

    $this->artisan('deletions:purge-soft-deleted')
        ->expectsOutputToContain('deleted 2 unused list record(s), restored 1 still in use as inactive')
        ->assertSuccessful();

    $restored = Contact::query()->find($this->customer->id);

    expect(Contact::withTrashed()->whereKey($unused->id)->exists())->toBeFalse()
        ->and(Item::withTrashed()->whereKey($item->id)->exists())->toBeFalse()
        ->and($restored)->not->toBeNull()
        ->and($restored->is_active)->toBeFalse();
});

it('is a step of app:upgrade', function () {
    $this->artisan('app:upgrade', ['--dry-run' => true])
        ->expectsOutputToContain('Soft-deleted records')
        ->assertSuccessful();
});
