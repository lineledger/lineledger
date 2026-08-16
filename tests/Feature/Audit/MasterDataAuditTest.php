<?php

use App\Actions\Accounting\SaveAccount;
use App\Enums\AccountSubtype;
use App\Enums\AuditAction;
use App\Models\Account;
use App\Models\AccountingAuditLog;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Audit\AccountingAuditRecorder;
use Illuminate\Database\Eloquent\Collection;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function masterDataAuditRows(?AuditAction $action = null): Collection
{
    return AccountingAuditLog::query()
        ->withoutGlobalScopes()
        ->where('company_id', test()->company->id)
        ->when($action, fn ($q) => $q->where('action', $action))
        ->orderBy('sequence')
        ->get();
}

function makeAuditedAccount(string $code = '9990', string $name = 'Consulting Expense'): Account
{
    return app(SaveAccount::class)->handle([
        'code' => $code,
        'name' => $name,
        'subtype' => AccountSubtype::Expense->value,
    ]);
}

it('records an account.created row with the payload attributes when an account is saved', function () {
    $account = makeAuditedAccount();

    $rows = masterDataAuditRows(AuditAction::AccountCreated)
        ->where('auditable_id', $account->id)
        ->values();

    expect($rows)->toHaveCount(1);

    $payload = $rows->first()->payload;
    expect($payload['attributes']['name'])->toBe('Consulting Expense');
    expect($payload['attributes']['code'])->toBe('9990');
    expect($payload['attributes']['company_id'])->toBe($this->company->id);
});

it('records account.created rows for the seeded default chart on company creation', function () {
    // Company creation seeds a full default chart through Eloquent, so every
    // seeded account documents itself — same behavior as source documents.
    $seeded = Account::query()->withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->count();

    expect($seeded)->toBeGreaterThan(0);
    expect(masterDataAuditRows(AuditAction::AccountCreated))->toHaveCount($seeded);
});

it('records an account.updated row with a name from/to diff when an account is renamed', function () {
    $account = makeAuditedAccount(name: 'Old Name');

    app(SaveAccount::class)->handle([
        'code' => '9990',
        'name' => 'New Name',
        'subtype' => AccountSubtype::Expense->value,
    ], $account);

    $rows = masterDataAuditRows(AuditAction::AccountUpdated)
        ->where('auditable_id', $account->id)
        ->values();

    expect($rows)->toHaveCount(1);
    // Key-order-insensitive: MySQL's JSON column normalizes object key order.
    $change = $rows->first()->payload['changes']['name'];
    expect($change['from'])->toBe('Old Name');
    expect($change['to'])->toBe('New Name');
});

it('does not record an audit row when recomputeBalance runs', function () {
    $account = makeAuditedAccount();
    $before = masterDataAuditRows()->count();

    $account->recomputeBalance();

    expect(masterDataAuditRows()->count())->toBe($before);
});

it('does not record an audit row for a balance_cents-only update (excluded attribute, empty diff)', function () {
    $account = makeAuditedAccount();
    $before = masterDataAuditRows()->count();

    $account->update(['balance_cents' => 123]);

    expect(masterDataAuditRows()->count())->toBe($before);
});

it('records contact.created and contact.updated rows through the model chokepoint', function () {
    $contact = Contact::create([
        'display_name' => 'Acme Corp',
        'is_customer' => true,
    ]);

    $created = masterDataAuditRows(AuditAction::ContactCreated)
        ->where('auditable_id', $contact->id)
        ->values();

    expect($created)->toHaveCount(1);
    expect($created->first()->payload['attributes']['display_name'])->toBe('Acme Corp');

    $contact->update(['display_name' => 'Acme Corporation']);

    $updated = masterDataAuditRows(AuditAction::ContactUpdated)
        ->where('auditable_id', $contact->id)
        ->values();

    expect($updated)->toHaveCount(1);
    // Key-order-insensitive: MySQL's JSON column normalizes object key order.
    $change = $updated->first()->payload['changes']['display_name'];
    expect($change['from'])->toBe('Acme Corp');
    expect($change['to'])->toBe('Acme Corporation');
});

it('does not record an audit row for an ar_balance_cents-only contact update', function () {
    $contact = Contact::create([
        'display_name' => 'Balance Churn Co',
        'is_customer' => true,
    ]);

    $before = masterDataAuditRows()->count();

    // ar/ap balances are cached, derived state — recomputed not edited.
    // (Not fillable, so forceFill + save: events still fire, unlike saveQuietly.)
    $contact->forceFill(['ar_balance_cents' => 500])->save();

    expect(masterDataAuditRows()->count())->toBe($before);
});

it('keeps the per-company hash chain valid across master-data events', function () {
    $account = makeAuditedAccount(name: 'Chain Account');
    app(SaveAccount::class)->handle([
        'code' => '9990',
        'name' => 'Chain Account v2',
        'subtype' => AccountSubtype::Expense->value,
    ], $account);

    $contact = Contact::create(['display_name' => 'Chain Contact', 'is_customer' => true]);
    $contact->update(['display_name' => 'Chain Contact v2']);

    $rows = masterDataAuditRows();

    // Sequence is monotonic, starting at 1 (chart seeding wrote the first rows).
    expect($rows->first()->sequence)->toBe(1);
    expect($rows->pluck('sequence')->all())->toBe(range(1, $rows->count()));

    // Each row's previous_hash matches the prior row's row_hash.
    $prev = AccountingAuditRecorder::GENESIS_HASH;
    foreach ($rows as $row) {
        expect($row->previous_hash)->toBe($prev);
        $prev = $row->row_hash;
    }
});

it('records a null actor_user_id when a portal contact updates itself', function () {
    // Portal sessions authenticate a Contact on the "customer" guard; its id
    // must never land in actor_user_id (FK to users). Regression: the guard
    // becomes the default via shouldUse(), so a bare Auth::id() returned the
    // contact id and violated the constraint.
    $contact = Contact::create([
        'display_name' => 'Portal Employee',
        'is_employee' => true,
        'is_active' => true,
    ]);

    $this->actingAs($contact, 'customer');

    $contact->update(['billing_city' => 'Calgary']);

    $row = masterDataAuditRows(AuditAction::ContactUpdated)
        ->where('auditable_id', $contact->id)
        ->last();

    expect($row)->not->toBeNull();
    expect($row->actor_user_id)->toBeNull();
    expect($row->payload['changes']['billing_city']['to'])->toBe('Calgary');
});
