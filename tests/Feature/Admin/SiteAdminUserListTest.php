<?php

use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->admin = User::factory()->siteAdmin()->create(['name' => 'Ada Admin']);
    $this->actingAs($this->admin);
});

it('lists users and filters by search', function () {
    User::factory()->create(['name' => 'Alice Anderson', 'email' => 'alice@example.com']);
    User::factory()->create(['name' => 'Bob Brown', 'email' => 'bob@example.com']);

    Livewire::test('pages::admin.users')
        ->assertSee('Alice Anderson')
        ->assertSee('Bob Brown')
        ->set('search', 'alice')
        ->assertSee('Alice Anderson')
        ->assertDontSee('Bob Brown');
});

it('grants and revokes the site admin role', function () {
    $target = User::factory()->create(['name' => 'Grant Target']);

    Livewire::test('pages::admin.users')
        ->call('toggleSiteAdmin', $target->id);

    expect($target->fresh()->site_admin)->toBeTrue();

    Livewire::test('pages::admin.users')
        ->call('toggleSiteAdmin', $target->id);

    expect($target->fresh()->site_admin)->toBeFalse();
});

it('refuses to remove the last remaining site admin', function () {
    // Only $this->admin is a site admin, so revoking them must be refused.
    Livewire::test('pages::admin.users')
        ->call('toggleSiteAdmin', $this->admin->id);

    expect($this->admin->fresh()->site_admin)->toBeTrue();
});

it('blocks a non-admin from the component entirely', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages::admin.users')->assertStatus(404);
});
