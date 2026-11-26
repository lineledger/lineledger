<?php

// On an operator's per-country sites (APP_URL_CA / _US / _GB), the signed-out
// pages name the site's country under the logo and link the other countries'
// sites, since accounts don't carry over between them. Any other host, a
// self-hosted installation's included, shows nothing new.

beforeEach(function () {
    config(['app.app_urls' => [
        'CA' => 'https://books.example.ca',
        'US' => 'https://books.example.com',
        'GB' => 'https://books.example.co.uk',
    ]]);
});

test('the sign-in page names this site’s country and links the other countries’ sites', function () {
    $this->get('https://books.example.co.uk/login')
        ->assertOk()
        ->assertSeeInOrder([
            'data-test="site-country"',
            '🇬🇧',
            'United Kingdom',
            'Each country has its own site with separate accounts, and an account can’t be moved to another country’s site.',
            'Another country?',
        ], false)
        ->assertSee('href="https://books.example.ca/login?ll=ca"', false)
        ->assertSee('href="https://books.example.com/login?ll=us"', false)
        ->assertDontSee('href="https://books.example.co.uk/login?ll=gb"', false);
});

test('the registration and password pages show it too, linking the same page elsewhere', function (string $path) {
    $this->get('https://books.example.com'.$path)
        ->assertOk()
        ->assertSee('data-test="site-country"', false)
        ->assertSee('United States')
        ->assertSee('href="https://books.example.ca'.$path.'?ll=ca"', false);
})->with(['/register', '/forgot-password']);

test('a sibling site that isn’t live yet is left out', function () {
    config(['app.app_urls.GB' => '']);

    $this->get('https://books.example.ca/login')
        ->assertOk()
        ->assertSee('href="https://books.example.com/login?ll=us"', false)
        ->assertDontSee('books.example.co.uk', false);
});

test('a site with no sibling live yet names its country without the note', function () {
    config(['app.app_urls.US' => '', 'app.app_urls.GB' => '']);

    $this->get('https://books.example.ca/login')
        ->assertOk()
        ->assertSee('data-test="site-country"', false)
        ->assertSee('Canada')
        ->assertDontSee('Another country?');
});

test('a sibling URL with a trailing slash still links cleanly', function () {
    config(['app.app_urls.US' => 'https://books.example.com/']);

    $this->get('https://books.example.ca/login')
        ->assertOk()
        ->assertSee('href="https://books.example.com/login?ll=us"', false);
});

test('shows nothing on a host that isn’t one of the sites', function () {
    $this->get('https://ledger.example.test/login')
        ->assertOk()
        ->assertDontSee('data-test="site-country"', false);

    config(['app.app_urls' => ['CA' => '', 'US' => '', 'GB' => '']]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('data-test="site-country"', false);
});
