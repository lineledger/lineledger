<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| The app answers only on the hosts it is configured for
|--------------------------------------------------------------------------
| APP_URL's host, plus any listed in TRUSTED_HOSTS. Any other Host is refused
| with 400 before a page, a redirect or an email can be built on it. Laravel's
| TrustHosts middleware stands down in local and testing, so these tests run
| the app as production.
*/

beforeEach(function () {
    $this->app['env'] = 'production';
});

afterEach(function () {
    // The allowed hosts live in Symfony statics that outlive this test's app.
    Request::setTrustedHosts([]);
});

it('answers on the APP_URL host', function () {
    $this->get('http://localhost/login')->assertOk();
});

it('refuses a host it is not configured for', function () {
    $this->get('http://attacker.example/login')->assertBadRequest();
});

it('refuses a subdomain of the APP_URL host', function () {
    $this->get('http://evil.localhost/login')->assertBadRequest();
});

it('refuses an unknown host on the API too', function () {
    $this->getJson('http://attacker.example/api/v1/accounts')->assertBadRequest();
});

it('answers on the extra hosts in TRUSTED_HOSTS', function () {
    config(['app.trusted_hosts' => ['books.example.com', ' HTTPS://Alias.Example.org:8443/ledger ', '']]);

    $this->get('http://books.example.com/login')->assertOk();
    $this->get('http://alias.example.org/login')->assertOk();
    $this->get('http://attacker.example/login')->assertBadRequest();
});

it('sends no password-reset email for a refused host', function () {
    Notification::fake();
    User::factory()->create(['email' => 'owner@example.com']);

    $this->post('http://attacker.example/forgot-password', ['email' => 'owner@example.com'])->assertBadRequest();

    Notification::assertNothingSent();
});

it('does not let X-Forwarded-Host stand in for the Host, even from a trusted proxy', function () {
    config(['trustedproxy.proxies' => ['127.0.0.1']]);

    // Honoured, this header would make the host attacker.example, and a 400.
    $this->get('http://localhost/login', ['X-Forwarded-Host' => 'attacker.example'])->assertOk();
});

it('answers the health check whatever the host', function (string $url) {
    // Docker's HEALTHCHECK curls localhost:8080 inside the container, and App
    // Platform probes the container's own address; neither sends the public host.
    config(['app.url' => 'https://books.example.com']);

    $this->get($url)->assertOk();
    $this->get('http://localhost:8080/login')->assertBadRequest();
})->with([
    'Docker' => 'http://localhost:8080/up',
    'App Platform' => 'http://10.244.3.7:8080/up',
]);

it('serves Livewire updates on the APP_URL host when routes are cached', function () {
    // Issue #180. Every deployment caches its routes (docker/entrypoint.sh, the
    // Forge script), and a cached route collection reads the Host of each request
    // it matches, including the one BindCurrentCompanyHook builds to find the
    // page a component was rendered on. On a bare path that host was localhost,
    // which isn't trusted once APP_URL is a real name, so every update was a 400.

    // Plain HTTP on a custom port, as the reporter's install is reached.
    config(['app.url' => 'http://books.example.com:8180']);
    URL::forceRootUrl('http://books.example.com:8180');

    $router = app('router');
    $router->setCompiledRoutes($router->getRoutes()->compile());

    // Running as production switches CSRF back on, and each test request starts
    // a fresh session; the token is beside the point here. Every other gate runs.
    $this->withoutMiddleware(PreventRequestForgery::class);

    $owner = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $company);
    [$chequing, $savings] = Account::query()
        ->whereIn('subtype', [AccountSubtype::Bank->value, AccountSubtype::CreditCard->value])
        ->orderBy('code')
        ->take(2)
        ->get()
        ->all();
    app()->forgetInstance('current_company');

    $url = route('banking.register', ['company' => $company->slug, 'account' => $chequing->id]);
    expect($url)->toStartWith('http://books.example.com:8180/');

    $snapshot = livewireReplayCapture($owner, $url, 'pages::banking.register');

    // Sorting a column, and switching to another account: the reporter's steps.
    livewireReplay($snapshot, [['sortBy', 'memo']])->assertOk();
    livewireReplay($snapshot, updates: ['account_id' => $savings->id])->assertOk();
});
