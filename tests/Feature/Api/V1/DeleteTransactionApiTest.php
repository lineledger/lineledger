<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\DeletedTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\EditLocks\EditLockManager;

/*
 * DELETE ?permanent=true deletes a document as if never entered; plain DELETE
 * keeps voiding a posted one (deprecated, with a pointer to POST …/void) and
 * now hard-deletes a draft; POST …/void voids.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => 'owner']);
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test', $this->owner->id);
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function apiDeleteInvoice(bool $post = true, ?string $date = null): int
{
    return test()->postJson('/api/v1/invoices', [
        'contact_id' => test()->customer->id,
        'invoice_date' => $date ?? now()->toDateString(),
        'post' => $post,
        'lines' => [['description' => 'Work', 'quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => test()->income->id]],
    ], test()->h)->assertStatus(201)->json('data.id');
}

it('still voids a posted document on a plain DELETE, pointing at POST …/void', function () {
    $id = apiDeleteInvoice();

    $this->deleteJson("/api/v1/invoices/{$id}", [], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void')
        ->assertHeader('Deprecation', 'true')
        ->assertHeader('Link', '<'.url("/api/v1/invoices/{$id}").'/void>; rel="successor-version"');
});

it('deletes a posted document for good with ?permanent=true', function () {
    $id = apiDeleteInvoice();
    $entryId = Invoice::withoutGlobalScopes()->findOrFail($id)->journal_entry_id;

    $this->deleteJson("/api/v1/invoices/{$id}?permanent=true", ['reason' => 'Duplicate import'], $this->h)->assertStatus(204);

    $record = DeletedTransaction::withoutGlobalScopes()->sole();

    expect(Invoice::withoutGlobalScopes()->withTrashed()->whereKey($id)->exists())->toBeFalse()
        ->and(JournalEntry::withoutGlobalScopes()->whereKey($entryId)->exists())->toBeFalse()
        ->and($record->deleted_via)->toBe('api')
        ->and($record->reason)->toBe('Duplicate import')
        ->and($record->deleted_by_user_id)->toBe($this->owner->id);
});

it('answers 409 with the blockers when something still depends on it', function () {
    $id = apiDeleteInvoice();
    $this->postJson('/api/v1/receipts', [
        'contact_id' => $this->customer->id, 'receipt_date' => now()->toDateString(),
        'deposit_to_account_id' => $this->undeposited->id, 'amount_cents' => 5000,
        'applications' => [['invoice_id' => $id, 'amount_cents' => 5000]],
    ], $this->h)->assertStatus(201);

    $this->deleteJson("/api/v1/invoices/{$id}?permanent=true", [], $this->h)
        ->assertStatus(409)
        ->assertJsonCount(1, 'blockers');

    expect(Invoice::withoutGlobalScopes()->whereKey($id)->exists())->toBeTrue();
});

it('answers 422 for a closed period, with no override over the API', function () {
    $id = apiDeleteInvoice(date: now()->subMonths(2)->toDateString());
    $this->company->forceFill(['lock_date' => now()->subMonth()->toDateString()])->save();

    $this->deleteJson("/api/v1/invoices/{$id}?permanent=true", [], $this->h)->assertStatus(422);

    expect(Invoice::withoutGlobalScopes()->whereKey($id)->exists())->toBeTrue();
});

it('hard-deletes a draft on a plain DELETE, keeping the record', function () {
    $id = apiDeleteInvoice(post: false);

    $this->deleteJson("/api/v1/invoices/{$id}", [], $this->h)->assertStatus(204);

    expect(Invoice::withoutGlobalScopes()->withTrashed()->whereKey($id)->exists())->toBeFalse()
        ->and(DeletedTransaction::withoutGlobalScopes()->count())->toBe(1);
});

it('voids through POST …/void, and refuses a draft or a voided document', function () {
    $id = apiDeleteInvoice();

    $this->postJson("/api/v1/invoices/{$id}/void", [], $this->h)
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Invoice::withoutGlobalScopes()->findOrFail($id)->status)->toBe(InvoiceStatus::Void);

    $this->postJson("/api/v1/invoices/{$id}/void", [], $this->h)->assertStatus(409);
    $this->postJson('/api/v1/invoices/'.apiDeleteInvoice(post: false).'/void', [], $this->h)->assertStatus(409);
});

it('deletes a posted manual journal entry, and refuses an entry a document owns', function () {
    app()->instance('current_company', $this->company);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    app()->forgetInstance('current_company');

    $entryId = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['account_id' => $this->income->id, 'debit_cents' => 100, 'credit_cents' => 0],
            ['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 100],
        ],
    ], $this->h)->assertStatus(201)->json('data.id');

    $this->deleteJson("/api/v1/journal-entries/{$entryId}?permanent=true", [], $this->h)->assertStatus(204);

    $invoiceEntry = Invoice::withoutGlobalScopes()->findOrFail(apiDeleteInvoice())->journal_entry_id;

    $this->deleteJson("/api/v1/journal-entries/{$invoiceEntry}?permanent=true", [], $this->h)->assertStatus(409);
});

it('answers 423 while someone is editing the document in the web app', function () {
    $id = apiDeleteInvoice();
    $editor = User::factory()->create();
    $this->company->members()->attach($editor, ['role' => 'owner']);
    app()->instance('current_company', $this->company);
    app(EditLockManager::class)->acquire(Invoice::query()->findOrFail($id), $editor);
    app()->forgetInstance('current_company');

    $this->deleteJson("/api/v1/invoices/{$id}?permanent=true", [], $this->h)->assertStatus(423);
});
