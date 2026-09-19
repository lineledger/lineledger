# TODOS

## Deployment

### Stand up the US app deployment at books.lineledger.com

**What:** A second Forge site with its own database, queue worker, and scheduler.

**Why:** The app ships with a two-region model, and the country-switcher banner links
guests to `APP_URL_US`. Until that host exists the banner is a dead link.

**Context:** The application side is already done — `APP_REGION`, `APP_URL_CA`, and
`APP_URL_US` all ship in `.env.example` and the banner reads them. What's left is
purely the Forge site. Set `APP_URL=https://books.lineledger.com` and leave `APP_REGION` unset so
the region derives from the host (a `.ca` host resolves to Canada, anything else to the
US). It needs its own `DB_*`, its own Stripe webhook endpoint, and its own SSL. Canada
is the primary market, so this is optional-but-linked: if you'd rather defer it, point
`APP_URL_US` at the Canadian host instead so the banner degrades gracefully. Full env
table is in README → Environment.

**Effort:** M
**Priority:** P1
**Depends on:** None

### Point both marketing builds at one feature-requests database

**What:** Build the Astro site twice (`PUBLIC_REGION=ca` and `PUBLIC_REGION=us`) with
both deployments reading the same `DB_NAME`.

**Why:** The public feature-request board carries votes and IDs. Two regional databases
would let IDs collide and split votes across the two sites.

**Context:** One shared database keeps IDs unique and votes unified. This lives in the
`lineledger-site` repo, which already has CI covering both region builds.

**Effort:** S
**Priority:** P1
**Depends on:** None

### Move uploads and backups to object storage on the hosted deployments

**What:** Create the two S3 buckets, then set `ATTACHMENT_DISK=s3`, `LOGO_DISK=s3_public`,
and `BACKUP_DISK=s3` on each hosted site.

**Why:** The capability shipped but isn't switched on. Every hosted deployment still
writes attachments, logos, and backups to the release directory's `storage/app`, so a
deploy that swaps the release directory loses them, and a second app server would never
see the first one's uploads. This is the failure mode the feature was built to prevent.

**Context:** Two buckets, because logos must be publicly readable and attachments and
backups must not: `AWS_BUCKET` (private) and `AWS_PUBLIC_BUCKET` + `AWS_PUBLIC_URL`
(public-read). Keep each region's bucket in the country it serves — `ca-central-1` for
the Canadian site. The three disk roles are independent, so you can move logos first and
leave backups local if you want to stage it. **Run `php artisan storage:check` after
switching** — it round-trips an object through each disk and then fetches it
unauthenticated to prove the public/private split is actually right. Existing files keep
resolving from their recorded per-row disk, so no backfill is needed; only new uploads
land on S3.

**Effort:** S
**Priority:** P1
**Depends on:** None

## Documentation

### Sync the marketing-site docs mirror to 1.1.0

**What:** Bring `~/Code/lineledger-site` up to date with the in-app docs: the 29 pages under
`src/content/docs`, the `public/images/docs` screenshots, the three tutorials, and the
`src/config/docs-nav.ts` grouping, now that the in-app docs nav mirrors the app sidebar.

**Why:** The site was last synced 2026-08-15 and already contradicts the app — pages, nav
order, and screenshots have all moved since.

**Context:** The in-app docs are the source of truth (`resources/views/pages/docs/`, screenshots
in `public/docs/screenshots/<page>/`). Mirror them page by page rather than rewriting; the site's
nav grouping should follow the app sidebar order the in-app nav now uses.

**Effort:** M
**Priority:** P1
**Depends on:** None

## Testing

### End-to-end test for the guest country-switcher banner

**What:** A browser test that loads a page on a `.ca` host as a guest, asserts the
banner offers the US site, and follows the link to `APP_URL_US`.

**Why:** The banner is the only cross-region navigation and it is currently covered
only at the unit level (`Country::fromHost`). The rendering, the `ll_region` cookie, the
dismiss action, and the cross-host link are untested.

**Context:** Flagged during the pre-launch audit as the region split's untested path.
Needs the second deployment, or a host-header stub, to assert the
cross-origin link meaningfully. The banner and its Alpine block are
`resources/views/components/geo-banner.blade.php` and the `geoBanner` component in
`resources/js/app.js`.

**Effort:** M
**Priority:** P2
**Depends on:** Stand up the US app deployment at books.lineledger.com

### Add the new in-app docs pages to the marketing site's docs-nav slug test

**What:** The marketing site's docs-nav test enforces slug pairing between its nav and its
content pages, so each new in-app docs page — `opening-balances`, `insights`,
`employee-portal`, `site-administration`, `self-hosting` — must be added there (nav entry +
content page) or that suite fails once the mirror is synced.

**Why:** Without the pairing, the site build passes while the new pages are silently
unreachable from its nav.

**Effort:** S
**Priority:** P2
**Depends on:** Sync the marketing-site docs mirror to 1.1.0

## Completed

## Known issues found during the 1.1.0 docs audit

### Template-based import steps can't read a Windows-1252 CSV

**What:** The Items, Open invoices (template layout), Open bills, Inventory on hand, Fixed
assets and Trial balance steps read the file byte for byte through `CsvParser`, which does no
encoding conversion and does not strip a UTF-8 byte-order mark. Excel's plain "CSV" on
Windows is Windows-1252, so accented names (Café, Montréal) arrive as invalid UTF-8; Excel's
"CSV UTF-8" adds a BOM that hides the first column.

**Why:** The QuickBooks-export readers (chart of accounts, contacts, open invoices in the QB
layout) already convert Windows-1252; the template steps should behave the same. The docs
currently tell users to export from Google Sheets or LibreOffice instead.

**Effort:** S · **Priority:** P2 · **Depends on:** None

### A copied 2210 account gets the new organization's provincial agency

**What:** When the setup wizard copies another organization's chart, `seedProvincialSalesTax()`
attaches the NEW organization's provincial agency and tax code to any non-system tax-payable
account coded 2210, whatever tax it was for (e.g. a BC "PST Payable" copied into a Manitoba
organization gets Manitoba RST).

**Why:** The account name and the agency then disagree. Either rename the account to match or
skip the provincial seeding when the copied account's tax does not match the new province.

**Effort:** S · **Priority:** P3 · **Depends on:** None
