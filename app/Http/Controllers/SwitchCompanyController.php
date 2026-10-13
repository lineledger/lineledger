<?php

namespace App\Http\Controllers;

use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\SecurityLogRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The company switcher's target. The switcher posts here from a
 * `target="_blank"` form, so the chosen company opens in a new tab while the
 * tab the user clicked in stays where it was.
 *
 * The new tab always lands on the chosen company's dashboard. Carrying the
 * current page across would 404 whenever that page names a record (an
 * invoice, a contact's statement) that belongs to the company being left.
 *
 * The tab's own company arrives as `from` rather than being read off the
 * user's current company: with several tabs open, that pointer names whichever
 * tab loaded last, so the security log would record the wrong origin.
 */
class SwitchCompanyController extends Controller
{
    public function __invoke(Request $request, Company $company, SecurityLogRecorder $recorder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless($user->belongsToCompany($company), 403);

        $fromSlug = $this->fromCompany($request, $user)?->slug;

        $user->switchCompany($company);

        if ($fromSlug !== $company->slug) {
            $recorder->record(SecurityEvent::CompanySwitched, $user, metadata: [
                'from_company_slug' => $fromSlug,
                'to_company_slug' => $company->slug,
            ]);
        }

        return redirect()->route('dashboard', ['company' => $company->slug]);
    }

    /**
     * The company of the tab the switch came from, trusted only when the user
     * belongs to it; otherwise the user's most recently used company.
     */
    protected function fromCompany(Request $request, User $user): ?Company
    {
        $slug = $request->input('from');

        $from = is_string($slug) && $slug !== ''
            ? $user->companies()->where('companies.slug', $slug)->first()
            : null;

        return $from ?? $user->currentCompany;
    }
}
