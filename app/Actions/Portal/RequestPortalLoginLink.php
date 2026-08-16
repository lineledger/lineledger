<?php

namespace App\Actions\Portal;

use App\Models\Company;
use App\Models\Contact;
use App\Notifications\Portal\PortalLoginLinkNotification;
use Illuminate\Support\Str;

/**
 * Issues a one-time magic-link to a customer for the payment portal. Looks up an
 * eligible customer by email within the company, stores a hashed token, and emails
 * the plaintext link. Enumeration-safe: the caller always shows the same "if an
 * account exists, we sent a link" message regardless of whether a match was found.
 */
final class RequestPortalLoginLink
{
    public function __construct(protected IssuePortalLoginToken $tokens) {}

    public function handle(Company $company, string $email): void
    {
        $contact = Contact::query()
            ->where('company_id', $company->id)
            ->portalEligible()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
            ->first();

        if ($contact === null) {
            return;
        }

        $url = $this->tokens->handle($company, $contact);

        $contact->notify(new PortalLoginLinkNotification($url, $company, IssuePortalLoginToken::TTL_MINUTES));
    }
}
