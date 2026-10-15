<?php

use App\Enums\AccountSubtype;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Services\Posting\ReceiptPoster;

beforeEach(function () {
    $this->company = Company::factory()->create();
    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'Test');
    $this->plain = $plain;

    app()->instance('current_company', $this->company);
    $this->customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    app()->forgetInstance('current_company');
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

function authHeader(): array
{
    return ['Authorization' => 'Bearer '.test()->plain];
}

function invoicePayload(array $overrides = []): array
{
    return array_merge([
        'contact_id' => test()->customer->id,
        'invoice_date' => '2026-05-20',
        'lines' => [[
            'description' => 'Consulting',
            'quantity' => '2',
            'unit_price_cents' => 5000,
            'account_id' => test()->income->id,
        ]],
    ], $overrides);
}

it('lists invoices with pagination meta', function () {
    $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->assertStatus(201);

    $this->getJson('/api/v1/invoices', authHeader())
        ->assertStatus(200)
        ->assertJsonStructure(['data', 'links', 'meta'])
        ->assertJsonPath('meta.total', 1);
});

it('shows a single invoice', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->getJson("/api/v1/invoices/{$id}", authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.id', $id)
        ->assertJsonPath('data.status', 'posted');
});

it('creates a draft when post is false', function () {
    $response = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader());

    $response->assertStatus(201)->assertJsonPath('data.status', 'draft');
    expect($response->json('data.journal_entry_id'))->toBeNull();
});

it('posts a draft via the post action', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader())->json('data.id');

    $this->postJson("/api/v1/invoices/{$id}/post", [], authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'posted');

    expect(Invoice::withoutGlobalScopes()->find($id)->journal_entry_id)->not->toBeNull();
});

it('edits a draft via update', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader())->json('data.id');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload([
        'memo' => 'Updated memo',
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 9900, 'account_id' => $this->income->id,
        ]],
    ]), authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.memo', 'Updated memo')
        ->assertJsonPath('data.total_cents', 9900)
        ->assertJsonPath('data.status', 'draft');
});

it('reposts a posted invoice in place via update', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload([
        'lines' => [[
            'quantity' => '1', 'unit_price_cents' => 12300, 'account_id' => $this->income->id,
        ]],
    ]), authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.total_cents', 12300)
        ->assertJsonPath('data.status', 'posted');
});

it('voids a posted invoice and writes a reversing entry', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->deleteJson("/api/v1/invoices/{$id}", [], authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'void');

    expect(Invoice::withoutGlobalScopes()->find($id)->status)->toBe(InvoiceStatus::Void);
});

it('deletes a draft invoice', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(['post' => false]), authHeader())->json('data.id');

    $this->deleteJson("/api/v1/invoices/{$id}", [], authHeader())->assertStatus(204);

    // Soft-deleted: no longer retrievable through the API.
    $this->getJson("/api/v1/invoices/{$id}", authHeader())->assertStatus(404);
});

it('returns 404 for another company\'s invoice', function () {
    $other = Company::factory()->create();
    ['plaintext' => $otherPlain] = CompanyApiKey::mint($other, 'Other');

    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->getJson("/api/v1/invoices/{$id}", ['Authorization' => "Bearer {$otherPlain}"])
        ->assertStatus(404);
});

it('forbids writes with a read-only key', function () {
    ['plaintext' => $readPlain] = CompanyApiKey::mint($this->company, 'Read', null, ['sales:read']);

    // read works
    $this->getJson('/api/v1/invoices', ['Authorization' => "Bearer {$readPlain}"])->assertStatus(200);

    // write is forbidden
    $this->postJson('/api/v1/invoices', invoicePayload(), ['Authorization' => "Bearer {$readPlain}"])
        ->assertStatus(403);
});

it('allows writes with a sales:write key', function () {
    ['plaintext' => $writePlain] = CompanyApiKey::mint($this->company, 'Write', null, ['sales:write']);

    $this->postJson('/api/v1/invoices', invoicePayload(), ['Authorization' => "Bearer {$writePlain}"])
        ->assertStatus(201);
});

