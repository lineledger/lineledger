<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('defaults to AI narration off', function () {
    expect($this->company->insightsAiNarrationEnabled())->toBeFalse();
});

it('hides the switch when the operator has not enabled insights AI', function () {
    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->assertDontSee('Write my daily insight with AI');
});

it('lets an owner opt in and back out', function () {
    config(['insights.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->assertSee('Write my daily insight with AI')
        ->set('insightsAiNarration', true);

    expect($this->company->fresh()->insightsAiNarrationEnabled())->toBeTrue();

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('insightsAiNarration', false);

    expect($this->company->fresh()->insightsAiNarrationEnabled())->toBeFalse();
});

it('blocks a custom-role member from flipping the switch', function () {
    config(['insights.ai.enabled' => true, 'services.anthropic.key' => 'test-key']);

    $member = User::factory()->create();
    $this->company->members()->attach($member, ['role' => CompanyRole::Custom->value]);
    $this->actingAs($member);

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('insightsAiNarration', true)
        ->assertForbidden();

    expect($this->company->fresh()->insightsAiNarrationEnabled())->toBeFalse();
});
