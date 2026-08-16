<?php

namespace App\Http\Middleware;

use App\Enums\JurisdictionCapability;
use App\Models\Company;
use App\Models\Contact;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audience gate for the two external portals, both of which authenticate a
 * Contact on the shared `customer` guard. It runs *after* auth:customer and
 * asserts the signed-in contact actually belongs to the requested audience:
 *
 *   portal.audience:customer  → $contact->is_customer
 *   portal.audience:employee  → $contact->is_employee  (+ company supports payroll)
 *
 * A contact flagged as both passes both gates (and may use both portals). This
 * keeps an employee-only contact out of the customer portal and a customer-only
 * contact out of the employee portal even though they share a guard and session.
 */
class EnsurePortalAudience
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $audience = 'customer'): Response
    {
        $contact = Auth::guard('customer')->user();

        abort_if(! $contact instanceof Contact, 403);

        // Active-status is gated at link issuance (the eligibility scopes), matching
        // the customer portal — the per-request gate here is purely the audience.
        $allowed = match ($audience) {
            'employee' => (bool) $contact->is_employee,
            'customer' => (bool) $contact->is_customer,
            default => false,
        };

        abort_unless($allowed, 403);

        // The employee portal exposes payroll artifacts, so it exists only for a
        // company whose jurisdiction supports payroll (a US company has none).
        if ($audience === 'employee') {
            $company = app('current_company');

            abort_unless(
                $company instanceof Company && $company->supports(JurisdictionCapability::Payroll),
                404,
            );
        }

        return $next($request);
    }
}
