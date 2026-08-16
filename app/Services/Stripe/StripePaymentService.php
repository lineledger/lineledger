<?php

namespace App\Services\Stripe;

use App\Models\Company;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

/**
 * Thin wrapper over the Stripe SDK for the customer portal, operating under
 * Stripe Connect: charges are created on each company's connected account so
 * funds settle to the company, not the platform. Standard accounts are the
 * merchant of record, so the processing fee is deducted from their balance and
 * read back off the charge's balance transaction.
 */
class StripePaymentService
{
    public function __construct(protected StripeClient $stripe) {}

    /**
     * OAuth URL a company owner visits to connect their Stripe account.
     */
    public function authorizeUrl(string $state, string $redirectUri): string
    {
        return 'https://connect.stripe.com/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => config('services.stripe.client_id'),
            'scope' => 'read_write',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    /**
     * Exchange an OAuth authorization code for the connected account id (acct_…).
     */
    public function exchangeOAuthCode(string $code): string
    {
        $response = $this->stripe->oauth->token([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        return $response->stripe_user_id;
    }

    /**
     * Whether a Stripe error means the platform key can no longer act on the
     * company's connected account — the link was revoked, disconnected, or the
     * account no longer exists. These don't fix themselves on a retry: the owner
     * must reconnect. Distinct from transient/network errors, which may.
     */
    public static function isConnectionRevoked(\Throwable $e): bool
    {
        if ($e instanceof PermissionException) {
            return true;
        }

        return $e instanceof InvalidRequestException && $e->getStripeCode() === 'account_invalid';
    }

    /**
     * Create a PaymentIntent on the company's connected account. The amount is
     * computed server-side by the caller from invoice balances, never the client.
     *
     * @param  array<string, string>  $metadata
     */
    public function createPaymentIntent(Company $company, int $amountCents, array $metadata): PaymentIntent
    {
        return $this->stripe->paymentIntents->create([
            'amount' => $amountCents,
            'currency' => strtolower($company->currency_code ?: 'usd'),
            'automatic_payment_methods' => ['enabled' => true],
            'metadata' => $metadata,
        ], ['stripe_account' => $company->stripe_account_id]);
    }

    /**
     * Retrieve a PaymentIntent (with its charge + balance transaction expanded)
     * from the connected account.
     */
    public function retrievePaymentIntent(Company $company, string $paymentIntentId): PaymentIntent
    {
        return $this->stripe->paymentIntents->retrieve(
            $paymentIntentId,
            ['expand' => ['latest_charge.balance_transaction']],
            ['stripe_account' => $company->stripe_account_id],
        );
    }

    /**
     * The Stripe processing fee, in cents, for a settled PaymentIntent.
     */
    public function feeForPaymentIntent(Company $company, string $paymentIntentId): int
    {
        $intent = $this->retrievePaymentIntent($company, $paymentIntentId);
        $charge = $intent->latest_charge;

        if ($charge === null || $charge->balance_transaction === null) {
            return 0;
        }

        return (int) $charge->balance_transaction->fee;
    }
}
