<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the UI locale for this request.
 *
 * Priority: `?lang=` (also sets the guest cookie) → signed-in customer
 * (document language) → staff user locale → cookie → app default.
 *
 * Organization locale is not used for staff UI; it only feeds customer
 * documents and the payment portal (via the contact/company resolver).
 */
class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromQuery($request)
            ?? $this->fromCustomer($request)
            ?? $this->fromStaff($request)
            ?? $this->fromCookie($request)
            ?? Locales::canonicalize(null);

        Locales::apply($locale);

        $response = $next($request);

        if ($request->query(Locales::QUERY) !== null && Locales::isSupported((string) $request->query(Locales::QUERY))) {
            $response->headers->setCookie(cookie()->forever(Locales::COOKIE, $locale));
        }

        return $response;
    }

    protected function fromQuery(Request $request): ?string
    {
        $lang = $request->query(Locales::QUERY);

        return is_string($lang) && Locales::isSupported($lang) ? $lang : null;
    }

    protected function fromCustomer(Request $request): ?string
    {
        $contact = $request->user('customer');
        $bound = app()->bound('current_company') ? app('current_company') : null;
        $company = $bound instanceof Company ? $bound : ($contact instanceof Contact ? $contact->company : null);

        if ($contact instanceof Contact) {
            return Locales::forDocument($contact, $company);
        }

        if ($company && ($request->is('pay/*') || $request->is('my-pay/*'))) {
            return Locales::forDocument(null, $company);
        }

        return null;
    }

    protected function fromStaff(Request $request): ?string
    {
        $user = $request->user('web');

        if (! $user instanceof User) {
            return null;
        }

        return Locales::isSupported($user->locale) ? $user->locale : null;
    }

    protected function fromCookie(Request $request): ?string
    {
        $cookie = $request->cookie(Locales::COOKIE);

        return is_string($cookie) && Locales::isSupported($cookie) ? $cookie : null;
    }
}
