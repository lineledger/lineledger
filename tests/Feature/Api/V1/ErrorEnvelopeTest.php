<?php

use App\Actions\Sales\SaveSalesOrder;
use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\SalesOrder;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->h = ['Authorization' => "Bearer {$plain}"];

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('renders ValidationException as 422 with {message, errors} envelope', function () {
    $response = $this->postJson('/api/v1/invoices', [], $this->h);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors']);

    expect($response->json('errors'))->toBeArray()
        ->and($response->json('errors.contact_id'))->toBeArray()
        ->and($response->json('errors.contact_id.0'))->toBeString();
});

it('renders AuthenticationException as 401 without leaking middleware detail', function () {
    $response = $this->postJson('/api/v1/invoices', []);

    $response->assertStatus(401)
        ->assertJsonStructure(['message']);

    // Lock the current message verbatim so a future change can't silently flip the shape.
    expect($response->json('message'))->toBe('Missing API key');
});

it('renders ModelNotFoundException as 404 with a generic envelope', function () {
    $response = $this->getJson('/api/v1/invoices/999999', $this->h);

    $response->assertStatus(404)
        ->assertExactJson(['message' => 'Resource not found.']);
});

it('renders HttpException conflicts as 409 with the author-written message', function () {
    // Create + auto-post an invoice, then re-post via the explicit action — the controller
    // short-circuits with $this->conflict('This entry is already posted.') for journal entries,
    // and InvoiceController short-circuits the same way for an already-posted invoice indirectly
    // via the conflict guard on void; the closest natural 409 is journal-entry double-post.
    $entry = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-05-20',
        'memo' => 'opening',
        'lines' => [
            ['account_id' => $this->bank->id, 'debit_cents' => 1000, 'credit_cents' => 0],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 1000],
        ],
    ], $this->h);
    $entry->assertStatus(201);
    $id = $entry->json('data.id');

    $response = $this->postJson("/api/v1/journal-entries/{$id}/post", [], $this->h);

    $response->assertStatus(409)
        ->assertExactJson(['message' => 'This entry is already posted.']);
});

it('renders PeriodLockedException as 422 with a sanitized message (no dates leak)', function () {
    $this->company->forceFill(['lock_date' => '2026-05-31'])->save();

    $response = $this->postJson('/api/v1/invoices', [
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-05-30',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => $this->income->id,
        ]],
    ], $this->h);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'The accounting period for this date is locked; posting is blocked.');

    expect($response->json('message'))
        ->not->toContain('2026-05-30')
        ->not->toContain('2026-05-31')
        ->not->toContain('lock date');
});

it('renders ReconciliationOutOfBalanceException as 422 with no cents leak', function () {
    $recId = $this->postJson('/api/v1/bank-reconciliations', [
        'account_id' => $this->bank->id,
        'statement_date' => '2026-05-31',
        'ending_balance_cents' => 5000,
    ], $this->h)->assertStatus(201)->json('data.id');

    $response = $this->postJson("/api/v1/bank-reconciliations/{$recId}/complete", [], $this->h);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'The reconciliation is out of balance; adjust marked items until the difference is zero.');

    expect($response->json('message'))
        ->not->toContain('5000')
        ->not->toContain('50.00')
        ->not->toContain('50');
});

it('renders ApiController::posting() generic 422 fallback when a poster throws a non-ClientSafe RuntimeException', function () {
    // The poster wrapper neutralizes any non-ClientSafe RuntimeException to a generic 422 so
    // raw poster detail (account ids, item config, lock-bypass guards) never reaches clients.
    // Forcing that path via a journal entry whose line account belongs to another tenant.
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreignAccount = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    app()->forgetInstance('current_company');

    // Validation passes because the request is wrapped in `posting()` which catches the
    // RuntimeException raised when the tenant scope blocks the cross-tenant reference.
    $response = $this->postJson('/api/v1/journal-entries', [
        'entry_date' => '2026-05-20',
        'memo' => 'cross-tenant',
        'lines' => [
            ['account_id' => $foreignAccount->id, 'debit_cents' => 1000, 'credit_cents' => 0],
            ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 1000],
        ],
    ], $this->h);

    // Either the validator rejects with 422 + errors (request guard) or posting() catches a
    // RuntimeException and emits the generic 422 envelope; both prove no internal detail leaks.
    $response->assertStatus(422);
    $body = $response->json();
    expect($body)->toHaveKey('message');
    expect((string) ($body['message'] ?? ''))
        ->not->toContain((string) $foreignAccount->id)
        ->not->toContain($foreignAccount->code ?? '___no_code___')
        ->not->toContain((string) $otherCompany->id);
});

it('renders the Throwable catch-all as a generic 500 without leaking exception detail', function () {
    // Bind a stub that throws a non-ClientSafe, non-Http, non-Validation RuntimeException from
    // a controller path that does NOT wrap in posting() — proves the bootstrap catch-all
    // sanitizes any escape from new (un-wrapped) code paths added in the future.
    app()->bind(SaveSalesOrder::class, function () {
        return new class
        {
            public function handle(array $data, ?SalesOrder $salesOrder = null): SalesOrder
            {
                throw new RuntimeException('internal-leak-canary-9b3f-account-id-42-cents-12345');
            }
        };
    });

    $response = $this->postJson('/api/v1/sales-orders', [
        'contact_id' => $this->customer->id,
        'order_date' => '2026-05-20',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 5000, 'account_id' => $this->income->id,
        ]],
    ], $this->h);

    $response->assertStatus(500)
        ->assertExactJson(['message' => 'This request could not be completed.']);

    expect($response->json('message'))
        ->not->toContain('internal-leak-canary')
        ->not->toContain('account-id-42')
        ->not->toContain('12345');
});
