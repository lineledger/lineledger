<?php

namespace App\Http\Responses;

use App\Http\Responses\Concerns\RedirectsToCurrentCompany;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a user lands after clicking the verification link in their email.
 *
 * Fortify's own response redirects to `config('fortify.home')` — `/dashboard` —
 * but that URI does not exist here: the real route is `{company}/dashboard`, so
 * the framework default 404s. The login and register responses already route
 * through RedirectsToCurrentCompany for exactly this reason; verification needs
 * the same treatment, and doubly so now that onboarding sits behind the
 * `verified` middleware — a freshly verified user usually has no company yet and
 * belongs on the welcome wizard, which is where the trait sends them.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    use RedirectsToCurrentCompany;

    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $path = $this->redirectPathForCurrentCompany($request, Fortify::redirects('email-verification'));

        return redirect()->intended($path.'?verified=1');
    }
}
