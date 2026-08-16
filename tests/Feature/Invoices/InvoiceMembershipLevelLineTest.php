<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\MembershipLevel;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create(['features_membership' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $this->level = MembershipLevel::factory()->create([
        'name' => 'Gold',
        'default_dues_cents' => 12000,
        'revenue_account_id' => $this->income->id,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('lists membership levels in the invoice item options for a membership company', function () {
    $options = Livewire::test('pages::invoices.form', ['company' => $this->company])->instance()->itemOptions();

    $level = collect($options)->firstWhere('id', 'level:'.$this->level->id);

    expect($level)->not->toBeNull();
    expect($level['name'])->toBe('Gold');
    expect($level['category'])->toBe('Membership level');
});

it('omits membership levels when the company does not track membership', function () {
    $this->company->update(['features_membership' => false]);

    $options = Livewire::test('pages::invoices.form', ['company' => $this->company])->instance()->itemOptions();

    expect(collect($options)->firstWhere('id', 'level:'.$this->level->id))->toBeNull();
});

it('prefills a line from a picked membership level', function () {
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.item_id', 'level:'.$this->level->id)
        ->assertSet('lines.0.account_id', $this->income->id)
        ->assertSet('lines.0.description', 'Membership dues: Gold')
        ->assertSet('lines.0.unit_price', '120.00');
});

it('posts a draft from a membership level pick without a catalog item id', function () {
    $customer = Contact::create(['display_name' => 'Member Co', 'is_customer' => true]);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->call('selectContact', $customer->id)
        ->set('lines.0.item_id', 'level:'.$this->level->id)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $invoice = Invoice::query()->where('contact_id', $customer->id)->firstOrFail();
    $line = $invoice->lines->first();

    expect($line->item_id)->toBeNull();
    expect($line->account_id)->toBe($this->income->id);
    expect($line->unit_price_cents)->toBe(12000);
    expect($line->description)->toBe('Membership dues: Gold');
});
