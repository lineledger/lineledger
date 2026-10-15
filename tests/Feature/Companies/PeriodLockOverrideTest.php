<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Enums\CompanyRole;
use App\Enums\PeriodLockMode;
use App\Exceptions\Posting\PeriodLockedException;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use App\Services\Posting\PeriodLockOverride;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * The lock date's two modes: Warn lets anyone who can use the form continue
 * after confirming; Block refuses, except for an Owner or Admin who re-enters
 * their password. Either way the crossing is written to the audit log, and the
 * API never overrides.
 */
beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));

    // The default User factory hashes the literal password "password".
    $this->owner = User::factory()->create();
    $this->accountant = User::factory()->create();
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 8, 'lock_date' => '2026-07-31']);
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->company->members()->attach($this->accountant, ['role' => CompanyRole::Accountant->value]);

    app()->instance('current_company', $this->company);

    $this->prepaid = Account::query()->where('subtype', AccountSubtype::CurrentAsset->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function yearEndEntryForm(Company $company, User $user)
{
    return Livewire::actingAs($user)
        ->test('pages::journal.form', ['company' => $company])
        ->set('entryDate', '2026-07-31')
        ->set('memo', 'AJE 1 — prepaid insurance')
        ->set('lines.0.account_id', test()->prepaid->id)
        ->set('lines.0.debit', '1200.00')
        ->set('lines.1.account_id', test()->expense->id)
        ->set('lines.1.credit', '1200.00');
}

function lockOverrideLogs(Company $company)
{
    return AccountingAuditLog::withoutGlobalScopes()
        ->where('company_id', $company->id)
        ->where('action', AuditAction::PeriodLockOverridden->value)
        ->get();
}

it('saves the lock mode with the lock date and records both in the audit log', function () {
    Livewire::actingAs($this->owner)
        ->test('pages::companies.edit', ['company' => $this->company])
        ->set('lockDate', '2026-07-31')
        ->set('lockMode', PeriodLockMode::Warn->value)
        ->set('lockPassword', 'password')
        ->call('confirmLockDate')
        ->assertHasNoErrors();

    expect($this->company->fresh()->lockMode())->toBe(PeriodLockMode::Warn);

    $log = AccountingAuditLog::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('action', AuditAction::PeriodLockChanged->value)
        ->latest('sequence')
        ->first();

    expect($log->payload['mode_from'])->toBe('block')
        ->and($log->payload['mode_to'])->toBe('warn');
});

it('defaults existing companies to Block', function () {
    expect($this->company->fresh()->lockMode())->toBe(PeriodLockMode::Block);
});

it('lets any member continue after confirming in Warn mode, and audits it', function () {
    $this->company->update(['lock_mode' => PeriodLockMode::Warn]);

    $form = yearEndEntryForm($this->company->fresh(), $this->accountant)
        ->call('postEntry')
        ->assertHasNoErrors()
        ->assertSet('periodLockRetryAction', 'postEntry')
        ->assertNoRedirect();

    expect(JournalEntry::query()->where('is_posted', true)->count())->toBe(0);

    $form->call('confirmPeriodLockOverride')->assertHasNoErrors()->assertRedirect();

    $entry = JournalEntry::query()->where('is_posted', true)->sole();
    $log = lockOverrideLogs($this->company)->sole();

    expect($entry->entry_date->toDateString())->toBe('2026-07-31')
        ->and($log->actor_user_id)->toBe($this->accountant->id)
        ->and($log->payload['mode'])->toBe('warn')
        ->and($log->payload['dates'])->toBe(['2026-07-31'])
        ->and($log->payload['action'])->toBe('postEntry');
});

it('blocks a member who is not an Owner or Admin in Block mode', function () {
    yearEndEntryForm($this->company, $this->accountant)
        ->call('postEntry')
        ->assertHasErrors('lines')
        ->assertSet('periodLockRetryAction', null);

    expect(JournalEntry::query()->where('is_posted', true)->count())->toBe(0);
});

it('lets an Owner override Block mode with their password', function () {
    $form = yearEndEntryForm($this->company, $this->owner)
        ->call('postEntry')
        ->assertHasNoErrors()
        ->assertSet('periodLockRetryAction', 'postEntry');

    $form->set('periodLockPassword', 'wrong-password')
        ->call('confirmPeriodLockOverride')
        ->assertHasErrors('periodLockPassword')
        ->assertNoRedirect();

    expect(JournalEntry::query()->where('is_posted', true)->count())->toBe(0)
        ->and(lockOverrideLogs($this->company))->toHaveCount(0);

    $form->set('periodLockPassword', 'password')
        ->call('confirmPeriodLockOverride')
        ->assertHasNoErrors()
        ->assertRedirect();

    $log = lockOverrideLogs($this->company)->sole();

    expect(JournalEntry::query()->where('is_posted', true)->count())->toBe(1)
        ->and($log->payload['mode'])->toBe('block')
        ->and($log->actor_user_id)->toBe($this->owner->id);
});

it('lets an Owner override the lock to correct a posted entry from the closed period', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-CLOSED', 'entry_date' => '2026-07-20', 'memo' => 'Posted']);
    $entry->lines()->createMany([
        ['account_id' => $this->prepaid->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => $this->expense->id, 'debit_cents' => 0, 'credit_cents' => 5000, 'line_order' => 1],
    ]);
    $this->company->update(['lock_date' => null]);
    app(JournalPoster::class)->post($entry);
    $this->company->update(['lock_date' => '2026-07-31']);

    Livewire::actingAs($this->owner)
        ->test('pages::journal.form', ['company' => $this->company->fresh(), 'entry' => $entry->fresh()])
        ->set('lines.0.debit', '60.00')
        ->set('lines.1.credit', '60.00')
        ->call('saveChanges')
        ->assertSet('periodLockRetryAction', 'saveChanges')
        ->set('periodLockPassword', 'password')
        ->call('confirmPeriodLockOverride')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect($entry->fresh()->lines->sum('debit_cents'))->toBe(6000)
        ->and(lockOverrideLogs($this->company)->sole()->payload['action'])->toBe('saveChanges');
});

