<?php

namespace App\Livewire\Hooks;

use App\Models\Company;
use Illuminate\Support\Facades\Auth;
use Livewire\ComponentHook;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Re-binds `current_company` in the container on every Livewire request.
 *
 * Initial GET requests bind it via the EnsureCompanyMembership HTTP middleware,
 * but Livewire's AJAX update endpoint (/livewire-a56fc212/update) does NOT
 * route through that middleware — it's a global Livewire route with only
 * RequireLivewireHeaders applied. Without re-binding, the BelongsToCompany
 * trait can't auto-fill company_id on new models and CompanyScope can't
 * scope queries to the current company.
 *
 * Any Livewire component with a `public Company $company` property gets
 * the binding for free. Multi-tab safe — each request's component carries
 * its own company in the snapshot.
 *
 * Finding #7 (2026-08-18 security review): that same AJAX shortcut means an
 * open tab's snapshot can keep re-binding a company the `web`-guard user has
 * since been removed from — EnsureCompanyMembership's belongsToCompany()
 * check never runs again after the initial page load. This re-checks it on
 * every hydrate/call/update/render, so a revoked user's open tab starts
 * 403ing on its next interaction rather than continuing to read/write that
 * company's data until the tab refreshes or the session is killed
 * server-side. Scoped to the `web` guard only: portal Livewire components
 * also carry a `company` property but authenticate under the separate
 * `customer` guard (see EnsurePortalAudience) and must not be affected.
 *
 * Site admins are exempt from the membership check: the /admin/* portal
 * (see routes/admin.php, guarded by EnsureSiteAdmin) deliberately lets a
 * platform operator manage any company without being one of its members —
 * that surface's authorization model is "is site admin", not "belongs to
 * this company", and its components enforce that themselves.
 *
 * mount() doesn't enforce the check (only binds) — it always runs right
 * after a fresh page load, which for tenant-scoped pages already passed
 * EnsureCompanyMembership, and for the admin portal is gated by the
 * component's own site-admin guard instead. Re-validating membership there
 * too would preempt that guard's own (intentionally 404, not 403) response
 * for a non-admin, since Livewire hydrates `$component->company` before the
 * component's own mount() body runs. The revocation check that matters —
 * catching an already-open tab after access is pulled mid-session — only
 * needs to run on the *subsequent* hydrate/call/update/render requests.
 */
class BindCurrentCompanyHook extends ComponentHook
{
    public function mount($params, $parent): void
    {
        $this->bindFromComponent(enforce: false);
    }

    public function hydrate(): void
    {
        $this->bindFromComponent();
    }

    public function call($method, $params, $returnEarly, $metadata, $componentContext)
    {
        $this->bindFromComponent();
    }

    public function update($property, $path, $value)
    {
        $this->bindFromComponent();
    }

    public function render($view, $data)
    {
        $this->bindFromComponent();
    }

    protected function bindFromComponent(bool $enforce = true): void
    {
        $component = $this->component;

        if ($component === null) {
            return;
        }

        if (! property_exists($component, 'company') || ! isset($component->company)) {
            return;
        }

        if (! $component->company instanceof Company) {
            return;
        }

        $user = Auth::guard('web')->user();

        if ($enforce && $user !== null && ! $user->site_admin && ! $user->belongsToCompany($component->company)) {
            throw new HttpException(403, 'You no longer have access to this company.');
        }

        app()->instance('current_company', $component->company);
    }
}
