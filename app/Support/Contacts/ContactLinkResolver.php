<?php

namespace App\Support\Contacts;

use App\Enums\Section;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Support\Access\RouteGate;
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
        return route('reports.transactions', $this->allTimeParams($contact, $company));
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
     * The Vendor Activity report for one vendor over an all-time range — every
     * posted transaction with them, including cheques and expenses that never
     * touch Accounts Payable and so never reach their AP statement.
     */
    public function vendorActivityUrl(Contact|int $contact, Company $company): string
    {
        return route('reports.vendor-activity', $this->allTimeParams($contact, $company));
    }

    /**
     * Same as vendorActivityUrl(), or null when the viewer cannot open reports.
     */
    public function vendorActivityUrlForViewer(Contact|int $contact, Company $company, ?User $viewer): ?string
    {
        return $this->viewerCanReach('reports.vendor-activity', $company, $viewer)
            ? $this->vendorActivityUrl($contact, $company)
            : null;
    }

    /**
     * Whether a viewer may open a company route ({@see RouteGate}). Memoised
     * per viewer/company/route because index pages resolve one link per row.
     */
    public function viewerCanReach(string $routeName, Company $company, ?User $viewer): bool
    {
        $key = ($viewer?->getKey() ?? 0).':'.$company->getKey().':'.$routeName;

        return $this->reachable[$key] ??= RouteGate::reaches($routeName, $company, $viewer);
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

        return ['reports.transactions', $this->allTimeParams($contact, $company)];
    }

    /**
     * Route params filtering a period report to one contact over an all-time
     * range; shared by the Transactions and Vendor Activity reports.
     *
     * @return array<string, mixed>
     */
    protected function allTimeParams(Contact|int $contact, Company $company): array
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