it('never overrides the lock for the API, even in Warn mode', function () {
    $this->company->update(['lock_mode' => PeriodLockMode::Warn]);
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    app()->forgetInstance('current_company');

    $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-07-31',
        'memo' => 'API year-end entry',
        'lines' => [
            ['account_id' => $this->prepaid->id, 'debit_cents' => 5000, 'credit_cents' => 0],
            ['account_id' => $this->expense->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ], ['Authorization' => "Bearer {$plain}"])->assertStatus(422);
});

it('scopes an override to the one company it was granted for', function () {
    $other = Company::factory()->create(['lock_date' => '2026-07-31']);
    $date = Carbon::parse('2026-07-15');

    [$result, $crossed] = PeriodLockOverride::run($this->company, fn () => [
        $this->company->isLockedFor($date),
        $other->isLockedFor($date),
    ]);

    expect($result)->toBe([false, true])
        ->and($crossed)->toBe(['2026-07-15'])
        ->and($this->company->isLockedFor($date))->toBeTrue()
        ->and(PeriodLockOverride::isActive())->toBeFalse();
});

it('leaves the poster refusing a closed-period date outside an override', function () {
    $entry = JournalEntry::create(['entry_no' => 'JE-X', 'entry_date' => '2026-07-31']);
    $entry->lines()->createMany([
        ['account_id' => $this->prepaid->id, 'debit_cents' => 100, 'credit_cents' => 0, 'line_order' => 0],
        ['account_id' => $this->expense->id, 'debit_cents' => 0, 'credit_cents' => 100, 'line_order' => 1],
    ]);

    expect(fn () => app(JournalPoster::class)->post($entry))->toThrow(PeriodLockedException::class);
});

it('offers the override on the document forms too, e.g. a transfer', function () {
    $this->company->update(['lock_mode' => PeriodLockMode::Warn]);

    $chequing = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $savings = app(SaveAccount::class)->handle([
        'code' => '1011', 'name' => 'Savings', 'subtype' => AccountSubtype::Bank->value,
    ]);

    Livewire::actingAs($this->accountant)
        ->test('pages::transfers.form', ['company' => $this->company->fresh()])
        ->set('from_account_id', $chequing->id)
        ->set('to_account_id', $savings->id)
        ->set('transfer_date', '2026-07-31')
        ->set('from_amount', '500.00')
        ->set('to_amount', '500.00')
        ->call('postTransfer')
        ->assertSet('periodLockRetryAction', 'postTransfer')
        ->call('confirmPeriodLockOverride')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(lockOverrideLogs($this->company)->sole()->payload['form'])->toContain('transfers');
});

it('renders the override dialog on every page that can offer it', function () {
    $pages = collect(File::allFiles(resource_path('views/pages')))
        ->filter(fn ($file) => str_contains($file->getContents(), 'use HandlesPeriodLockOverride;'));

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        expect($page->getContents())->toContain('<x-period-lock-override :company="$company" :message="$periodLockMessage" />');
    }
});
