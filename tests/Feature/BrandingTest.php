<?php

use App\Models\User;

test('the logo falls back to the project mark, in one theme-agnostic image', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('/logo/line-ledger-logo.png', false);
    $response->assertDontSee('dark:hidden', false);
});

test('a deployment can point the logo somewhere else', function () {
    config(['brand.logo' => '/storage/brand/logo.png']);

    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('/storage/brand/logo.png', false);
    $response->assertDontSee('/logo/line-ledger-logo.png', false);
});

test('a dark logo is swapped in under the dark theme', function () {
    config([
        'brand.logo' => '/storage/brand/logo.png',
        'brand.logo_dark' => '/storage/brand/logo-dark.png',
    ]);

    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertSee('/storage/brand/logo.png', false);
    $response->assertSee('/storage/brand/logo-dark.png', false);
    $response->assertSee('dark:hidden', false);
    $response->assertSee('hidden dark:block', false);
});

test('the browser tab follows the app name', function () {
    config(['app.name' => 'Alternatives']);

    $this->get(route('login'))
        ->assertOk()
        ->assertSee('Alternatives</title>', false);
});

test('the footer names the configured operator', function () {
    config(['brand.footer.owner' => 'Personal Alternative Funeral Services Limited']);

    $this
        ->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Personal Alternative Funeral Services Limited')
        ->assertDontSeeText('Local Foundry Inc.');
});

test('the project links can be dropped, keeping the version', function () {
    config(['brand.footer.project_links' => false]);

    $version = (string) config('version.app');

    $response = $this
        ->actingAs(User::factory()->create())
        ->get(route('dashboard'));

    $response->assertOk();
    $response->assertSeeText('v'.$version);
    $response->assertDontSeeText('AGPL-3.0');
    $response->assertDontSee('https://github.com/lineledger/lineledger', false);
    $response->assertDontSee('/legal', false);
});

test('the country switcher shows on the sibling deployments', function () {
    config(['app.app_urls' => [
        'CA' => 'https://books.lineledger.ca',
        'US' => 'https://books.lineledger.com',
    ]]);

    $this->get('https://books.lineledger.ca/login')
        ->assertOk()
        ->assertSee('geoBanner(', false)
        // @js() escapes the slashes, so match the host rather than the URL.
        ->assertSee('books.lineledger.com', false);
});

test('the country switcher stays off a self-hosted domain', function () {
    config(['app.app_urls' => [
        'CA' => 'https://books.lineledger.ca',
        'US' => 'https://books.lineledger.com',
    ]]);

    $this->get('https://ledger.example.test/login')
        ->assertOk()
        ->assertDontSee('geoBanner(', false);
});

test('an explicit region does not bring the country switcher back', function () {
    config([
        'app.region' => 'CA',
        'app.app_urls' => [
            'CA' => 'https://books.lineledger.ca',
            'US' => 'https://books.lineledger.com',
        ],
    ]);

    $this->get('https://ledger.example.test/login')
        ->assertOk()
        ->assertDontSee('geoBanner(', false);
});
