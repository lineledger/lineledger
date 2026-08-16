<?php

use App\Actions\Portal\RequestPortalLoginLink;
use App\Models\Company;
use App\Models\Contact;
use App\Models\PortalLoginLink;
use App\Notifications\Portal\PortalLoginLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->customer = Contact::create([
        'display_name' => 'Acme Co',
        'email' => 'buyer@acme.test',
        'is_customer' => true,
        'is_active' => true,
    ]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('emails a magic link to an eligible customer', function () {
    Notification::fake();

    app(RequestPortalLoginLink::class)->handle($this->company, 'buyer@acme.test');

    expect(PortalLoginLink::where('contact_id', $this->customer->id)->count())->toBe(1);
    Notification::assertSentTo($this->customer, PortalLoginLinkNotification::class);
});

it('is enumeration-safe: no link or email for an unknown address', function () {
    Notification::fake();

    app(RequestPortalLoginLink::class)->handle($this->company, 'stranger@nowhere.test');

    expect(PortalLoginLink::count())->toBe(0);
    Notification::assertNothingSent();
});

it('does not issue links to non-customers or inactive contacts', function () {
    Notification::fake();

    Contact::create(['display_name' => 'Vendor', 'email' => 'v@x.test', 'is_vendor' => true, 'is_customer' => false]);
    Contact::create(['display_name' => 'Inactive', 'email' => 'old@acme.test', 'is_customer' => true, 'is_active' => false]);

    app(RequestPortalLoginLink::class)->handle($this->company, 'v@x.test');
    app(RequestPortalLoginLink::class)->handle($this->company, 'old@acme.test');

    expect(PortalLoginLink::count())->toBe(0);
    Notification::assertNothingSent();
});

it('reports success from the login component without revealing whether a match was found', function () {
    Notification::fake();

    Livewire::test('pages::portal.login', ['company' => $this->company])
        ->set('email', 'stranger@nowhere.test')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('sent', true);
});

it('consumes a valid link and signs the customer in', function () {
    $token = 'validtoken123';
    PortalLoginLink::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'token_hash' => PortalLoginLink::hashToken($token),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
    ]);

    $this->get(route('portal.login.consume', ['company' => $this->company->slug, 'token' => $token]))
        ->assertRedirect(route('portal.dashboard', ['company' => $this->company->slug]));

    $this->assertAuthenticatedAs($this->customer, 'customer');
    expect(PortalLoginLink::first()->used_at)->not->toBeNull();
});

it('rejects an expired link', function () {
    $token = 'expiredtoken';
    PortalLoginLink::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'token_hash' => PortalLoginLink::hashToken($token),
        'expires_at' => CarbonImmutable::now()->subMinute(),
    ]);

    $this->get(route('portal.login.consume', ['company' => $this->company->slug, 'token' => $token]))
        ->assertRedirect(route('portal.login', ['company' => $this->company->slug]));

    $this->assertGuest('customer');
});

it('rejects an already-used link', function () {
    $token = 'usedtoken';
    PortalLoginLink::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'token_hash' => PortalLoginLink::hashToken($token),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
        'used_at' => CarbonImmutable::now()->subMinute(),
    ]);

    $this->get(route('portal.login.consume', ['company' => $this->company->slug, 'token' => $token]))
        ->assertRedirect(route('portal.login', ['company' => $this->company->slug]));

    $this->assertGuest('customer');
});

it('will not consume a link under a different company', function () {
    $other = Company::factory()->create();
    $token = 'crosscompany';

    PortalLoginLink::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->customer->id,
        'token_hash' => PortalLoginLink::hashToken($token),
        'expires_at' => CarbonImmutable::now()->addMinutes(10),
    ]);

    // The request binds the OTHER company (portal.company middleware), so the
    // hashed-token lookup is scoped away from the issuing company.
    app()->forgetInstance('current_company');

    $this->get(route('portal.login.consume', ['company' => $other->slug, 'token' => $token]))
        ->assertRedirect(route('portal.login', ['company' => $other->slug]));

    $this->assertGuest('customer');
});
