<?php

namespace App\Support\Contacts;

use App\Enums\Section;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\Reporting\ReportDatePresets;
use App\Support\Reporting\SourceLinkResolver;
use Illuminate\Support\Facades\Route;

/**
 * Resolves a contact to the page its name should link to — the contact-side
 * "QuickZoom", parallel to {@see SourceLinkResolver}
 * for documents. One place decides where each role goes so reports, index
 * pages and global search cannot drift:
 *
 *   customer   → AR statement
 *   vendor     → AP statement
 *   employee   → Employees page with the editor open
 *   other name → Transactions report filtered to that name, all-time range
 *
 * Only customers and vendors have a sub-ledger statement; an Other name's
 * activity lives on ordinary expense / bank lines, so the Transactions report
 * (which filters purely on journal_lines.contact_id) is its natural home. The
 * date range is explicit because that report defaults to fiscal-year-to-date
 * and would otherwise hide older cheques.
 */
class ContactLinkResolver
{
    /** @var array<string, bool> viewer:company:route => reachable */
    private array $reachable = [];

    /**
     * The contact's home URL, ungated. Global search uses this (its result
     * carries a non-nullable URL); blade links should use urlForViewer().
     */
    public function urlFor(Contact $contact, Company $company): string
    {
        [$name, $params] = $this->target($contact, $company);

        return route($name, $params);
    }

    /**
     * The contact's home URL, or null when the viewer cannot reach the section
     * that page belongs to — the same rule EnsureSectionAccess applies to the
     * request, so a link is rendered exactly when clicking it would not 403.
     */
    public function urlForViewer(Contact $contact, Company $company, ?User $viewer): ?string
    {
        [$name, $params] = $this->target($contact, $company);

        if (! Route::has($name) || ! $this->viewerCanReach($name, $company, $viewer)) {
            return null;
        }

        try {
            return route($name, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The Transactions report filtered to one contact over an all-time range.
     */
    public function transactionsUrl(Contact|int $contact, Company $company): string
    {
        return route('reports.transactions', $this->transactionsParams($contact, $company));
    }

    /**
     * Same as transactionsUrl(), or null when the viewer cannot open reports.
     */
    public function transactionsUrlForViewer(Contact|int $contact, Company $company, ?User $viewer): ?string
    {
        return $this->viewerCanReach('reports.transactions', $company, $viewer)
            ? $this->transactionsUrl($contact, $company)
            : null;
    }

    /**
     * Whether a viewer may open a company route, judged by the sections that
     * gate it: the section must be enabled for the company (site-admin kill
     * switch) AND the viewer must hold it — any one of the route's sections
     * suffices, mirroring EnsureSectionEnabled + EnsureSectionAccess (the same
     * pair SidebarNavCatalog checks before rendering a nav link). Memoised per
     * viewer/company/route because canAccessSection() queries memberships on
     * every call and index pages resolve one link per row.
     */
    public function viewerCanReach(string $routeName, Company $company, ?User $viewer): bool
    {
        $sections = Section::forRouteName($routeName);

        if ($sections === []) {
            return true;
        }

        if ($viewer === null) {
            return false;
        }

        $key = $viewer->getKey().':'.$company->getKey().':'.$routeName;

        return $this->reachable[$key] ??= collect($sections)->contains(
            fn (Section $section) => $company->sectionEnabled($section) && $viewer->canAccessSection($company, $section),
        );
    }

    /**
     * Every role the contact holds, as badge label + Flux colour, in display order.
     *
     * @return list<array{label: string, color: string}>
     */
    public function roleLabels(Contact $contact): array
    {
        $labels = [];

        if ($contact->is_customer) {
            $labels[] = ['label' => __('Customer'), 'color' => 'green'];
        }

        if ($contact->is_vendor) {
            $labels[] = ['label' => __('Vendor'), 'color' => 'blue'];
        }

        if ($contact->is_employee) {
            $labels[] = ['label' => __('Employee'), 'color' => 'zinc'];
        }

        if ($contact->is_other_name) {
            $labels[] = ['label' => __('Other name'), 'color' => 'violet'];
        }

        return $labels;
    }

    /**
     * Lower-case, comma-joined role summary ("customer, vendor"), or "contact".
     */
    public function roleLabel(Contact $contact): string
    {
        $roles = array_map(fn (array $role) => mb_strtolower($role['label']), $this->roleLabels($contact));

        return implode(', ', $roles) ?: 'contact';
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function target(Contact $contact, Company $company): array
    {
        if ($contact->is_customer) {
            return ['reports.contact-statement', ['company' => $company->slug, 'contact' => $contact->id, 'kind' => 'ar']];
        }

        if ($contact->is_vendor) {
            return ['reports.contact-statement', ['company' => $company->slug, 'contact' => $contact->id, 'kind' => 'ap']];
        }

        if ($contact->is_employee) {
            return ['employees.index', ['company' => $company->slug, 'edit' => $contact->id]];
        }

        return ['reports.transactions', $this->transactionsParams($contact, $company)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function transactionsParams(Contact|int $contact, Company $company): array
    {
        [$start, $end] = ReportDatePresets::resolve('all', (int) ($company->fiscal_year_start_month ?: 1), $company->currentDateTime());

        return [
            'company' => $company->slug,
            'contact' => $contact instanceof Contact ? $contact->id : $contact,
            'range' => 'all',
            'start' => $start->toDateString(),
            'end' => $end->toDateString(),
        ];
    }
}
