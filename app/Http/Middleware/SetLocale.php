<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the UI locale for this request.
 *
 * Priority: `?lang=` (also sets the guest cookie) → on the customer and
 * employee portals: cookie, then the signed-in contact's document language,
 * then the portal company's → staff user locale → cookie → app default.
 *
 * Organization locale is not used for staff UI; it only feeds customer
 * documents and the portals. A portal session in the same browser never
 * changes the staff app's language.
 */
class SetLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->fromQuery($request)
            ?? $this->fromPortal($request)
            ?? $this->fromStaff($request)
            ?? $this->fromCookie($request)
            ?? Locales::canonicalize((string) config('app.default_locale', 'en'));

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

    /**
     * Portal pages (and their Livewire updates) use the visitor's explicit choice,
     * then the contact's document language, then the portal company's.
     *
     * This runs before `portal.company` binds the company, so it must not resolve
     * the customer guard: the guard would load the Contact without CompanyScope
     * and cache it for the rest of the request. Instead it reads the contact id
     * from the session and loads that contact only within the portal's company.
     */
    protected function fromPortal(Request $request): ?string
    {
        $company = $this->portalCompany($request);

        if ($company === null) {
            return null;
        }

        if ($cookie = $this->fromCookie($request)) {
            return $cookie;
        }

        $contactId = $request->hasSession()
            ? $request->session()->get(Auth::guard('customer')->getName())
            : null;

        $contact = $contactId === null ? null : Contact::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->find($contactId);

        return Locales::forDocument($contact, $company);
    }

    protected function portalCompany(Request $request): ?Company
    {
        if (! Livewire::isLivewireRequest()) {
            if (! $request->routeIs('portal.*', 'employee-portal.*')) {
                return null;
            }

            $company = $request->route('company');

            return $company instanceof Company
                ? $company
                : (is_string($company) ? Company::where('slug', $company)->first() : null);
        }

        // A Livewire update posts to /livewire/update; the page it belongs to is
        // in the component snapshot. Only the language depends on it.
        $segments = explode('/', trim((string) Livewire::originalPath(), '/'));

        if (! in_array($segments[0], ['pay', 'my-pay'], true) || ($segments[1] ?? '') === '') {
            return null;
        }

        return Company::where('slug', $segments[1])->first();
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
