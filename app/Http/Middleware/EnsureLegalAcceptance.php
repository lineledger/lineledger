<?php

namespace App\Http\Middleware;

use App\Support\Legal\LegalDocuments;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Force an authenticated user to accept the current version of every legal
 * document that requires acceptance before they can use the app. This catches
 * invited users (who never saw the registration checkbox) and anyone whose
 * accepted version went stale after a document was updated — they are redirected
 * to the acceptance screen until they agree. Runs before EnsureUserHasCompany so
 * terms are accepted ahead of onboarding.
 */
class EnsureLegalAcceptance
{
    public function __construct(private readonly LegalDocuments $legal) {}

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

        if (! $this->legal->hasOutstanding($user)) {
            return $next($request);
        }

        return redirect()->route('legal.accept');
    }

    protected function isExempt(Request $request): bool
    {
        if ($request->routeIs(
            // The acceptance screen itself, plus the auth routes a user needs to
            // reach regardless (signing out, re-authenticating).
            'legal.accept',
            'logout',
            'login',
            'register',
            'register.store',
        )) {
            return true;
        }

        // Livewire's frontend asset + update endpoints live under a versioned
        // `livewire-{hash}` prefix; redirecting those would return HTML to a
        // JSON-expecting client and break the acceptance form, which is itself a
        // Livewire component.
        return preg_match('#^livewire(-[a-z0-9]+)?/#', ltrim($request->path(), '/')) === 1;
    }
}
