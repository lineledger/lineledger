<?php

use App\Models\Company;
use Illuminate\Testing\TestResponse;

/**
 * Build a Stripe-signed POST to the Connect webhook, signing with $secret.
 */
function postSignedConnectWebhook(array $event, string $secret): TestResponse
{
    $payload = json_encode($event);
    $timestamp = time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    return test()->call(
        'POST',
        '/stripe/webhook',
        [], [], [],
        ['HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}", 'CONTENT_TYPE' => 'application/json'],
        $payload,
    );
}

test('the Connect webhook fails closed when no signing secret is configured', function () {
    // With an empty secret the HMAC is forgeable; the controller must refuse the
    // event instead of resolving a company and recording a payment against it.
    config()->set('services.stripe.webhook_secret', '');
    Company::factory()->create(['stripe_account_id' => 'acct_abc']);

    postSignedConnectWebhook([
        'id' => 'evt_forged',
        'type' => 'payment_intent.succeeded',
        'account' => 'acct_abc',
        'data' => ['object' => ['id' => 'pi_forged', 'amount' => 100000]],
    ], secret: '')->assertStatus(500);
});

test('the Connect webhook rejects a bad signature when a secret is configured', function () {
    config()->set('services.stripe.webhook_secret', 'whsec_connect_test');
    Company::factory()->create(['stripe_account_id' => 'acct_abc']);

    postSignedConnectWebhook([
        'id' => 'evt_x',
        'type' => 'payment_intent.succeeded',
        'account' => 'acct_abc',
        'data' => ['object' => ['id' => 'pi_x', 'amount' => 100000]],
    ], secret: 'whsec_wrong')->assertStatus(400);
});
