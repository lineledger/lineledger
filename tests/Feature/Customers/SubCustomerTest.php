<?php

use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates a sub-customer nested under a parent', function () {
    $parent = Contact::factory()->customer()->create(['display_name' => 'Acme']);

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'Roof Job')
        ->set('f_parent_id', $parent->id)
        ->call('save')
        ->assertHasNoErrors();

    $child = Contact::query()->where('display_name', 'Roof Job')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id)
        ->and($child->qualifiedName())->toBe('Acme : Roof Job')
        ->and($parent->children()->count())->toBe(1);
});

it('prevents a customer from being its own parent', function () {
    $c = Contact::factory()->customer()->create();

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openEdit', $c->id)
        ->set('f_parent_id', $c->id)
        ->call('save')
        ->assertHasErrors('f_parent_id');
});

it('labels sub-customers with their parent in AR aging', function () {
    $parent = Contact::factory()->customer()->create(['display_name' => 'Acme']);
    $child = Contact::factory()->customer()->create(['display_name' => 'Roof Job', 'parent_id' => $parent->id]);

    $invoice = app(SaveInvoice::class)->handle([
        'contact_id' => $child->id,
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'lines' => [['account_id' => $this->income->id, 'quantity' => '1', 'unit_price_cents' => 50000]],
    ]);
    app(InvoicePoster::class)->post($invoice);

    Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->assertSee('Acme : Roof Job');
});
