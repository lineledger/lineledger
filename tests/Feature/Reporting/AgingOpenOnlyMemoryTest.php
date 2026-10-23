<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\MemorizedReport;
use App\Models\User;
use Livewire\Livewire;

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

dataset('aging reports', [
    'AR aging' => ['pages::reports.ar-aging', 'reports.ar-aging'],
    'AP aging' => ['pages::reports.ap-aging', 'reports.ap-aging'],
]);

it('defaults to owing-only on a first visit', function (string $page) {
    Livewire::test($page, ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true);
})->with('aging reports');

it('reopens with the last open_only choice', function (string $page) {
    Livewire::test($page, ['company' => $this->company])
        ->set('excludeUnappliedCredits', false);

    session()->flush();

    Livewire::test($page, ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', false);
})->with('aging reports');

it('lets an explicit ?open_only= in the URL win over the remembered choice', function (string $page) {
    Livewire::test($page, ['company' => $this->company])
        ->set('excludeUnappliedCredits', false);

    Livewire::withQueryParams(['open_only' => '1'])
        ->test($page, ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true);
})->with('aging reports');

it('lets a memorized report win over the remembered choice', function (string $page, string $reportKey) {
    Livewire::test($page, ['company' => $this->company])
        ->set('excludeUnappliedCredits', false);

    $memorized = MemorizedReport::create([
        'company_id' => $this->company->id,
        'user_id' => $this->user->id,
        'report_key' => $reportKey,
        'name' => 'Owing only',
        'settings' => ['excludeUnappliedCredits' => true],
    ]);

    Livewire::withQueryParams(['memorized' => $memorized->id])
        ->test($page, ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true);
})->with('aging reports');

it('keeps the AR and AP choices independent', function () {
    Livewire::test('pages::reports.ar-aging', ['company' => $this->company])
        ->set('excludeUnappliedCredits', false);

    Livewire::test('pages::reports.ap-aging', ['company' => $this->company])
        ->assertSet('excludeUnappliedCredits', true);
});
