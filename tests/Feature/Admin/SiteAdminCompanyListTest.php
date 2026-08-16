<?php

use App\Models\Company;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->siteAdmin()->create();
    $this->actingAs($this->admin);
});

it('lists every company including soft-deleted ones', function () {
    $active = Company::factory()->create(['name' => 'Active Widgets Inc']);
    $deleted = Company::factory()->create(['name' => 'Gone Corp']);
    $deleted->delete();

    Livewire::test('pages::admin.companies')
        ->assertSee('Active Widgets Inc')
        ->assertSee('Gone Corp')
        ->assertSee('Deleted');
});

it('filters companies by search', function () {
    Company::factory()->create(['name' => 'Findable Co']);
    Company::factory()->create(['name' => 'Hidden Co']);

    Livewire::test('pages::admin.companies')
        ->set('search', 'Findable')
        ->assertSee('Findable Co')
        ->assertDontSee('Hidden Co');
});

it('blocks a non-admin from the component entirely', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.companies')->assertStatus(404);
});
