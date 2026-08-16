<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PortalLoginLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SessionController extends Controller
{
    /**
     * Exchange a one-time magic-link token for an authenticated portal session.
     * The current company is already bound by the portal.company middleware, so
     * the link (and its contact) are looked up within this company only.
     */
    public function consume(Request $request, Company $company, string $token): RedirectResponse
    {
        // current_company is already bound to $company by portal.company, so the
        // BelongsToCompany scope filters this lookup to the company in the URL.
        // The explicit company_id filter restates that invariant at the auth
        // boundary so the token can never resolve cross-tenant even if the
        // global scope is ever bypassed.
        $link = PortalLoginLink::query()
            ->where('company_id', $company->id)
            ->where('token_hash', PortalLoginLink::hashToken($token))
            ->first();

        if ($link === null || ! $link->isUsable()) {
            return redirect()
                ->route('portal.login', ['company' => $company->slug])
                ->withErrors(['email' => __('That sign-in link is invalid or has expired. Please request a new one.')]);
        }

        $link->forceFill(['used_at' => now()])->save();

        Auth::guard('customer')->login($link->contact);
        $request->session()->regenerate();

        // Deep-link to the document the link was issued for (e.g. a specific
        // invoice). The path is always server-generated, never user input.
        if ($link->intended_path !== null && str_starts_with($link->intended_path, '/pay/'.$company->slug.'/')) {
            return redirect()->to($link->intended_path);
        }

        return redirect()->route('portal.dashboard', ['company' => $company->slug]);
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login', ['company' => $company->slug]);
    }
}
