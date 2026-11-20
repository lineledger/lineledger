<?php

use App\Actions\Companies\DeleteCompany;
use App\Actions\Companies\PurgeCompany;
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
use App\Support\Storage\StorageDisks;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * An organization with an owner, soft-deleted with the given purge date, the
 * way the owner-facing dialog (a date) or the admin portal (null) leaves it.
 */
function companyDeletedForPurgeCommandTest(string $name, ?CarbonInterface $purgeAt): Company
{
    $owner = User::factory()->create();
    $company = Company::factory()->create(['name' => $name]);
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    app(DeleteCompany::class)->handle($company, purgeAt: $purgeAt);

    return Company::withTrashed()->findOrFail($company->id);
}

it('purges an owner-deleted company once its grace period has ended, ledger and all', function () {
    $owner = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Acme Books']);
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    // Posted data exercises the RESTRICT chains a plain forceDelete() trips
    // over (journal_lines -> accounts, invoice_lines -> accounts, invoices ->
    // contacts) and the trigger-guarded audit log.
    app()->instance('current_company', $company);

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

    $entryId = JournalEntry::query()->where('company_id', $company->id)->value('id');
    expect($entryId)->not->toBeNull();

    app()->forgetInstance('current_company');

    app(DeleteCompany::class)->handle($company, $owner, now()->subMinute());

    $this->artisan('companies:purge-deleted')->assertSuccessful();

    $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    $this->assertDatabaseMissing('company_members', ['company_id' => $company->id]);
    $this->assertDatabaseMissing('invoices', ['id' => $invoice->id]);
    $this->assertDatabaseMissing('invoice_lines', ['invoice_id' => $invoice->id]);
    $this->assertDatabaseMissing('journal_entries', ['id' => $entryId]);
    $this->assertDatabaseMissing('journal_lines', ['journal_entry_id' => $entryId]);
    $this->assertDatabaseMissing('contacts', ['id' => $customer->id]);
    $this->assertDatabaseMissing('accounting_audit_logs', ['company_id' => $company->id]);

    // The purge's own record survives it, and is the only trace left.
    $log = SecurityLog::query()->where('event', SecurityEvent::CompanyPurged->value)->sole();
    expect($log->user_id)->toBeNull()
        ->and($log->metadata['company_id'])->toBe($company->id)
        ->and($log->metadata['name'])->toBe('Acme Books')
        ->and($log->metadata['actor'])->toBe('scheduler');
});

it('erases the purged company’s files', function () {
    Storage::fake(StorageDisks::attachments());

    $company = companyDeletedForPurgeCommandTest('Acme Books', now()->subDay());
    $path = "attachments/{$company->id}/receipt.pdf";
    Storage::disk(StorageDisks::attachments())->put($path, 'receipt');

    $this->artisan('companies:purge-deleted')->assertSuccessful();

    Storage::disk(StorageDisks::attachments())->assertMissing($path);
});

it('leaves companies that are not due: future purge dates, admin holds and live companies', function () {
    $notYetDue = companyDeletedForPurgeCommandTest('Not Yet Due', now()->addDay());
    $adminHold = companyDeletedForPurgeCommandTest('Admin Hold', null);

    // A live company never qualifies, even carrying a stale purge date.
    $live = Company::factory()->create(['name' => 'Still Live']);
    $live->forceFill(['purge_at' => now()->subDay()])->save();

    $this->artisan('companies:purge-deleted')
        ->expectsOutputToContain('Purged 0 organization(s).')
        ->assertSuccessful();

    expect(Company::withTrashed()->whereKey([$notYetDue->id, $adminHold->id, $live->id])->count())->toBe(3)
        ->and(SecurityLog::query()->where('event', SecurityEvent::CompanyPurged->value)->exists())->toBeFalse();
});

it('only lists what is due on a dry run', function () {
    $due = companyDeletedForPurgeCommandTest('Acme Books', now()->subDay());

    $this->artisan('companies:purge-deleted', ['--dry-run' => true])
        ->expectsOutputToContain('Would purge Acme Books')
        ->expectsOutputToContain('1 organization(s) due for purge. Nothing was changed.')
        ->assertSuccessful();

    expect(Company::withTrashed()->whereKey($due->id)->exists())->toBeTrue();
});

it('purges the rest when one company fails, and exits non-zero', function () {
    Exceptions::fake();

    $failing = companyDeletedForPurgeCommandTest('Broken Books', now()->subDay());
    $healthy = companyDeletedForPurgeCommandTest('Healthy Books', now()->subDay());

    $real = app(PurgeCompany::class);

    $this->mock(PurgeCompany::class, function ($mock) use ($failing, $real) {
        $mock->shouldReceive('handle')->andReturnUsing(function (Company $company) use ($failing, $real): void {
            if ($company->id === $failing->id) {
                throw new RuntimeException('Purge failed');
            }

            $real->handle($company);
        });
    });

    $this->artisan('companies:purge-deleted')
        ->expectsOutputToContain('Purged 1 organization(s). 1 failed.')
        ->assertFailed();

    Exceptions::assertReported(RuntimeException::class);

    // The failure stays soft-deleted with its purge date, so tomorrow's run retries it.
    expect(Company::withTrashed()->find($failing->id)?->purge_at)->not->toBeNull()
        ->and(Company::withTrashed()->whereKey($healthy->id)->exists())->toBeFalse();
});
