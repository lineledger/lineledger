<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\SecurityLog;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use Livewire\Livewire;

afterEach(function () {
    app()->forgetInstance('current_company');
});

beforeEach(function () {
    $this->admin = User::factory()->siteAdmin()->create(['name' => 'Ada Admin']);
    $this->actingAs($this->admin);

    $this->owner = User::factory()->create(['name' => 'Olive Owner']);
    $this->company = Company::factory()->create(['name' => 'Acme Books']);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
});

it('marks a company deleted without destroying its memberships', function () {
    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('deleteCompany');

    $this->assertSoftDeleted('companies', ['id' => $this->company->id]);

    // The pivot rows survive — that is what makes restore lossless.
    $this->assertDatabaseHas('company_members', [
        'company_id' => $this->company->id,
        'user_id' => $this->owner->id,
    ]);
});

it('locks members out of a deleted company and lets them back in on restore', function () {
    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('deleteCompany');

    $this->actingAs($this->owner)
        ->get('/'.$this->company->slug.'/dashboard')
        ->assertForbidden();

    $this->actingAs($this->admin);

    Livewire::test('pages::admin.company-show', ['company' => $this->company->fresh()])
        ->call('restoreCompany');

    expect($this->company->fresh()->trashed())->toBeFalse();

    $this->actingAs($this->owner)
        ->get('/'.$this->company->slug.'/dashboard')
        ->assertOk();
});

it('hides a deleted company from the owners switcher', function () {
    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('deleteCompany');

    expect($this->owner->fresh()->companies()->pluck('companies.id'))
        ->not->toContain($this->company->id);
});

it('permanently deletes a trashed company and its ledger', function () {
    $accountId = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->value('id');
    expect($accountId)->not->toBeNull();

    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('deleteCompany');

    Livewire::test('pages::admin.company-show', ['company' => $this->company->fresh()])
        ->set('purgeName', 'Acme Books')
        ->call('purgeCompany')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('companies', ['id' => $this->company->id]);
    $this->assertDatabaseMissing('accounts', ['id' => $accountId]);

    // The purge must not erase its own audit trail.
    expect(SecurityLog::query()->where('event', SecurityEvent::CompanyPurged->value)->exists())->toBeTrue();
});

it('purges a company that has posted accounting data', function () {
    // Exercises the RESTRICT chains a plain forceDelete() trips over:
    // journal_lines -> accounts, invoice_lines -> accounts, invoices -> contacts.
    app()->instance('current_company', $this->company);

    $customer = Contact::create(['display_name' => 'Acme Corp', 'is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-PURGE-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
    ]);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    $entryId = JournalEntry::query()->withoutGlobalScopes()
        ->where('company_id', $this->company->id)->value('id');
    expect($entryId)->not->toBeNull();

    app()->forgetInstance('current_company');

    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('deleteCompany');

    Livewire::test('pages::admin.company-show', ['company' => $this->company->fresh()])
        ->set('purgeName', 'Acme Books')
        ->call('purgeCompany')
        ->assertHasNoErrors();

    $this->assertDatabaseMissing('companies', ['id' => $this->company->id]);
    $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    $this->assertDatabaseMissing('invoice_lines', ['invoice_id' => $invoice->id]);
    $this->assertDatabaseMissing('journal_entries', ['id' => $entryId]);
    // journal_lines has no company_id — it cascades from its entry.
    $this->assertDatabaseMissing('journal_lines', ['journal_entry_id' => $entryId]);
    $this->assertDatabaseMissing('contacts', ['id' => $customer->id]);
    // The immutability trigger blocks a direct DELETE; only the cascade from
    // the companies row can clear the hash chain, and a purge must clear it.
    $this->assertDatabaseMissing('accounting_audit_logs', ['company_id' => $this->company->id]);
});

it('swaps the danger zone between the live and deleted states', function () {
    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->assertSee('Danger zone')
        ->assertSee('Mark as deleted')
        ->assertDontSee('Restore organization')
        ->call('deleteCompany')
        ->assertSee('Restore organization')
        ->assertSee('Delete permanently')
        ->assertSee('Type &quot;Acme Books&quot; to confirm', false)
        ->assertDontSee('Mark as deleted');
});

it('requires the exact name to purge', function () {
    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('deleteCompany');

    Livewire::test('pages::admin.company-show', ['company' => $this->company->fresh()])
        ->set('purgeName', 'Wrong Name')
        ->call('purgeCompany')
        ->assertHasErrors('purgeName');

    $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
});

it('refuses to purge a company that is not deleted', function () {
    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->set('purgeName', 'Acme Books')
        ->call('purgeCompany')
        ->assertForbidden();

    $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
});

it('filters the company list by deleted status', function () {
    $live = Company::factory()->create(['name' => 'Still Trading']);

    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->call('deleteCompany');

    Livewire::test('pages::admin.companies')
        ->set('statusFilter', 'deleted')
        ->assertSee('Acme Books')
        ->assertDontSee('Still Trading')
        ->set('statusFilter', 'active')
        ->assertSee('Still Trading')
        ->assertDontSee('Acme Books');
});

it('blocks a non-admin from the company lifecycle actions', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.company-show', ['company' => $this->company])
        ->assertStatus(404);

    foreach (['deleteCompany', 'restoreCompany'] as $method) {
        try {
            Livewire::test('pages::admin.company-show', ['company' => $this->company])->call($method);
        } catch (Throwable) {
            // 404 from the in-component guard — the write was blocked.
        }
    }

    expect($this->company->fresh()->trashed())->toBeFalse();
});
