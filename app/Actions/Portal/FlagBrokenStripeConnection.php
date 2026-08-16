<?php

namespace App\Actions\Portal;

use App\Models\Company;
use App\Notifications\Companies\StripeConnectionBrokenNotification;
use Illuminate\Support\Facades\Log;

/**
 * Records that a company's Stripe Connect link is broken and alerts the owner to
 * reconnect. Idempotent via {@see Company::markStripeConnectionBroken()}: only the
 * first failure flags the connection and emails the owner, so a flood of failed
 * customer payments doesn't re-alert.
 */
final class FlagBrokenStripeConnection
{
    public function handle(Company $company): void
    {
        if (! $company->markStripeConnectionBroken()) {
            return;
        }

        Log::warning('Stripe connected-account access lost; payments paused pending reconnect.', [
            'company_id' => $company->id,
            'stripe_account_id' => $company->stripe_account_id,
        ]);

        $company->owner()?->notify(new StripeConnectionBrokenNotification($company));
    }
}
