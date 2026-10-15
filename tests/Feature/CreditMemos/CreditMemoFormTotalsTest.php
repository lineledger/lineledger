<?php

use App\Actions\Sales\SaveCreditMemo;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->forCountry(Country::Canada, 'BC')->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->create(['is_customer' => true]);
    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
    $this->pst = TaxCode::where('code', 'PST-BC')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('totals both taxes on the edit form, matching the saved credit memo', function () {
    // GST 5% + PST 7% on 150.00, and a -25.00 line with the codes the other way round.
    $memo = app(SaveCreditMemo::class)->handle([
        'contact_id' => $this->customer->id,
        'credit_memo_date' => now()->toDateString(),
        'lines' => [
            ['account_id' => $this->income->id, 'quantity' => 1, 'unit_price_cents' => 15000,
                'tax_code_id' => $this->gst->id, 'secondary_tax_code_id' => $this->pst->id],
            ['account_id' => $this->income->id, 'quantity' => 1, 'unit_price_cents' => -2500,
                'tax_code_id' => $this->pst->id, 'secondary_tax_code_id' => $this->gst->id],
        ],
    ]);

    expect($memo->total_cents)->toBe(14000);

    $totals = Livewire::test('pages::credit-memos.form', ['company' => $this->company, 'credit_memo' => $memo])
        ->instance()->totals;

    expect($totals)->toBe(['subtotal' => 12500, 'tax' => 1500, 'total' => 14000]);
});

it('totals both taxes while entering a new credit memo', function () {
    $totals = Livewire::test('pages::credit-memos.form', ['company' => $this->company])
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.tax_code_ids', [$this->gst->id, $this->pst->id])
        ->instance()->totals;

    expect($totals)->toBe(['subtotal' => 10000, 'tax' => 1200, 'total' => 11200]);
});
