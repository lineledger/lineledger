<?php

namespace App\Http\Controllers\Stripe;

use App\Actions\Portal\RecordStripePayment;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Webhook;

/**
 * Receives Stripe Connect webhooks. Events carry the connected `account` id, which
 * we resolve back to a Company. Posting is idempotent on the PaymentIntent id, so
 * Stripe's at-least-once delivery is safe to replay.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, RecordStripePayment $record): JsonResponse
    {
        // Fail closed on a missing secret: Stripe's verifier HMACs with whatever
        // key it is given, and an empty key yields a signature any caller can
        // forge. Refuse rather than accept unverifiable events (mirrors
        // InboundEmailController). 500 so Stripe retries once configured.
        $secret = (string) config('services.stripe.webhook_secret');
        abort_if($secret === '', 500, 'Stripe webhook secret is not configured.');

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
                $secret,
            );
        } catch (SignatureVerificationException|\UnexpectedValueException) {
            abort(400, 'Invalid signature.');
        }

        $accountId = $event->account ?? null;

        if ($accountId === null) {
            return response()->json(['received' => true]);
        }

        $company = Company::query()->withoutGlobalScopes()
            ->where('stripe_account_id', $accountId)
            ->first();

        if ($company === null) {
            return response()->json(['received' => true]);
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->handleSucceeded($company, $event->data->object, $record),
            'account.application.deauthorized' => $this->handleDeauthorized($company),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    /**
     * @param  PaymentIntent  $intent
     */
    protected function handleSucceeded(Company $company, $intent, RecordStripePayment $record): void
    {
        $metadata = $intent->metadata ?? null;
        $contactId = (int) ($metadata['contact_id'] ?? 0);
        $invoiceIds = array_filter(array_map('intval', explode(',', (string) ($metadata['invoice_ids'] ?? ''))));

        if ($contactId === 0 || $invoiceIds === []) {
            return;
        }

        app()->instance('current_company', $company);

        // Resolved here (after signature verification) so an unsigned/invalid
        // request never triggers Stripe client construction.
        $fee = app(StripePaymentService::class)->feeForPaymentIntent($company, $intent->id);

        $record->handle($company, $intent->id, (int) $intent->amount, $fee, $contactId, array_values($invoiceIds));
    }

    protected function handleDeauthorized(Company $company): void
    {
        $company->forceFill([
            'stripe_account_id' => null,
            'stripe_connected_at' => null,
        ])->save();
    }
}
