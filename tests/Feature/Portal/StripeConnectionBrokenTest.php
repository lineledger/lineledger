<?php

use App\Actions\Portal\FlagBrokenStripeConnection;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Companies\StripeConnectionBrokenNotification;
use App\Services\Posting\InvoicePoster;
use App\Services\Stripe\StripePaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;

beforeEach(function () {
    Notification::fake();

    // The Connect controller resolves a StripeClient (built from this key) even
    // for the disconnect path, which makes no Stripe call — give it a dummy so
    // the container can construct it.
    config()->set('services.stripe.secret', 'sk_test_dummy');

    $this->company = Company::factory()->create(['currency_code' => 'CAD']);
    $this->company->forceFill(['stripe_account_id' => 'acct_test123', 'stripe_connected_at' => now()])->save();
    app()->instance('current_company', $this->company);

    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->customer = Contact::create(['display_name' => 'Acme', 'email' => 'a@x.test', 'is_customer' => true]);

    $invoice = Invoice::create([
        'contact_id' => $this->customer->id,
        'invoice_no' => 'INV-BROKE-1',
        'invoice_date' => CarbonImmutable::create(2026, 5, 1),
        'due_date' => CarbonImmutable::create(2026, 5, 31),
    ]);
    $invoice->lines()->create([
        'account_id' => $this->income->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);
    app(InvoicePoster::class)->post($invoice);

    $this->actingAs($this->customer, 'customer');
});

afterEach(fn () => app()->forgetInstance('current_company'));

function mockRevokedStripe(): void
{
    test()->mock(StripePaymentService::class, function ($mock) {
        $mock->shouldReceive('createPaymentIntent')->andThrow(
            PermissionException::factory('The provided key does not have access to account acct_test123.'),
        );
    });
}

it('classifies revoked-connection Stripe errors but not transient ones', function () {
    expect(StripePaymentService::isConnectionRevoked(PermissionException::factory('no access')))->toBeTrue()
        ->and(StripePaymentService::isConnectionRevoked(
            InvalidRequestException::factory('No such account', null, null, null, null, 'account_invalid'),
        ))->toBeTrue()
        ->and(StripePaymentService::isConnectionRevoked(new RuntimeException('network blip')))->toBeFalse();
});

it('pauses card payments and alerts the owner when the Stripe connection is revoked', function () {
    mockRevokedStripe();

    Livewire::test('pages::portal.pay', ['company' => $this->company])
        ->call('preparePayment')
        ->assertSet('clientSecret', null)
        ->assertSee('Online payments unavailable');

    $fresh = $this->company->fresh();
    expect($fresh->canAcceptCardPayments())->toBeFalse()
        ->and($fresh->stripeConnectionNeedsAttention())->toBeTrue();

    Notification::assertSentTo($this->owner, StripeConnectionBrokenNotification::class);
});

it('alerts the owner only once across repeated failures', function () {
    // First failure flags + alerts; a second handle() on the already-flagged
    // company (loaded fresh, so the broken state is visible) must not re-alert.
    app(FlagBrokenStripeConnection::class)->handle($this->company);
    app(FlagBrokenStripeConnection::class)->handle($this->company->fresh());

    Notification::assertSentToTimes($this->owner, StripeConnectionBrokenNotification::class, 1);
    expect($this->company->fresh()->stripeConnectionNeedsAttention())->toBeTrue();
});

it('does not pause payments on a transient Stripe error', function () {
    $this->mock(StripePaymentService::class, function ($mock) {
        $mock->shouldReceive('createPaymentIntent')->andThrow(new RuntimeException('network blip'));
    });

    Livewire::test('pages::portal.pay', ['company' => $this->company])
        ->call('preparePayment')
        ->assertSet('errorMessage', __('We could not start the payment. Please try again later.'));

    expect($this->company->fresh()->canAcceptCardPayments())->toBeTrue();
    Notification::assertNothingSent();
});

it('does not offer card payment on the portal once the connection is broken', function () {
    $this->company->markStripeConnectionBroken();

    Livewire::test('pages::portal.pay', ['company' => $this->company])
        ->assertSee('Online payments unavailable')
        ->assertDontSee('payment-element');
});

it('prompts the owner to reconnect in company settings when the connection is broken', function () {
    $this->company->markStripeConnectionBroken();

    $this->actingAs($this->owner)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('companies.edit', $this->company))
        ->assertOk()
        ->assertSee('Stripe connection needs attention')
        ->assertSee('Reconnect Stripe');
});

it('clears the broken flag when the company reconnects or disconnects', function () {
    $this->company->markStripeConnectionBroken();
    expect($this->company->fresh()->stripeConnectionNeedsAttention())->toBeTrue();

    // A manual disconnect resets the connection state cleanly.
    $this->actingAs($this->owner)
        ->delete(route('settings.stripe.disconnect', $this->company))
        ->assertRedirect();

    $fresh = $this->company->fresh();
    expect($fresh->stripeConnectionNeedsAttention())->toBeFalse()
        ->and($fresh->hasStripeConnected())->toBeFalse()
        ->and($fresh->stripe_disconnected_at)->toBeNull();
});
