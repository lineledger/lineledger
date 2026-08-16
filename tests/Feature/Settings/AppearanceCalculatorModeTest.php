<?php

use App\Enums\CalculatorMode;
use App\Models\User;
use Livewire\Livewire;

test('appearance settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('appearance.edit'))->assertOk();
});

test('new users default to the standard calculator mode', function () {
    expect(User::factory()->create()->calculator_mode)->toBe(CalculatorMode::Standard);
});

test('calculator mode preference can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('calculatorMode', CalculatorMode::AddingMachine->value)
        ->call('updateCalculatorMode')
        ->assertHasNoErrors();

    expect($user->refresh()->calculator_mode)->toBe(CalculatorMode::AddingMachine);
});

test('calculator mode rejects an invalid value', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::settings.appearance')
        ->set('calculatorMode', 'not-a-mode')
        ->call('updateCalculatorMode')
        ->assertHasErrors(['calculatorMode']);

    expect($user->refresh()->calculator_mode)->toBe(CalculatorMode::Standard);
});
