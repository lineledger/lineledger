<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the amount-input calculator on the cheque line amount field', function () {
    $html = $this->get(route('cheques.create', ['company' => $this->company->slug]))
        ->assertOk()
        ->getContent();

    // The <flux:input> inside <x-amount-input> must actually compile to an <input>.
    // A leftover literal "<flux:input" means Blade's component compiler choked and
    // the field would silently vanish in the browser.
    expect($html)->not->toContain('<flux:input');

    // The compiled amount field is present and bound to the line amount.
    expect($html)
        ->toContain('data-test="line-amount"')
        ->toContain('wire:model.live="lines.0.amount"');

    // The Alpine calculator is wired and the tape dropdown is rendered.
    expect($html)
        ->toContain('x-data="amountCalculator"')
        ->toContain('data-test="calc-tape"')
        ->toContain('data-test="calc-result"');
});
