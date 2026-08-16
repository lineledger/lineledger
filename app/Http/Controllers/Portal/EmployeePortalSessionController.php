<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\PortalLoginLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeePortalSessionController extends Controller
{
    /**
     * Exchange a one-time magic-link token for an authenticated employee-portal
     * session. The current company is already bound by the portal.company
     * middleware, so the link (and its contact) are looked up within this company
     * only.
     */
    public function consume(Request $request, Company $company, string $token): RedirectResponse
    {
        $link = PortalLoginLink::query()
            ->where('company_id', $company->id)
            ->where('token_hash', PortalLoginLink::hashToken($token))
            ->first();

        // Re-assert the employee audience at the auth boundary: a token issued to
        // a customer-only contact can never mint an employee session here, even if
        // the customer/employee consume endpoints were ever confused.
        if ($link === null || ! $link->isUsable() || ! $link->contact->is_employee || ! $link->contact->is_active) {
            return redirect()
                ->route('employee-portal.login', ['company' => $company->slug])
                ->withErrors(['email' => __('That sign-in link is invalid or has expired. Please request a new one.')]);
        }

        $link->forceFill(['used_at' => now()])->save();

        Auth::guard('customer')->login($link->contact);
        $request->session()->regenerate();

        // Deep-link only to a server-generated my-pay path (never user input).
        if ($link->intended_path !== null && str_starts_with($link->intended_path, '/my-pay/'.$company->slug.'/')) {
            return redirect()->to($link->intended_path);
        }

        return redirect()->route('employee-portal.dashboard', ['company' => $company->slug]);
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('employee-portal.login', ['company' => $company->slug]);
    }
}
