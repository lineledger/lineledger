<?php

use App\Enums\CalculatorMode;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->user->forceFill(['current_company_id' => $this->company->id])->save();

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('renders the calculator trigger and modal beside global search', function () {
    $html = Livewire::test('global-search')->html();

    // The trigger button and Alpine-wired modal body are present.
    expect($html)
        ->toContain('data-test="calculator-trigger"')
        ->toContain('data-test="calculator-body"')
        ->toContain('data-test="calculator-tape"')
        ->toContain('tapeCalculator(')
        // Copy + "place into the previously-focused field" controls are wired.
        ->toContain('copy()')
        ->toContain('place()');

    // All Flux component tags inside the calculator must have compiled away.
    expect($html)->not->toContain('<flux:');
});

it('renders the standard keypad without a Total key by default', function () {
    expect($this->user->calculator_mode)->toBe(CalculatorMode::Standard);

    $html = Livewire::test('global-search')->html();

    expect($html)
        ->toContain("mode: 'standard'")
        ->not->toContain(__('Total'));
});

it('renders the adding-machine keypad with a Total key when selected', function () {
    $this->user->update(['calculator_mode' => CalculatorMode::AddingMachine]);

    $html = Livewire::test('global-search')->html();

    expect($html)
        ->toContain("mode: 'adding_machine'")
        ->toContain(__('Total'));
});
