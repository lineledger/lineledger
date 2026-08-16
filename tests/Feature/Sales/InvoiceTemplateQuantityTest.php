<?php

use App\Actions\Sales\SaveInvoiceTemplate;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $this->template = app(SaveInvoiceTemplate::class)->handle([
        'name' => 'Zero-qty package',
        'is_active' => true,
        'lines' => [[
            'item_id' => null,
            'account_id' => $this->income->id,
            'description' => 'Placeholder line',
            'quantity' => '0',
            'unit_price_cents' => 10000,
            'tax_code_id' => null,
        ]],
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('persists a template line quantity of zero', function () {
    expect((float) $this->template->lines->first()->quantity)->toBe(0.0);
});

it('shows zero (not one) when reopening the template form', function () {
    Livewire::test('pages::invoice-templates.form', [
        'company' => $this->company,
        'invoiceTemplate' => $this->template,
    ])->assertSet('lines.0.quantity', '0');
});

it('keeps zero when the template is saved again from the form', function () {
    Livewire::test('pages::invoice-templates.form', [
        'company' => $this->company,
        'invoiceTemplate' => $this->template,
    ])
        ->assertSet('lines.0.quantity', '0')
        ->call('save')
        ->assertHasNoErrors();

    expect((float) $this->template->fresh()->lines->first()->quantity)->toBe(0.0);
});

it('carries a zero quantity into an invoice created from the template', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('template_id', $this->template->id)
        ->assertSet('lines.0.quantity', '0');
});
