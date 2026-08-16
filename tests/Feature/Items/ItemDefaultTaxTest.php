<?php

use App\Actions\MasterData\SaveItem;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\ItemType;
use App\Models\Account;
use App\Models\Company;
use App\Models\Item;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    // BC seeds both GST and PST-BC, the canonical two-tax (GST + PST) case.
    $this->company = Company::factory()->forCountry(Country::Canada, 'BC')->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->pst = TaxCode::where('code', 'PST-BC')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('saves an Other charge item carrying two default taxes', function () {
    $item = app(SaveItem::class)->handle([
        'name' => 'Embalming',
        'type' => 'other_charge',
        'income_account_id' => $this->income->id,
        'default_tax_code_id' => $this->gst->id,
        'default_secondary_tax_code_id' => $this->pst->id,
    ]);

    expect($item->type)->toBe(ItemType::OtherCharge)
        ->and($item->track_inventory)->toBeFalse()
        ->and($item->default_tax_code_id)->toBe($this->gst->id)
        ->and($item->default_secondary_tax_code_id)->toBe($this->pst->id);
});

it('saves the Other charge type and both default taxes from the items form', function () {
    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Casket')
        ->set('f_type', ItemType::OtherCharge->value)
        ->set('f_income_account_id', $this->income->id)
        ->set('f_default_tax_code_ids', [$this->gst->id, $this->pst->id])
        ->call('save')
        ->assertHasNoErrors();

    $item = Item::where('name', 'Casket')->firstOrFail();

    expect($item->type)->toBe(ItemType::OtherCharge)
        ->and($item->default_tax_code_id)->toBe($this->gst->id)
        ->and($item->default_secondary_tax_code_id)->toBe($this->pst->id);
});

it('reloads both default taxes into the picker when editing an item', function () {
    $item = app(SaveItem::class)->handle([
        'name' => 'Casket',
        'type' => 'service',
        'income_account_id' => $this->income->id,
        'default_tax_code_id' => $this->gst->id,
        'default_secondary_tax_code_id' => $this->pst->id,
    ]);

    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->call('openEdit', $item->id)
        ->assertSet('f_default_tax_code_ids', [$this->gst->id, $this->pst->id]);
});

it('rejects more than two default taxes', function () {
    Livewire::test('pages::settings.lists.items', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Too many')
        ->set('f_income_account_id', $this->income->id)
        ->set('f_default_tax_code_ids', [$this->gst->id, $this->pst->id, $this->gst->id])
        ->call('save')
        ->assertHasErrors('f_default_tax_code_ids');

    expect(Item::where('name', 'Too many')->exists())->toBeFalse();
});

it('prefills both default taxes onto an invoice line when the item is selected', function () {
    $item = app(SaveItem::class)->handle([
        'name' => 'Casket',
        'type' => 'service',
        'income_account_id' => $this->income->id,
        'default_price_cents' => 50000,
        'default_tax_code_id' => $this->gst->id,
        'default_secondary_tax_code_id' => $this->pst->id,
    ]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.item_id', $item->id)
        ->assertSet('lines.0.tax_code_id', $this->gst->id)
        ->assertSet('lines.0.secondary_tax_code_id', $this->pst->id)
        ->assertSet('lines.0.tax_code_ids', [$this->gst->id, $this->pst->id]);
});
