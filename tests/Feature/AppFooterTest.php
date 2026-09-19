<?php

use App\Models\User;

test('the dashboard surfaces the license and source links', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeText('AGPL-3.0');
    $response->assertSee('https://www.gnu.org/licenses/agpl-3.0.html', false);
    $response->assertSee('https://github.com/lineledger/lineledger', false);
});

test('the footer names the operating company', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Local Foundry Inc.');
});

test('the documentation pages surface the license and source links', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('docs.getting-started'));

    $response->assertOk();
    $response->assertSeeText('AGPL-3.0');
    $response->assertSee('https://www.gnu.org/licenses/agpl-3.0.html', false);
    $response->assertSee('https://github.com/lineledger/lineledger', false);
});

test('the login page surfaces the license and source links', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSeeText('AGPL-3.0');
    $response->assertSee('https://www.gnu.org/licenses/agpl-3.0.html', false);
    $response->assertSee('https://github.com/lineledger/lineledger', false);
});

test('the footer shows the running version and links to its release notes', function () {
    $user = User::factory()->create();

    $version = (string) config('version.app');

    $this
        ->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('v'.$version)
        ->assertSee('https://github.com/lineledger/lineledger/releases/tag/v'.$version, false);
});