it('reposts a negative line added via update as a debit to its account', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    app()->instance('current_company', $this->company);
    $discounts = Account::query()
        ->where('subtype', AccountSubtype::Income->value)
        ->where('id', '!=', $this->income->id)
        ->firstOrFail();
    app()->forgetInstance('current_company');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload([
        'lines' => [
            ['quantity' => '1', 'unit_price_cents' => 101151, 'account_id' => $this->income->id],
            ['quantity' => '1', 'unit_price_cents' => -10115, 'account_id' => $discounts->id],
        ],
    ]), authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.total_cents', 91036)
        ->assertJsonPath('data.status', 'posted');

    $invoice = Invoice::query()->withoutGlobalScopes()->findOrFail($id);
    $lines = JournalLine::query()->withoutGlobalScopes()->where('journal_entry_id', $invoice->journal_entry_id)->get();

    $discount = $lines->firstWhere('account_id', $discounts->id);
    expect((int) $discount->debit_cents)->toBe(10115);
    expect((int) $discount->credit_cents)->toBe(0);
    expect((int) $lines->firstWhere('account_id', $this->income->id)->credit_cents)->toBe(101151);
    expect((int) $lines->sum('debit_cents'))->toBe((int) $lines->sum('credit_cents'));
});

it('rejects an update whose lines net to a credit', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload([
        'lines' => [
            ['quantity' => '1', 'unit_price_cents' => 1000, 'account_id' => $this->income->id],
            ['quantity' => '1', 'unit_price_cents' => -1500, 'account_id' => $this->income->id],
        ],
    ]), authHeader())
        ->assertStatus(422)
        ->assertJsonValidationErrors(['lines']);

    // The posted invoice is untouched.
    expect((int) Invoice::query()->withoutGlobalScopes()->findOrFail($id)->total_cents)->toBe(10000);
});

it('changes the sales rep on update and reposts', function () {
    app()->instance('current_company', $this->company);
    $first = Contact::create(['display_name' => 'Annika Anderson', 'is_employee' => true]);
    $second = Contact::create(['display_name' => 'Kristi Olson', 'is_employee' => true]);
    app()->forgetInstance('current_company');

    $id = $this->postJson('/api/v1/invoices', invoicePayload(['sales_rep_id' => $first->id]), authHeader())
        ->assertStatus(201)
        ->json('data.id');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload(['sales_rep_id' => $second->id]), authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.sales_rep_id', $second->id);
});

it('clears the sales rep when update omits it', function () {
    app()->instance('current_company', $this->company);
    $rep = Contact::create(['display_name' => 'Annika Anderson', 'is_employee' => true]);
    app()->forgetInstance('current_company');

    $id = $this->postJson('/api/v1/invoices', invoicePayload(['sales_rep_id' => $rep->id]), authHeader())->json('data.id');

    // PATCH replaces the whole document (see docs §7), so an omitted rep is a
    // cleared rep — not a kept one.
    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload(), authHeader())
        ->assertStatus(200)
        ->assertJsonPath('data.sales_rep_id', null);
});

it('rejects an update that drops a paid invoice below what its receipts applied', function () {
    $id = $this->postJson('/api/v1/invoices', invoicePayload(), authHeader())->json('data.id');

    app()->instance('current_company', $this->company);
    $receipt = CustomerReceipt::create([
        'contact_id' => $this->customer->id,
        'receipt_no' => 'REC-API-1',
        'receipt_date' => '2026-05-20',
        'deposit_to_account_id' => Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->value('id'),
        'amount_cents' => 10000,
    ]);
    $receipt->applications()->create(['invoice_id' => $id, 'amount_cents' => 10000]);
    app(ReceiptPoster::class)->post($receipt->fresh('applications'));
    app()->forgetInstance('current_company');

    $this->patchJson("/api/v1/invoices/{$id}", invoicePayload([
        'lines' => [['quantity' => '1', 'unit_price_cents' => 6000, 'account_id' => $this->income->id]],
    ]), authHeader())
        ->assertStatus(422)
        ->assertJsonPath('message', fn (string $message) => str_contains($message, 'Receipts have already applied 100.00 to this invoice'));

    // Rolled back whole: header, lines and payment are as they were.
    $invoice = Invoice::query()->withoutGlobalScopes()->with('lines')->findOrFail($id);
    expect((int) $invoice->total_cents)->toBe(10000);
    expect((int) $invoice->amount_paid_cents)->toBe(10000);
    expect($invoice->lines)->toHaveCount(1);
    expect((int) $invoice->lines->first()->unit_price_cents)->toBe(5000);
});
