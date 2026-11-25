<?php

namespace App\Actions\Portal;

use App\Enums\SensitiveFeature;
use App\Models\Company;
use App\Models\Contact;
use App\Notifications\Portal\EmployeePortalLoginLinkNotification;
use App\Support\Security\SensitiveFeatureGate;
use Illuminate\Support\Str;

/**
 * Issues a one-time magic-link to an employee for the self-service ("my-pay")
 * portal. Looks up an eligible employee by email within the company, stores a
 * hashed token (via the shared {@see IssuePortalLoginToken}, pointed at the
 * employee consume route), and emails the plaintext link. Enumeration-safe: the
 * caller always shows the same "if an account exists, we sent a link" message
 * regardless of whether a match was found.
 */
final class RequestEmployeePortalLoginLink
{
    public function __construct(
        protected IssuePortalLoginToken $tokens,
        protected SensitiveFeatureGate $gate,
    ) {}

    public function handle(Company $company, string $email): void
    {
        // Customer email kept behind two-factor authentication the owner hasn't
        // turned on: no link, and the same answer as for an unknown address.
        if (! $this->gate->allows(SensitiveFeature::CustomerEmail, $company)) {
            return;
        }

        $contact = Contact::query()
            ->where('company_id', $company->id)
            ->employeePortalEligible()
            ->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])
            ->first();

        if ($contact === null) {
            return;
        }

        $url = $this->tokens->handle($company, $contact, null, 'employee-portal.login.consume');

        $contact->notify(new EmployeePortalLoginLinkNotification($url, $company, IssuePortalLoginToken::TTL_MINUTES));
    }
}
