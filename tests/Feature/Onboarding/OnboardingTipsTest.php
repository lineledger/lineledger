<?php

use App\Actions\Companies\CreateCompany;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Company;
use App\Models\User;
use App\Support\Onboarding\OnboardingTips;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

function enableOnboarding(Company $company): void
{
    $company->setOnboardingState(['enabled' => true, 'completed' => [], 'dismissed' => false]);
}

it('seeds onboarding as enabled when a company is created', function () {
    $company = app(CreateCompany::class)->handle(
        user: User::factory()->create(),
        name: 'Fresh Co',
        country: Country::Canada,
    );

    expect($company->onboardingEnabled())->toBeTrue();
    expect($company->onboardingCompletedTips())->toBe([]);
    expect($company->onboardingDismissed())->toBeFalse();
});

it('shows the tips box for a company with onboarding enabled', function () {
    enableOnboarding($this->company);

    Livewire::test('onboarding-tips')
        ->assertSet('visible', true)
        ->assertSee('Customize your sidebar');
});

it('does not show the box for a company without onboarding enabled', function () {
    Livewire::test('onboarding-tips')
        ->assertSet('visible', false)
        ->assertDontSee('Customize your sidebar');
});

it('persists a completed tip and hides the box once every tip is checked', function () {
    enableOnboarding($this->company);
    $keys = OnboardingTips::keys();

    $component = Livewire::test('onboarding-tips')->assertSet('visible', true);
    foreach ($keys as $key) {
        $component->call('toggleComplete', $key);
    }
    $component->assertSet('visible', false);

    expect($this->company->fresh()->onboardingCompletedTips())->toContain($keys[0]);
});

it('un-checking a tip brings the box back', function () {
    enableOnboarding($this->company);
    $keys = OnboardingTips::keys();

    $component = Livewire::test('onboarding-tips');
    foreach ($keys as $key) {
        $component->call('toggleComplete', $key);
    }
    $component->assertSet('visible', false)
        ->call('toggleComplete', $keys[0])
        ->assertSet('visible', true);
});

it('dismisses the box for good and persists the choice', function () {
    enableOnboarding($this->company);

    Livewire::test('onboarding-tips')
        ->assertSet('visible', true)
        ->call('dismiss')
        ->assertSet('visible', false);

    expect($this->company->fresh()->onboardingDismissed())->toBeTrue();
});

it('restarting onboarding from company settings re-shows the full tour', function () {
    // Complete and dismiss first.
    $this->company->setOnboardingState([
        'enabled' => true,
        'completed' => OnboardingTips::keys(),
        'dismissed' => true,
    ]);

    expect($this->company->fresh()->onboardingDismissed())->toBeTrue();

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->call('restartOnboarding');

    $fresh = $this->company->fresh();
    expect($fresh->onboardingEnabled())->toBeTrue();
    expect($fresh->onboardingCompletedTips())->toBe([]);
    expect($fresh->onboardingDismissed())->toBeFalse();
});
