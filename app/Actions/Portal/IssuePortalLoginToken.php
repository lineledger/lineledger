<?php

namespace App\Actions\Portal;

use App\Models\Company;
use App\Models\Contact;
use App\Models\PortalLoginLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Creates a one-time, hashed magic-link token for a customer and returns the
 * consume URL the customer clicks. Shared by passwordless login and by sending a
 * specific document (e.g. an invoice), where an optional intended path deep-links
 * the customer past the dashboard after sign-in.
 */
final class IssuePortalLoginToken
{
    /**
     * Minutes a freshly issued link remains valid.
     */
    public const TTL_MINUTES = 15;

    public function handle(
        Company $company,
        Contact $contact,
        ?string $intendedPath = null,
        string $consumeRouteName = 'portal.login.consume',
    ): string {
        $token = Str::random(48);

        PortalLoginLink::create([
            'company_id' => $company->id,
            'contact_id' => $contact->id,
            'token_hash' => PortalLoginLink::hashToken($token),
            'expires_at' => CarbonImmutable::now()->addMinutes(self::TTL_MINUTES),
            'intended_path' => $intendedPath,
        ]);

        // The same one-time PortalLoginLink row backs both portals; only the
        // consume route differs (customer vs. employee), so the magic-link URL
        // lands the recipient on the right sign-in endpoint.
        return route($consumeRouteName, [
            'company' => $company->slug,
            'token' => $token,
        ]);
    }
}
