<?php

namespace App\Http\Controllers\Stripe;

use App\Actions\Portal\EnsureStripeAccounts;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\Stripe\StripePaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;

/**
 * Stripe Connect (Standard) OAuth onboarding for a company. The owner is sent to
 * Stripe to authorize the platform; Stripe redirects back to a fixed callback URL
 * with an authorization code and our signed state (the company id), which we
 * exchange for the connected account id.
 */
class ConnectController extends Controller
{
    public function __construct(protected StripePaymentService $stripe) {}

    /**
     * Begin onboarding: redirect the owner to Stripe's OAuth consent screen.
     */
    public function redirect(Company $company): RedirectResponse
    {
        $state = Crypt::encryptString((string) $company->id);

        return redirect()->away(
            $this->stripe->authorizeUrl($state, route('stripe.connect.callback')),
        );
    }

    /**
     * Handle Stripe's OAuth redirect back to the platform.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('companies.index')
                ->with('status', __('Stripe connection was cancelled.'));
        }

        try {
            $companyId = (int) Crypt::decryptString((string) $request->query('state'));
        } catch (\Throwable) {
            abort(403);
        }

        $company = Company::query()->withoutGlobalScopes()->findOrFail($companyId);

        // The returning user must be an owner of the company they connected.
        abort_unless($request->user()?->companyRole($company)?->value === 'owner', 403);

        $accountId = $this->stripe->exchangeOAuthCode((string) $request->query('code'));

        $company->forceFill([
            'stripe_account_id' => $accountId,
            'stripe_connected_at' => now(),
            'stripe_disconnected_at' => null,
        ])->save();

        app()->instance('current_company', $company);
        app(EnsureStripeAccounts::class)->handle($company);

        return redirect()->route('companies.edit', ['company' => $company->slug])
            ->with('status', __('Stripe account connected.'));
    }

    /**
     * Disconnect Stripe from the company. Existing receipts are untouched; new
     * card payments simply become unavailable until reconnected.
     */
    public function disconnect(Company $company): RedirectResponse
    {
        $company->forceFill([
            'stripe_account_id' => null,
            'stripe_connected_at' => null,
            'stripe_disconnected_at' => null,
        ])->save();

        return redirect()->route('companies.edit', ['company' => $company->slug])
            ->with('status', __('Stripe account disconnected.'));
    }
}
