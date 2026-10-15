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

it('keeps the calculator out of global search re-renders', function () {
    $html = Livewire::test('global-search')->html();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);

    // Both the trigger and the modal body sit inside one wire:ignore island,
    // so a search update (e.g. clear() on close while a result navigates) can
    // never morph the calculator mid-teardown.
    foreach (['calculator-trigger', 'calculator-body'] as $test) {
        $node = $xpath->query('//*[@data-test="'.$test.'"]')->item(0);
        expect($node)->not->toBeNull();

        $island = $xpath->query('ancestor::*[@data-test="calculator-island"]', $node)->item(0);
        expect($island)->not->toBeNull()
            ->and($island->hasAttribute('wire:ignore'))->toBeTrue();
    }
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
