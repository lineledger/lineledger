# TODOS

## Deployment

### Stand up the US app deployment at books.lineledger.com

**What:** A second Forge site with its own database, queue worker, and scheduler.

**Why:** The app ships with a multi-region model, and the country-switcher banner links
guests to `APP_URL_US`. Until that host exists the banner is a dead link.

**Context:** The application side is already done — `APP_REGION`, `APP_URL_CA`,
`APP_URL_US`, and `APP_URL_GB` all ship in `.env.example` and the banner reads them.
What's left is purely the Forge site. Set `APP_URL=https://books.lineledger.com` and
leave `APP_REGION` unset so the region derives from the host (`.ca` → Canada, `.uk` →
United Kingdom, otherwise US). It needs its own `DB_*`, its own Stripe webhook
endpoint, and its own SSL. Canada is the primary market, so this is
optional-but-linked: if you'd rather defer it, point `APP_URL_US` at the Canadian
host instead so the banner degrades gracefully. Full env table is in README →
Environment.

**Effort:** M
**Priority:** P1
**Depends on:** None

### Stand up the UK app deployment at books.lineledger.co.uk

**What:** A third Forge site with its own database, queue worker, and scheduler,
plus a marketing twin at `lineledger.co.uk`.

**Why:** The application side already supports `Country::UnitedKingdom` (`GB`),
`BritishDefaults` (VAT / HMRC / GBP), and a three-sibling country switcher. Until
the `.co.uk` host exists, UK visitors stay on the CA/US twins.

**Context:** Set `APP_URL=https://books.lineledger.co.uk` and leave `APP_REGION`
unset (host derives GB). Own `DB_*`, Stripe Connect + webhook for GBP, and object
storage in `eu-west-2`. Put `APP_URL_GB` / `MARKETING_URL_GB` on **all three**
app hosts so the banner can link every direction. Point
`MARKETING_URL_GB=https://lineledger.co.uk` once the marketing twin exists.

**Effort:** M
**Priority:** P1
**Depends on:** None

### Point both marketing builds at one feature-requests database

**What:** Build the Astro site twice (`PUBLIC_REGION=ca` and `PUBLIC_REGION=us`) with
both deployments reading the same `DB_NAME`.

**Why:** The public feature-request board carries votes and IDs. Two regional databases
would let IDs collide and split votes across the two sites.

**Context:** One shared database keeps IDs unique and votes unified. This lives in the
`lineledger-site` repo, which already has CI covering both region builds. When the
UK marketing twin lands, add a `PUBLIC_REGION=gb` build against the same shared DB.

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

### Sync the marketing-site docs mirror to 1.2.0

**What:** Bring `~/Code/lineledger-site` up to date with the in-app docs (36 pages as of
1.2.0, including the new Languages and United Kingdom & VAT pages): the pages under
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
banner offers the US and UK sibling sites, and follows a link to `APP_URL_US`
(or `APP_URL_GB`).

**Why:** The country-switcher is guest-facing and currently covered
only at the unit level (`Country::fromHost`). The rendering, the `ll_region` cookie, the
referrer short-circuit, and the cross-origin handoff are all untested end-to-end.

**Context:** Feature coverage already lives in `tests/Feature/BrandingTest.php` for
`resources/views/components/geo-banner.blade.php` and the `geoBanner` component in

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
`employee-portal`, `site-administration`, `self-hosting`, and in 1.2.0 `languages` and
`united-kingdom` — must be added there (nav entry +
content page) or that suite fails once the mirror is synced.

**Why:** Without the pairing, the site build passes while the new pages are silently
unreachable from its nav.

**Effort:** S
**Priority:** P2
**Depends on:** Sync the marketing-site docs mirror to 1.2.0

## Completed

## Known limitations in 1.2.0

Documented in the in-app docs (Languages, United Kingdom & VAT) and the CHANGELOG; listed
here so they are picked up deliberately rather than rediscovered.

### Report PDFs are English-only

**What:** 33 of the 39 templates in `resources/views/pdf/reports/` (cash flow, general
ledger, trial balance, sales tax, T4, PD7A, the Revenu Québec remittance, …) hard-code
English, as do `pdf/reconciliations/show.blade.php` and `pdf/donations/receipt.blade.php`.
The on-screen reports are translated; their PDF exports are not.

**Why:** A French-speaking organization prints mixed-language reports.

**Context:** Wrap the strings in `__()` and add `lang/fr.json` entries; `ReportCopy` already
registers report names. Date formats should go through `Locales::formatDate()`.

**Effort:** M · **Priority:** P2 · **Depends on:** None

### Amounts are not localized in French

**What:** Most amounts on screen and on printed documents go through `number_format()` and
keep the `1,234.56` / `$` prefix style in French; only the ~24 views that call
`Money::format()` print the `fr_CA` form (`1 234,56 $`). A French tax line also mixes styles
("TPS (5 %) 5.00%").

**Context:** Route display amounts through `Money::format()` (or a Blade helper around it),
starting with the customer-facing PDFs in `resources/views/pdf/`.

**Effort:** M · **Priority:** P3 · **Depends on:** None

### Vendors have no document language

**What:** The customer form has a Language field; the vendor form doesn't, so purchase
orders, cheques and other vendor documents always use the organization's language.

**Effort:** S · **Priority:** P3 · **Depends on:** None

### UK payroll, Making Tax Digital and Gift Aid

**What:** Payroll is Canada-only (`JurisdictionProfile`), the VAT Return is worked out but
not submitted to HMRC (copy the boxes into MTD software), and Charity is not offered
outside Canada, so there are no Gift Aid receipts.

**Effort:** L each · **Priority:** P3 · **Depends on:** Demand from UK users

### ICU locale is pinned to Canada

**What:** `Locales::icu()` maps `en` → `en_CA` and `fr` → `fr_CA` for every organization, so
a UK organization's number formatting follows Canadian conventions where the two differ.
Currency symbols and day-first document dates are already per-country.

**Effort:** S · **Priority:** P3 · **Depends on:** None

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
