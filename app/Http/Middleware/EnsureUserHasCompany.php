<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Redirect authenticated users who don't yet belong to any company to the
 * welcome onboarding page where they pick a jurisdiction and create their
 * first company. The welcome, restore, logout and auth routes are exempt so
 * the user can complete onboarding (including restoring from a backup, which
 * creates the company itself) or sign out.
 */
class EnsureUserHasCompany
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Staff-only concern: read the web guard explicitly so a customer signed
        // in to the payment portal (customer guard) is never mistaken for a user.
        $user = $request->user('web');

        if (! $user) {
            return $next($request);
        }

        if ($this->isExempt($request)) {
            return $next($request);
        }

        if ($user->companies()->exists()) {
            return $next($request);
        }

        return redirect()->route('welcome.create-company');
    }

    protected function isExempt(Request $request): bool
    {
        if ($request->routeIs(
            'welcome.create-company',
            // Restoring from a backup creates the new company itself, so a user
            // who has no company yet must be able to reach this page — the
            // onboarding wizard redirects here when "Restore from a backup" is
            // chosen.
            'companies.restore',
            // Accepting an invitation is how an invited user gains their first
            // company, so they reach it with none. The page authorizes by
            // matching the invitation email to the user, not by membership.
            'invitations.accept',
            // A user with outstanding legal documents is held on the acceptance
            // screen by EnsureLegalAcceptance (which runs first); that screen
            // must stay reachable even before they have a company.
            'legal.accept',
            // Email verification now comes *before* onboarding: the welcome
            // wizard sits behind the `verified` middleware, which bounces an
            // unverified user to verification.notice. Without these exemptions
            // this middleware would bounce them straight back to the wizard —
            // an infinite redirect loop — and verification.verify would never
            // reach its controller, so the emailed link could never be used.
            'verification.notice',
            'verification.verify',
            'verification.send',
            'logout',
            'login',
            'register',
            'register.store',
        )) {
            return true;
        }

        // Livewire's frontend asset + update endpoints live under a versioned
        // `livewire-{hash}` prefix (e.g. /livewire-a56fc212/update). Redirecting
        // those would return HTML to a JSON-expecting client and break the
        // welcome form itself, since that form is a Livewire component.
        return preg_match('#^livewire(-[a-z0-9]+)?/#', ltrim($request->path(), '/')) === 1;
    }
}
