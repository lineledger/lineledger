<?php

namespace App\Contracts;

use App\Models\Company;
use App\Support\Access\DefaultCompanyAccess;

/**
 * Whether an organization may still change its books, and what to tell its
 * members. The app binds {@see DefaultCompanyAccess} (never read-only, no
 * notice); a hosting layer binds its own, for example to freeze an
 * organization whose subscription has lapsed.
 *
 * A read-only organization stays fully readable:
 *  - every member, the Owner included, resolves to view-only permissions
 *    (PermissionResolver), so pages, Livewire methods, /api/v1 and MCP refuse
 *    anything that writes;
 *  - JournalPoster refuses to post, void or repost, as a backstop (a Stripe
 *    card payment that already settled is still recorded);
 *  - the per-organization scheduled jobs and inbound email skip it.
 * The Owner can still export a backup and delete the organization, and every
 * member can still raise a support ticket and manage their own account.
 */
interface CompanyAccess
{
    public function isReadOnly(Company $company): bool;

    /**
     * The notice shown across the top of the organization's pages, or null for
     * none. Plain text: it is escaped when shown. Independent of
     * isReadOnly(), so it can also warn ahead of a change.
     */
    public function readOnlyNotice(Company $company): ?string;
}
