# LineLedger

Free, open-source double-entry accounting — a web application
built with [Laravel 13](https://laravel.com), [Livewire 4](https://livewire.laravel.com),
and [Flux UI](https://fluxui.dev) (Free) on [Tailwind CSS v4](https://tailwindcss.com).

Multi-tenant by design: every user belongs to one or more **organizations**, each
with its own chart of accounts, books, branding, and team. (The UI says
"organization"; the model, routes, and `{company}` route param are still named
`company` in code.) The general ledger is
the source of truth — every posting document (invoice, bill, receipt, cheque,
deposit) writes balanced journal entries, and the reports read straight back off
the GL.

## Status

LineLedger is at **1.1.0**, released September 18, 2026; 1.0.0 shipped August 15, 2026
(see [`CHANGELOG.md`](CHANGELOG.md)). Running an existing install? Read
[`UPGRADING.md`](UPGRADING.md) before you pull — it covers the backup-first,
`php artisan app:upgrade` path for both Docker and Forge, and the same material is in
the in-app docs under **Self-hosting & upgrades** (`/docs/self-hosting`, once signed
in). The code is open source (AGPLv3) for transparency and to share how a real
Laravel SaaS-style app is structured.
**This repository is not actively soliciting external contributions or issues**
— you're welcome to read, learn, and fork under the
terms of the [license](#license) below. The software is provided **as is, used at
your own risk, and is not a substitute for professional accounting or tax
advice** — please read the [Disclaimer & no warranty](#disclaimer--no-warranty).

## What's in here

- **General ledger** — chart of accounts (with drill-through to each account's
  ledger), manual journal entries and journal-entry templates, recurring journal
  entries, trial balance, and class/location dimensions tracked on every
  transaction line.
- **Opening balances** — an owner-only workspace at `/{company}/opening-balances`
  for an organization that starts without an import: a draft trial balance (CSV
  import or an editable grid) held as targets, per-customer opening AR and
  per-vendor opening AP as real opening documents you can name after the old
  books, outstanding cheques and deposits in transit posted at their original
  dates, plus inventory and fixed-asset imports. Nothing locks — one opening journal
  entry follows every save and lands the as-of ledger exactly on the targets.
- **Accounts receivable** — customers, estimates, sales orders, invoices, credit
  memos, customer receipts, AR aging, open-credit netting, and **customer
  statements**: open invoices as of a date, or account activity over a period,
  rendered in the invoice's customer-facing PDF style with an aging strip and a memo
  column — view, download, or email with the PDF attached and a portal link, from the
  customers list or the AR statement report. Sales reps on invoices and credit memos
  (also via the API).
- **Accounts payable** — vendors, purchase orders, bills, bill payments, vendor
  credits, AP aging, 1099 tracking (US), and a **Vendor Activity** report listing
  every posted transaction with a vendor — bills, payments, credits, cheques, expenses
  and journal entries — with the vendor list's name and balance drilling separately
  into activity and the AP statement.
- **Cheques** — cheque printing calibrated to Intuit pre-printed voucher stock
  (every field re-measured to a baseline in `config/cheque.php`, a YYYYMMDD date comb,
  and a printed payee mailing address block for window envelopes), a linked payee
  picker across vendors, customers, employees and Other names, duplicate-a-cheque,
  repeatable cheque numbers ("EFT", "DD"), per-line customer or vendor on a line that
  settles a receivable or payable (never taxed twice), and editing a posted cheque,
  which reposts the same journal entry in place.
- **Other names** — QuickBooks-style payees that are not a vendor, customer, or
  employee. Managed in **Settings → Lists → Other names**, with a one-way conversion
  to any of the three roles.
- **Banking** — a read-only bank register that shows each document's own memo,
  deposits (an undeposited-receipts picker with select-all, sortable columns and
  PDF/CSV/XLSX export), transfers, bank reconciliation worksheets, and bank-statement
  import (CSV, Excel, OFX/QFX/QBO, PDF) with rules, payee and tax-code suggestions,
  bill matching and a For Review queue. A cash-flow forecast reads off the same data.
- **Recurring documents** — recurring invoices, bills, and journal entries generated
  as **drafts** by the scheduler, on a fixed day of the month, the last day, or the
  last business day (the same anchors drive scheduled report emails).
- **Inventory** — items, FIFO/average costing, stock adjustments, received-stock
  on bill posting.
- **Fixed assets** — asset categories, depreciation, and a nightly job that drafts
  the period's depreciation entries.
- **Payroll** *(Canada)* — employees, pay schedules, and pay runs with CPP / EI /
  income-tax calculation (CRA T4127), full **Quebec** support (QPP / QPP2 / QPIP /
  EI-QC + federal abatement and Revenu Québec provincial tax), payroll cheques, a
  PD7A remittance worksheet, and T4 / T4A / RL-1 / ROE filings with CRA- and Revenu
  Québec-ready XML. Rates need a yearly refresh — see
  [Operating & maintenance](#operating--maintenance).
- **Time tracking & time off** — time entries, time-off policies with automatic
  accrual, and employee time-off requests.
- **Employee self-serve portal** — magic-link portal at `/my-pay/{company}` where
  employees see pay stubs, download T4 / RL-1 slips, and submit time and time-off
  requests.
- **Employee reimbursements** — expense claims that post to the employee's payable.
- **Multi-currency** — home-cents GL with foreign memo columns, daily exchange
  rate fetching, realized + unrealized gain/loss.
- **Budgeting** — account-level budgets with budget-vs-actual, overview, and
  by-month reports; build from prior-year actuals.
- **Non-profit & fund accounting** *(org-type gated)* — net-asset subtypes,
  ASNPO statements, restricted funds with interfund transfers, grant tracking with a
  grants-summary report, and donation receipts (T3010).
- **Customer payment portal** — magic-link portal at `/pay/{company}` with
  Stripe Connect card payments.
- **Reporting** — income statement, balance sheet, cash flow statement, AR/AP
  aging, sales/purchases/inventory reports, a sales-by-rep drill-down (per-rep
  breakdown by revenue account with prior-period comparison, and a Rep column on the
  internal AR statement), Vendor Activity, management report packages, combined
  multi-organization report groups (with a per-line cash-flow activity override),
  custom report sections, QuickZoom drill-through, memorized reports and favorites,
  and scheduled report emails. Exports are stamped in the organization's timezone.
- **Daily insights** — a per-organization "Did you know?" dashboard card computed
  nightly, with a browsable history and a profit-insights report.
- **Payment reminders** — per-customer opt-in dunning that emails overdue invoices
  every morning.
- **Tax** — sales-tax codes, sales-tax returns, and GIFI Schedule 100/125
  statements *(Canada)*.
- **Documents** — a per-organization document repository (folders + private-by-default
  sharing) plus a unified transaction-attachment index.
- **Document inbox** — drop receipts and bills in by drag-and-drop or by forwarding
  them to a per-organization email address, with optional OCR extraction, then turn
  them into bills or expenses.
- **Multi-tenancy & RBAC** — guided organization onboarding, per-member section
  access (Owner / Admin / Accountant / Custom), invitations.
- **Edit locks** — one member edits a record at a time. Opening a record to edit
  takes a short lease; other members see who is editing instead of the form, show-page
  and list actions wait, and Owners/Admins can take over. The open page renews the
  lease by heartbeat, releases it on leave, lets it expire about two minutes after the
  tab closes, and pauses it after 15 minutes idle. A save is refused if the lease was
  lost or the record changed underneath, and the API answers `423` with `Retry-After`
  while a record is held. Tunable (or off) with `EDIT_LOCKS_*` — see
  [Optional subsystems](#optional-subsystems).
- **Site admin portal** — a platform-operator area at `/admin` (site admins only,
  2FA + password re-challenge): every user and organization, platform-wide account
  disable, organization soft-delete / restore / permanent purge, support-ticket
  triage, and site-wide maintenance-mode and registrations-open switches. The first
  user to register on a fresh install becomes the site admin.
- **Support tickets** — in-app tickets users raise and site admins answer.
- **Navigation & keyboard ergonomics** — per-user sidebar preferences (Settings →
  Navigation, backed by the `nav_preferences` table); **Escape goes back** to the
  previous page (per-user switch in Settings → Appearance; open modals, dropdowns and
  pickers still close first); a click anywhere in a date field opens the calendar; an
  in-cell amount calculator that understands `+ − × ÷`; the company switcher opens the
  chosen organization in a new tab; and the banking screens remember the last account
  you worked in.
- **Auth** — Laravel Fortify with passkeys (WebAuthn) and two-factor, with an
  optional organization-wide 2FA requirement (`require_two_factor`) that makes
  owners/admins enrol before they can use the app, an optional Cloudflare Turnstile
  challenge on the public auth forms, and a per-IP registration throttle. Removing a
  member or downgrading their role immediately tears down their sessions, company API
  keys, and OAuth tokens (`AccessRevoker`).
- **REST API v1** — versioned `/api/v1` endpoints with API-key abilities/scopes
  and full audit logging (see [`docs/api-v1.md`](docs/api-v1.md)). 1.1.0 adds invoice
  `balance_cents`, receipt `unapplied_cents`, negative invoice lines, `sales_rep_id`,
  and the `423` edit-lock response.
- **Business Q&A (MCP)** — a read-only [Model Context Protocol](https://modelcontextprotocol.io)
  server (20 reporting tools, 7 resources, 4 prompts) that answers plain-language
  finance questions from any MCP-capable AI assistant over an API key or one-click
  OAuth. Sales reps are exposed to the sales report and invoice listing.
- **Agentic MCP writes** *(off by default)* — a second MCP server that can draft
  invoices, bills, expenses, and journal entries through a **propose → confirm**
  handshake: the assistant stages a proposal, a human confirms it, and only then does
  it post. Requires **both** the operator switch `MCP_WRITE_ENABLED=true` and the
  per-organization **AI assistant writes (MCP)** switch on the organization's edit
  page, and every write goes through the same actions, permission checks, and audit
  log as the UI.
- **Data migration** — guided QuickBooks import: full-history general-ledger
  replay or an opening trial balance, with account mapping. Anything left over after
  setup goes through the opening-balances workspace above.
- **Audit log** — every accounting mutation is recorded, including which API key
  (if any) made the change.
- **Backup & restore** — export an organization to a portable ZIP and restore it into
  a brand-new organization.
- **PDFs** — invoices, statements, and cheques via DomPDF + TCPDF.
- **Public verification page** — `/verification` runs end-to-end accounting proofs
  (multi-year close, imported trial balance, QuickBooks journal import) on real
  seeded data, with downloadable source data and reports; see
  [Verification](#verification).

## Tech stack

| Layer | Choice |
| --- | --- |
| Runtime | PHP 8.5+ |
| Framework | Laravel 13 (`laravel/framework` 13.32 as of 1.1.0) |
| UI | Livewire 4 + Flux UI Free + Tailwind CSS 4 (Vite) |
| Charts | Chart.js 4 (`chart.js`) for the dashboard and report chart panels (`resources/js/charts.js`) |
| Auth | Laravel Fortify + passkeys (WebAuthn, configured in `config/fortify.php`); OAuth2 via Laravel Passport (MCP); optional Cloudflare Turnstile on the auth forms (`config/turnstile.php`) |
| Database | MySQL 8 (local) / SQLite (CI) |
| Queue & cache | database driver by default (Redis-ready) |
| Object storage | `league/flysystem-aws-s3-v3` — S3 or any S3-compatible store (MinIO, R2, B2) for attachments, logos and backups; local disks by default. See [Object storage](#object-storage) |
| Payments | Stripe Connect — an organization's own customers pay its invoices by card, via `stripe/stripe-php` |
| AI / MCP | Laravel MCP server (`laravel/mcp`) — read-only Q&A tools + an opt-in write server |
| AI (in-app) | Optional and off by default, all behind `ANTHROPIC_API_KEY`: document-inbox OCR, AI daily insights, the bank-import PDF fallback, and the transaction-classification fallback. See [Optional subsystems](#optional-subsystems) |
| Mail | Any Laravel mailer; Resend bundled (`resend/resend-php`), `log` by default |
| PDFs | `barryvdh/laravel-dompdf` + `tecnickcom/tcpdf` + `setasign/fpdi` (slip templates) |
| PDF text extraction | poppler `pdftotext` when installed, else the bundled pure-PHP `smalot/pdfparser` (bank-statement import) |
| Spreadsheets | `openspout/openspout` (XLSX export) |
| Observability | Laravel Nightwatch (`laravel/nightwatch`), opt-in via `NIGHTWATCH_ENABLED` |
| Tests | Pest 4 / PHPUnit 12 (PHP) + Node's built-in test runner (`tests/js/`) |
| Static analysis | PHPStan / Larastan (baselined, runs in CI) |
| Tooling | Laravel Pint, Laravel Boost (MCP) |

## Develop

You need PHP 8.5, Node 22+ (the JS tests use Node's built-in runner), and MySQL 8.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed     # dev/QA only — seeds a demo site admin (test@example.com / password); refuses to run in production
php artisan storage:link       # serve uploaded logos / attachments
composer run dev               # server + queue worker + pail + vite, all in one
```

`composer run dev` runs four processes under one command via `concurrently`:
the PHP dev server, the queue worker (`queue:listen`), the log tailer (`pail`),
and Vite. The app is then at <http://localhost:8000>.

> `composer run setup` does the same first steps non-interactively (install, key
> generate, migrate, npm build) but **without `--seed`** — it's meant for a clean
> install, not the demo data. Use the block above if you want the seeded login.

> **Demo organizations.** `migrate --seed` creates only the site-admin login. The
> data set every in-app docs screenshot was captured against — **Demo Company Inc.**
> and **Demo Community Society** (a registered charity, for the non-profit flows) —
> comes from a standalone, idempotent seeder:
> `php artisan db:seed --class=DemoCompanySeeder`. Re-running it wipes and rebuilds
> both organizations, so screenshots can be regenerated at will.

> **Heads-up:** the queue worker matters in dev too — backups, restores,
> recurring-document generation, document-inbox processing, and emails are queued
> jobs. Without a worker running they'll sit in the `jobs` table. (Bank-statement
> imports are not — they run inline in the request.)

| Command | Action |
| --- | --- |
| `composer run dev` | Server + queue + logs + Vite together (recommended) |
| `php artisan serve` | PHP dev server only |
| `npm run dev` | Vite dev server with hot reload |
| `npm run build` | Build front-end assets to `public/build/` |
| `composer run test` | Clear config → Pint check → `npm test` → full Pest suite (what CI runs) |
| `php artisan test --compact` | Run the PHP test suite |
| `npm test` | JS unit tests on Node's built-in runner (`tests/js/`: amount-expression parser, date picker, edit-lock, escape-back) |
| `vendor/bin/pint --dirty` | Auto-format changed PHP files |

### Bank-statement PDF import (poppler)

Importing a bank statement as **PDF** needs [poppler](https://poppler.freedesktop.org/)'s
`pdftotext` for text extraction:

- macOS: `brew install poppler`
- Debian/Ubuntu: `sudo apt-get install -y poppler-utils`

CSV, Excel and OFX/QFX/QBO imports need nothing extra. The importer reads a PDF in
two tiers: first `pdftotext` (a pure-PHP `smalot/pdfparser` fallback is bundled, but
it can't read secured/encrypted PDFs), then — when text extraction comes up empty
*and* the optional AI layer is on — it sends the PDF to the Anthropic API, whose
model reads secured and scanned PDFs natively. Enable the AI fallback with
`BANK_IMPORT_AI_ENABLED=true` and an `ANTHROPIC_API_KEY` (see the
[Environment](#environment) table). With neither `pdftotext` nor AI available, PDF
import asks the user for a CSV/OFX export instead.

## Project structure

A standard Laravel layout; the accounting-specific logic lives in a few places:

- **`resources/views/pages/`** — the UI. Every page is a Livewire 4 **Volt
  single-file component** (`⚡name.blade.php`, 280+ of them) registered with
  `Route::livewire()`. Forms, lists, detail pages, and reports all live here.
  (`app/Livewire/` holds only a dozen support classes, not the pages: the
  `BindCurrentCompanyHook`, `Logout`, and the shared form concerns — the edit-lock
  contract every form page honours (`#[GuardsEditLock]`, `HoldsEditLock`,
  `ShowsEditLock`, `GuardsEditLockedForm`), `InteractsWithOpeningBalances`,
  `ManagesPayeeCombo`, `ManagesLineContacts`, and the statement-line concerns.)
- **`resources/js/`** — the front-end behaviour modules `app.js` imports:
  `escape-back.js`, `date-picker.js`, `edit-lock.js` (lease heartbeat + release),
  `amount-expression.js` (the in-cell calculator parser), `charts.js`, and
  `passkeys.js`. The first four each have a suite under `tests/js/`.
- **`app/Actions/`** — shared write paths invoked by both Livewire and the API
  (`SaveInvoice`, `SaveBill`, `FulfillSalesOrder`, …). The API controllers and
  the UI call the *same* action so behavior never diverges.
- **`app/Services/`** — 30 domain engines: `Posting/` (journal-entry posting),
  `Accounting/`, `OpeningBalances/` (the one-opening-entry synchronizer),
  `EditLocks/` (`EditLockManager`, compare-and-set leases), `Reporting/`,
  `Reconciliation/`, `Inventory/`, `Currency/`, `Tax/`, `Payroll/` (CRA deduction
  engine), `Migration/` (QuickBooks import), `Banking/`, `Inbox/`, `Insights/`,
  `Classification/`, `Recurring/`, `Reminders/`, `Printing/`, `Pdf/`, `Proof/`,
  `Backup/`, `Restore/`, `Stripe/`, `Security/`, `Audit/`, and more.
- **`app/Mcp/`** — both MCP servers: `BusinessQaServer` (read-only reporting tools)
  and `BusinessActionsServer` (the opt-in propose→confirm write tools).
- **`app/Models/`** — Eloquent models. The GL core is `JournalEntry` /
  `JournalLine` (posting state is denormalized onto lines for fast balance sums);
  documents (`Invoice`, `Bill`, `Cheque`, …) own their lines and post through the
  services.
- **`app/Enums/`** — typed domain vocabulary (`AccountType`, `InvoiceStatus`,
  `CompanyRole`, `Section`, `ApiAbility`, `Country`, …).
- **`routes/`** — `web.php` (tenant-scoped UI under `{company}`, plus a handful of
  deliberately unscoped routes such as the company switcher and `/health/fx`),
  `api.php` (`/api/v1`), `ai.php` (MCP servers), `admin.php` (the site-admin portal,
  which is deliberately *not* tenant-scoped), `docs.php` (the in-app docs),
  `settings.php`, `console.php` (the scheduler).
- **`database/migrations/`** — schema (290+ migrations); `database/seeders/`
  has `DemoCompanySeeder`, which builds two sample organizations: a for-profit demo and
  **Demo Community Society**, a registered charity for the non-profit / fundraising docs.
- **`tests/`** — Pest feature + unit tests, plus `tests/js/` for the JS modules. CI
  runs the PHP suite against SQLite.

## Environment

`.env.example` ships sensible local defaults (MySQL, database queue/cache,
`MAIL_MAILER=log`). The marketing/auth flows work out of the box; the keys below
unlock specific subsystems. Set the same keys in production via Forge → Site →
**Environment**.

| Key | Purpose | Local | Production |
| --- | --- | --- | --- |
| `APP_NAME` | App name shown in UI & emails | `LineLedger` | `LineLedger` |
| `APP_URL` | Absolute base URL (links, passkey RP ID, Stripe redirects) | `http://localhost:8000` | `https://books.lineledger.ca` |
| `APP_KEY` | Encryption key | `php artisan key:generate` | generated once, kept secret |
| `APP_REGION` | Country this deployment serves (`CA` / `US`). Picks which marketing site the footer's legal links point at. Leave **unset in production** to derive it from the request host (a `.ca` host → CA, anything else → US); set it explicitly on host-agnostic environments like local dev | `CA` (`.env.example` ships it blank) | unset (derived from host) |
| `APP_URL_CA` / `APP_URL_US` | The project's two sibling app deployments. The guest country-switcher banner offers a visitor the other one, and **renders only on these two hosts** — so a self-hosted instance on its own domain never shows it, whatever `APP_REGION` says | `https://books.lineledger.ca` / `https://books.lineledger.com` | your two app hosts |
| `MARKETING_URL_CA` / `MARKETING_URL_US` | Marketing sites hosting the legal documents linked from the footer, chosen by region | `https://lineledger.ca` / `https://lineledger.com` | your marketing sites |
| `BRAND_LOGO` / `BRAND_LOGO_DARK` | The mark on the sign-in, registration and onboarding screens, and in the sidebar until an organization uploads its own. Any path the browser can fetch: a file under `public/`, a `/storage/…` path (which survives a deploy), or an absolute URL. The dark variant is optional — unset, the one logo serves both themes. The name beside it is `APP_NAME` | `/logo/line-ledger-logo.png` / unset | your logo |
| `BRAND_FOOTER_OWNER` | Who operates this deployment — the name in the footer's copyright line | `Local Foundry Inc.` | your company |
| `BRAND_SHOW_PROJECT_LINKS` | The upstream links beside it (version → release notes, licence, source, legal). `false` keeps the copyright line and a plain version number. **Read `config/brand.php` first**: the source link is how this app offers its source to the people using it, which AGPL-3.0 §13 requires of a modified version run as a network service | `true` | `true` |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL connection | `mysql` @ `127.0.0.1:3306`, db `lineledger` | the DB + user you create in Forge → **Database** |
| `QUEUE_CONNECTION` | Queue driver (backups, restores, recurring, mail) | `database` | `database` or `redis` |
| `DB_QUEUE_RETRY_AFTER` / `REDIS_QUEUE_RETRY_AFTER` | Seconds before a reserved job is handed to another worker (default 90). Must exceed the worker's `--timeout` **and** the longest job — exports/restores run up to 1800 s, the QuickBooks GL replay up to 3600 s. Both shipped workers run `--timeout=120`, so set it on either path — Docker: [Optional services](#optional-services); Forge: [step 6](#one-time-setup) of the deploy guide | default | `3700` |
| `SESSION_DRIVER` / `CACHE_STORE` | Session + cache backing | `database` | `database` or `redis` |
| `MAIL_MAILER` + `MAIL_*` | Outgoing mail (invitations, statements, portal links, **email verification** — a new account can't create its organization until it verifies) | `log` (writes to log, sends nothing) | `smtp`/`resend`/`postmark` + verified sender |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | From identity on all mail | `hello@example.com` | an address on your verified sender domain |
| `RESEND_API_KEY` | API key when `MAIL_MAILER=resend` (the one bundled API mailer) | — | your Resend key |
| `SECURITY_ALERT_EMAIL` / `LEDGER_INTEGRITY_ALERT_EMAIL` / `OPS_ALERT_EMAIL` | The three ops alert addresses: `security:monitor` (hourly anomaly scan), `integrity:check` + `audit:verify` (nightly ledger proof), and `OPS_ALERT_EMAIL` for the plumbing alarms — `ops:monitor-failed-jobs` (hourly `failed_jobs` digest) and any scheduled task that crashes. Each falls back to `EXCHANGE_RATE_HEALTH_ALERT_EMAIL`, then to a project default — **set them, or the alerts go to the upstream project's inbox** | — | your ops address(es) |
| `OPS_FAILED_JOBS_WINDOW_MINUTES` | Look-back window for the hourly failed-jobs digest | `60` | `60` |
| `EXCHANGE_RATE_HEALTH_ALERT_EMAIL` / `EXCHANGE_RATE_HEALTH_MAX_AGE_HOURS` | Where `rates:health` alerts when FX rates go stale, and how old a rate may be before that counts (26 h covers a missed 06:00 fetch). The address is also the fallback for every alert row above | — | your ops address / `26` |
| `NIGHTWATCH_ENABLED` / `NIGHTWATCH_TOKEN` | Laravel Nightwatch application monitoring (errors, queries, queue). Off unless enabled | `false` | `true` + your token |
| `ATTACHMENT_DISK` / `LOGO_DISK` / `BACKUP_DISK` | Which disk holds each kind of uploaded file. See [Object storage](#object-storage) | `local` / `public` / `local` | `s3` / `s3_public` / `s3` (so uploads and backups survive deploys) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` | S3 credentials + region (only when a disk above is set to S3) | region `ca-central-1` | your IAM key; keep the region in the country you serve |
| `AWS_BUCKET` / `AWS_PUBLIC_BUCKET` / `AWS_PUBLIC_URL` | Private bucket (attachments + backups) and public-read bucket (logos) with its base URL | — | your two buckets |
| `AWS_URL` | Optional base URL override for the **private** disk (CDN or custom domain) | — | — |
| `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` | Scratch space for in-progress uploads. Deliberately stays **local** even when the disks above are on S3 | `local` | `local` |
| `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` | Point at an S3-compatible store instead of AWS (MinIO, Cloudflare R2, Backblaze B2) | — | endpoint URL + `true` |
| `PASSKEYS_USER_HANDLE_SECRET` | Secret used to derive passkey user handles. Falls back to `APP_KEY` only when the line is **absent** — a present-but-empty value is used as-is, so leave it commented out rather than blank | any random string | a stable, secret random string (**don't rotate** — invalidates existing passkeys) |
| `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY` | Cloudflare Turnstile bot challenge on register, login and forgot-password. **Off until both keys are set**; `TURNSTILE_ENABLED=false` is a hard kill switch. Registration fails closed when Cloudflare is unreachable; login and password reset fail open. `PUBLIC_TURNSTILE_SITE_KEY` is accepted as an alias for the site key. See `config/turnstile.php` | — | a key pair minted per hostname in the Cloudflare dashboard |
| `REGISTER_RATE_LIMIT` / `REGISTER_RATE_DECAY` | Per-IP throttle on account creation (attempts / decay minutes). Fortify ships `POST /register` unthrottled; this is enforced in `CreateNewUser` and skipped for the very first user so a fresh install can always be bootstrapped | `5` / `10` | `5` / `10` |
| `STRIPE_KEY` / `STRIPE_SECRET` | Stripe API keys (publishable + secret) for Connect | test keys from <https://dashboard.stripe.com/test/apikeys> | live keys |
| `STRIPE_CLIENT_ID` | Stripe **Connect** client ID (per-organization payouts for the portal) | test Connect client ID | live Connect client ID |
| `STRIPE_WEBHOOK_SECRET` | Signing secret for the **Connect** webhook (`/stripe/webhook`) | from `stripe listen` | from the live webhook endpoint |
| `EXCHANGE_RATE_DRIVER` | Multi-currency rate source | `frankfurter` (free, no key) | `frankfurter` |
| `FRANKFURTER_BASE_URL` | Override the Frankfurter API base | default | default |
| `BANK_IMPORT_AI_ENABLED` + `ANTHROPIC_API_KEY` | Optional AI fallback for bank-statement PDFs that `pdftotext` can't read (secured/scanned). See [Bank-statement PDF import](#bank-statement-pdf-import-poppler) | `false` | `false` unless you want it |

> Anything left unset just disables its feature — e.g. with no Stripe keys the
> customer portal still works but card payment is unavailable; with
> `MAIL_MAILER=log` mail is written to `storage/logs` instead of sent.
>
> The Business Q&A MCP server's OAuth path also needs Passport signing keys —
> generate them once with `php artisan passport:keys` (see [Deploy](#deploy-laravel-forge--digitalocean)).
> The Docker image does this for you on first boot.
>
> **Mail drivers need their transport package.** `smtp`, `sendmail`, and `log`
> work out of the box. **Resend** also ships ready to use — `resend/resend-php`
> is a bundled dependency; just set `MAIL_MAILER=resend` and `RESEND_API_KEY`.
> The other API mailers must be installed *before* you select them:
> `composer require symfony/postmark-mailer` for Postmark, `aws/aws-sdk-php` for
> SES, or `symfony/mailgun-mailer` for Mailgun. Selecting a mailer whose package
> isn't installed fails the send with `Class "…" not found` (e.g. the queued
> notification throws `Class "Resend" not found`).

### Optional subsystems

These are **off or on safe defaults** out of the box. Each has its own config file if
you need the full set of knobs; the keys below are the ones that turn the feature on.

| Subsystem | Turn it on with | Config |
| --- | --- | --- |
| **Document inbox — email forwarding** | `INBOUND_EMAIL_ENABLED=true`, `INBOUND_DOMAIN`, `INBOUND_EMAIL_SIGNING_SECRET` (the provider webhook is verified by HMAC, not CSRF) | `config/inbox.php` |
| **Document inbox — OCR** | `INBOX_OCR_ENABLED=true` + `ANTHROPIC_API_KEY`; tune with `INBOX_OCR_MODEL` / `_DRIVER` / `_TIMEOUT` / `INBOX_MAX_KILOBYTES` | `config/inbox.php` |
| **Agentic MCP writes** | `MCP_WRITE_ENABLED=true` **and** the per-organization **AI assistant writes (MCP)** switch on the organization edit page. `MCP_REDIRECT_DOMAINS` allow-lists OAuth redirect hosts | `config/mcp.php` |
| **AI daily insights** | `INSIGHTS_AI_ENABLED=true` + `ANTHROPIC_API_KEY`; `INSIGHTS_AI_MODEL` / `_MAX_CANDIDATES` / `_TIMEOUT` | `config/insights.php` |
| **Bank-import AI fallback** | `BANK_IMPORT_AI_ENABLED=true` + `ANTHROPIC_API_KEY`; `BANK_IMPORT_PDF_EXTRACTOR`, `BANK_IMPORT_AI_MODEL` / `_DRIVER` / `_TIMEOUT` / `_SAMPLE_ROWS`, `BANK_IMPORT_MAX_KILOBYTES`, `BANK_IMPORT_DATE_TOLERANCE_DAYS` | `config/banking.php` |
| **Transaction classification** | The history-based suggester is on by default; tune history depth with `CLASSIFICATION_HISTORY_DAYS`, `CLASSIFICATION_MAX_HISTORY_ROWS`, `CLASSIFICATION_DESCRIPTION_HISTORY_LIMIT`. The AI fallback (`CLASSIFICATION_AI_MAX_DESCRIPTIONS` batches it) has no switch of its own — it rides the inbox OCR gate above, so nothing leaves the server unless OCR is on | `config/classification.php` |
| **Edit locks** (one member edits a record at a time) | On by default; `EDIT_LOCKS_ENABLED=false` switches it off. Tune with `EDIT_LOCKS_TTL_SECONDS` (lease length, 120 — keep it above 60, since hidden tabs renew about once a minute), `EDIT_LOCKS_HEARTBEAT_SECONDS` (30), `EDIT_LOCKS_IDLE_MINUTES` (15) and `EDIT_LOCKS_PRUNE_AFTER_DAYS` (7). None of these are in `.env.example`; add the ones you change | `config/edit_locks.php` |
| **In-app support tickets** | On by default; `SUPPORT_ENABLED=false` drops the Support entry from both account menus and 404s `/support`. Tickets already raised are kept and stay readable in the admin portal | `config/support.php` |
| **Bot challenge on auth forms** | `TURNSTILE_SITE_KEY` + `TURNSTILE_SECRET_KEY` (see the [Environment](#environment) table); `TURNSTILE_ENABLED=false` kills it regardless of keys | `config/turnstile.php` |
| **Content-Security-Policy** | Always emitted by `SecurityHeaders`; `CSP_MODE` picks `report-only` (default — observe, don't block), `enforce`, or `off` (the escape hatch for a self-host with customized assets). `CSP_REPORTING` / `CSP_REPORT_SAMPLE` control the `POST /csp-report` violation log in `storage/logs/csp.log` | `config/security.php` |
| **Cheque print alignment** | No env vars. `config/cheque.php` holds the Intuit voucher-stock grid — every field's `[x, y]` baseline, font sizes, the `Ymd` date comb, the payee address block and the voucher columns — plus `offset_x` / `offset_y` to nudge the whole page for a printer. It is a config file, so a change needs `php artisan config:cache` (or a redeploy) to take | `config/cheque.php` |
| **Security-alert thresholds** | `SECURITY_ALERT_WINDOW_MINUTES`, `SECURITY_ALERT_FAILED_LOGIN_THRESHOLD`, `SECURITY_ALERT_API_KEY_REVOCATION_THRESHOLD` (defaults 60 / 10 / 5) | `config/services.php` |

> **Filing payroll slips needs your transmitter identity.** The T4 / T4A XML carries a
> CRA transmitter number and the RL-1 XML carries Revenu Québec preparer numbers. The
> defaults are **placeholders** (`MM000000`, `NP000000`) that CRA and RQ will reject —
> set `CRA_TRANSMITTER_NUMBER`, `CRA_TRANSMITTER_TYPE`, `CRA_TRANSMITTER_LANGUAGE`,
> and (for Quebec) `RQ_TRANSMITTER_NUMBER`, `RQ_PREPARER_NUMBER`,
> `RQ_AUTHORIZATION_NUMBER`, `RQ_RL1_SLIP_TYPE` before you file. See
> `config/payroll.php`.

## Scheduled jobs

Thirteen tasks run on the scheduler (`routes/console.php`). They need either a
cron entry calling `schedule:run` every minute (Forge does this for you) or a
long-running `schedule:work` process (the Docker `scheduler` container):

| Command | Cadence | Does |
| --- | --- | --- |
| `payroll:accrue-time-off` | daily 01:00 | Accrue time-off balances per each organization's policies |
| `recurring:generate` | daily 02:00 | Generate due recurring invoices/bills/journal entries as **drafts** |
| `depreciation:generate` | daily 02:30 | Draft the period's depreciation entries for due fixed assets |
| `integrity:check` | daily 04:00 | Five checks per organization — audit hash chain, double-entry balance, account-balance cache, invoice/bill paid caches, and tax on control-account cheque lines; email ops on failure |
| `insights:generate` | daily 05:00 | Compute each organization's daily "Did you know?" dashboard insight (one queued job per organization; idempotent per day) |
| `rates:fetch` | daily 06:00 | Pull the day's FX rates (multi-currency) |
| `reports:send-scheduled` | daily 07:00 | Email memorized reports on their schedules |
| `reminders:send` | daily 07:30 | Email payment reminders for overdue invoices (opted-in customers only) |
| `rates:health` | daily 08:30 | Alert if FX rates are stale (the fetch missed or failed) |
| `security:monitor` | hourly | Scan the security log for anomalies (failed-login spikes, lockouts, mass API-key revocation, privilege escalation); email ops on any finding |
| `ops:monitor-failed-jobs` | hourly | Digest of queued jobs that landed in `failed_jobs` during the window; logs, emails `OPS_ALERT_EMAIL`, and exits non-zero. The only thing that surfaces a silently failed backup, restore, or mail send |
| `backups:prune-expired` | daily | Delete expired organization backup files |
| `edit-locks:prune` | daily | Delete released edit-lock rows nobody has held or changed for `EDIT_LOCKS_PRUNE_AFTER_DAYS` (default 7) |

The per-organization jobs — `recurring:generate`, `depreciation:generate`,
`insights:generate`, `reports:send-scheduled`, `reminders:send` — all take an optional
`{company?}` (id or slug) plus `--sync`, so you can run one organization in-process
when you're debugging. `payroll:accrue-time-off` takes `{company?}` and `--date=`.
Since 1.1.0 they skip soft-deleted organizations instead of queuing work that fails.

Every task carries `->onFailure(SchedulerFailureAlert::for(...))`, so a command that
throws or exits non-zero emails `OPS_ALERT_EMAIL` rather than failing silently. Most
tasks are `withoutOverlapping()` and `onOneServer()` — which needs a cache store
shared across servers (Redis, or the `database` store on one shared DB) if you ever run
`schedule:run` on more than one box.

### On-demand operator commands

Not scheduled — run these by hand when you need them:

| Command | Does |
| --- | --- |
| `app:upgrade [--dry-run] [--verify] [--company=]` | **The upgrade command.** Runs pending migrations, then the post-upgrade data steps a release needs (`banking:backfill-line-memos` and `banking:backfill-reconciliation-stamps`), and with `--verify` finishes with `integrity:check` (without alert emails). `--dry-run` reports what would run; `--company=` scopes the post-migration steps to one organization. The Docker entrypoint runs it on every boot unless `MIGRATE_ON_BOOT=false`, and the Forge deploy script calls it in place of `migrate --force`. See [`UPGRADING.md`](UPGRADING.md). |
| `audit:verify {company?} [--full] [--no-alert]` | Verify the accounting audit-log **hash chain**; checks one organization, or all when omitted. A normal run resumes from the last saved checkpoint — pass `--full` to re-verify from genesis when you suspect tampering; `--no-alert` reports without logging or emailing. (Also runs nightly as part of `integrity:check`.) |
| `integrity:check {company?} [--fix] [--no-alert]` | The nightly reconciliation check, on demand: hash chain, double-entry balance, account-balance cache, invoice/bill paid caches, and tax on control-account cheque lines. `--fix` recomputes drifted account-balance **and** document paid caches in place (the cheque-tax check is report-only: open the cheque and save it); `--no-alert` reports without emailing. |
| `ops:monitor-failed-jobs [--window=] [--no-alert]` | Run the hourly failed-jobs digest by hand over a custom look-back window (minutes). |
| `backup:export --company=<id> [--sync]` | Produce a ZIP backup of one organization on the configured backup disk; `--sync` runs in-process so exceptions surface in the shell (handy for smoke tests). |
| `backup:import <file> --user=<id> [--sync] [--dry-run]` | Restore a backup ZIP into a **new** organization owned by the given user. `--dry-run` inspects the bundle without writing. |
| `storage:check [--skip-probes]` | Audit the storage configuration and **prove** it: round-trips a throwaway object through every configured disk, then fetches it over plain HTTP with no credentials to confirm logos are public and attachments/backups are not. See [Object storage](#object-storage). |
| `security:monitor [--window=] [--no-alert]` | Run the hourly anomaly scan by hand over a custom look-back window. |
| `rates:fetch [--date=]` / `rates:health [--no-alert]` | Fetch FX rates for a specific date, or check freshness without emailing. `GET /health/fx` is the unauthenticated HTTP counterpart for an uptime monitor. |
| `edit-locks:prune` | Delete released edit-lock rows untouched for `EDIT_LOCKS_PRUNE_AFTER_DAYS` (the daily job, on demand). |
| `proof:generate {test?} [--per-year=]` | Rebuild the public `/verification` proofs — all three scenarios, or `test-1` / `test-2` / `test-3`. See [Verification](#verification). |
| `banking:backfill-line-memos {company?} [--dry-run]` | Append each posted document's own memo to its bank journal line, so the bank register reads "Deposit: August rent" instead of a bare "Deposit"; wording only, amounts untouched. Idempotent. `app:upgrade` runs it. |
| `banking:backfill-reconciliation-stamps {company?} [--dry-run]` | Un-clear the service-charge and interest lines a completed reconciliation replaced or left unticked, so the register's cleared balance matches the reconciliation; reconciliations whose ticked lines no longer line up (e.g. after a restore) are skipped and listed. `app:upgrade` runs it. |
| `payroll:verify-slip-templates {year?}` | Verify the official T4 / T4A / RL-1 PDF templates still import, map, and render. Run it alongside the two `payroll:verify-*` commands after a year-end update. |

Two older one-off data backfills also ship for upgrading data from before 1.0.0 and
are **not** run by `app:upgrade` — run once if/when relevant:
`migration:backfill-contact-links` (backfill `contact_id` onto AR/AP journal lines
so GL-driven statements match aging) and `payroll:backfill-accounts` (create the
system payroll GL accounts on existing Canadian organizations).

## Operating & maintenance

The [scheduler](#scheduled-jobs) covers the recurring *automated* work. The tasks
below are the *manual* ones an operator owns on a calendar. Most are rare — but
the payroll one is mandatory every year before the first January pay run.

| Cadence | Task | What to do |
| --- | --- | --- |
| **Every upgrade** | Back up, then `app:upgrade` | Take a backup first (`docker/backup.sh`, or your own DB dump + `storage/` copy), deploy the new release, and let `php artisan app:upgrade` run (the Docker entrypoint and the Forge deploy script both call it). Finish with `php artisan app:upgrade --verify`. Release-specific notes live in [`UPGRADING.md`](UPGRADING.md). |
| **Annually, by Jan 1** | Refresh payroll tax constants | Append the new effective-date tables to `FederalConstants.php` + `ProvincialConstants.php` from CRA's new T4127 (details below). Payroll is **blocked** for any date/province not loaded. |
| **Mid-year, if CRA issues a Jul 1 T4127** | Add the mid-year payroll table | Same as above, keyed `YYYY-07-01`. |
| **Each filing season** | Verify CRA / RQ e-file schemas | Before filing T4 / T4A / RL-1 / ROE, confirm the generated XML still matches CRA's (and, for RL-1, Revenu Québec's) current schema for the year — the generators are shipped marked "validate against the current schema before submission." PD7A is a remittance worksheet, not an e-file, and has no XML to check. |
| **When a jurisdiction changes a rate** | Update sales-tax codes | Edit the affected organization's codes in **Settings → Sales Tax**, and update `app/Support/Defaults/CanadianDefaults.php` so *new* organizations seed the new rate. |
| **Every deploy** | Regenerate verification proofs | Run `php artisan proof:generate` so `/verification` reflects the current code. Neither the Forge deploy script below nor the Docker entrypoint runs it — add it to your deploy script, or `docker compose exec app php artisan proof:generate` after an upgrade. |
| **After a restore** | Prove the books | `php artisan app:upgrade --verify` (or `integrity:check` directly, `--fix` to heal drifted caches) and `audit:verify --full`, so a restored dump is known-good before anyone posts to it. |
| **Periodically** | Dependency & security updates | `composer update` / `npm update`, run the suite, redeploy; keep PHP and MySQL patched. The `security` CI workflow runs `composer audit` + `npm audit`, secret scanning and SAST weekly and on every PR, so advisories surface without you polling. |
| **Periodically** | Confirm backups + worker health | Check organization backups complete and land on `BACKUP_DISK` (`php artisan storage:check` proves the disks), and that the queue worker + `schedule:run` are alive. The queue runs backups, restores, recurring-document generation, depreciation drafting, daily insights, scheduled report emails, payment reminders, document-inbox processing (incl. OCR), the QuickBooks full-history replay, and all outgoing mail; the scheduler drives the [thirteen tasks](#scheduled-jobs). `ops:monitor-failed-jobs` emails `OPS_ALERT_EMAIL` within the hour when anything lands in `failed_jobs`, so the manual check is mostly confirming that alert path works. |

### Payroll tax rates (the important one)

Canadian payroll math is driven by two data files, each keyed by the table's
**effective date**:

- `app/Support/Payroll/Constants/FederalConstants.php` — CPP / CPP2 / EI rates and
  maximums, plus the federal income-tax brackets and basic personal amount.
- `app/Support/Payroll/Constants/ProvincialConstants.php` — per-province brackets,
  basic personal amount, surtax, and health premium. **Quebec** runs a parallel
  system: its block carries a nested `quebec` bag (QPP / QPP2 / EI-QC / QPIP +
  federal abatement) and its provincial tax comes from Revenu Québec's TP-1015.G.

CRA publishes a new **T4127 "Payroll Deductions Formulas"** every January (and
occasionally a July revision); Quebec's pieces come from the T4127 plus Revenu
Québec. As shipped, the **2025** and **2026** tables are loaded (both the January and
the July editions — four effective-date periods in all), so each new year (once the
next edition is published) you must:

1. Get the new T4127 edition from CRA.
2. **Append** one entry keyed by its effective date (e.g. `'2027-01-01' => [...]`)
   to the federal file and to every province you support. Do **not** edit prior
   years — old pay runs must still recompute identically.
3. Run the verification commands (below) and spot-check a pay run against CRA's
   [Payroll Deductions Online Calculator](https://www.canada.ca/en/revenue-agency/services/e-services/digital-services-businesses/payroll-deductions-online-calculator.html).

Three artisan commands guard the payroll data — run them whenever you load a new
table (and ideally in CI / on deploy):

```bash
php artisan payroll:verify-constants       # re-derives CPP/CPP2/EI + QPP/QPP2/EI-QC/QPIP
                                           # maxima from rate × ceiling; must match published maxima
php artisan payroll:verify-calculations    # runs the engine against its CRA PDOC reference
                                           # matrix; must match to the cent (exits non-zero on any miss)
php artisan payroll:verify-slip-templates  # official T4 / T4A / RL-1 PDF templates still import,
                                           # map, and render for the year
```

`payroll:verify-constants` catches data-entry slips across every loaded period;
`payroll:verify-calculations` is the payroll analog of `proof:generate` — cases with
no reference value yet are flagged "awaiting" (run them through CRA PDOC and fill
them into `PayrollVerificationDataset`).

`PayrollConstantsRepository` **refuses** to compute for a date or province with no
loaded table — the pay-run UI blocks payroll rather than withhold wrong amounts —
so a missing update fails loudly instead of quietly producing bad numbers.

## Testing

```bash
php artisan test --compact                 # the PHP suite
php artisan test --compact --filter=Invoice # one feature/filter
npm test                                   # the JS suite (tests/js/, Node's built-in runner)
composer run test                          # config:clear + Pint check + npm test + full suite (CI parity)
```

CI runs against **SQLite**, local dev against **MySQL** — date columns must store
date-only strings (cast `date:Y-m-d` + `->toDateString()`) or SQLite comparisons
break where MySQL silently coerces. Every change should come with a test. Note that
`composer run test` fails on a failing JS test too, so `npm install` first.

Four workflows run in CI: `tests` (the PHP suite on SQLite plus `npm test`), `linter`
(**Pint** and **PHPStan / Larastan**), `security` (weekly and per PR: `composer audit`,
`npm audit`, full-history secret scanning, Semgrep SAST, dependency review), and
`docker` (the image build). PHPStan is baselined (`phpstan-baseline.neon`), so the gate
fails only on *new* findings; run it locally with
`vendor/bin/phpstan analyse --memory-limit=1G`.

## Verification

Beyond the unit/feature suite, a public, unauthenticated page at **`/verification`**
proves the accounting engine is correct on real data. Three end-to-end scenarios
are built deterministically through the *real* posting pipeline, validated, and
published with downloadable evidence:

- **Test 1 — 3-Year Closing Trial Balance.** ~500 posted transactions per fiscal
  year (2023–2025); at each Dec 31 the trial balance balances, ties to the balance
  sheet and income statement, and the immutable audit chain verifies.
- **Test 2 — Imported Trial Balance.** A new organization brought live with an imported
  opening trial balance; the resulting reports tie back to every imported figure.
- **Test 3 — QuickBooks Journal Import.** A mocked QuickBooks Desktop *Journal*
  export for 2023–2025 replayed through the full-history importer; every account
  ties back to the source totals and all transactions post.

Each test publishes a `manifest.json` (totals, pass/fail, SHA-256 hashes) and a ZIP
of the source transactions plus generated reports — trial balance, balance sheet,
income statement, AR/AP aging, open invoices/bills, and general ledger as PDF and
CSV — so anyone can re-derive the numbers.

```bash
php artisan proof:generate                       # build, validate, write artifacts to storage/app/proof
php artisan proof:generate test-2                # one scenario; --per-year= sizes Test 1 (default 500)
php artisan test --compact tests/Feature/Proof   # the same scenarios, run as the CI gate
```

`proof:generate` builds each scenario inside a database transaction that is always
rolled back (artifacts are flushed to storage first), so it leaves no organization
behind. The one `ScenarioBuilder` backs both the command and the Pest tests, so the
published page and the test suite can never drift. Run it on deploy to keep the
page current — nothing runs it for you (see [Operating & maintenance](#operating--maintenance)).

## Self-hosting with Docker

The easiest way to run LineLedger yourself. One prebuilt image
(`ghcr.io/lineledger/lineledger`) runs the web app, the queue worker, and the
scheduler as three containers alongside MySQL 8.4 — Docker and Docker Compose are
the only requirements. Release tags are built for **linux/amd64 and linux/arm64**;
the `edge` build from `main` is amd64-only.

### Quick start

No clone needed — fetch the compose bundle for the release, configure, and start:

```bash
mkdir lineledger && cd lineledger
base=https://raw.githubusercontent.com/lineledger/lineledger/v1.1.0/docker
curl -fsSLO "$base/docker-compose.yml"
curl -fsSL  "$base/.env.example" -o .env
curl -fsSLO "$base/backup.sh"
curl -fsSLO "$base/restore.sh"
chmod +x backup.sh restore.sh

# Edit .env: set DB_PASSWORD, DB_ROOT_PASSWORD, APP_URL, and your mail settings,
# and add DB_QUEUE_RETRY_AFTER=3700 (the worker runs --timeout=120; see Optional services).
# Then generate the encryption key and paste it into .env as APP_KEY=...
docker compose run --rm --no-deps app php artisan key:generate --show

docker compose up -d
```

Fetch from the tag you are deploying (`v1.1.0` above), not `main` — the compose
file, entrypoint and scripts on `main` may already describe the next release.

**Mail has to work before anyone can get in.** Email verification is enforced: a
new account can't create its organization until it clicks the verification link, so a
blank `MAIL_HOST` leaves a fresh install stuck at the verify-email screen. Point
`MAIL_*` at a real mailer, or for a trial run start Mailpit
(`docker compose --profile mail up -d`, then `MAIL_HOST=mailpit`, `MAIL_PORT=1025`;
its inbox is at `127.0.0.1:8025`, loopback only, because it shows password-reset
links with no login).

Open <http://localhost:8080> and register. **The first registered user becomes the
site admin** (the cross-tenant `/admin` portal, behind 2FA + a password re-check)
and creates the first organization; registration stays open to anyone who can
reach the host until you close it in `/admin` → Site settings. On boot the app
container runs `php artisan app:upgrade` (migrations plus the release's post-upgrade
steps), `storage:link`, and generates the Passport OAuth keys; the queue and
scheduler containers wait until the app is healthy before starting.

To build the image locally instead of pulling from GHCR:
`git clone`, `cd lineledger/docker`, then `docker compose build && docker compose up -d`.

**Back up your `.env`** — `APP_KEY` encrypts sessions and secrets, and
`PASSKEYS_USER_HANDLE_SECRET` anchors every registered passkey. Losing either
is unrecoverable. The `.env` template documents both, plus the three alert-email
addresses (`SECURITY_ALERT_EMAIL`, `LEDGER_INTEGRITY_ALERT_EMAIL`, `OPS_ALERT_EMAIL`)
you should point at your own inbox. `backup.sh` copies `.env` alongside
every backup for exactly this reason.

### Upgrading

Release notes and anything a specific version needs are in
[`UPGRADING.md`](UPGRADING.md); the routine is always the same:

```bash
./backup.sh                                # 1. DB dump + storage volume + .env into ./backups
# 2. in .env, pin the release:  LINELEDGER_VERSION=1.1.0
docker compose pull && docker compose up -d   # 3. new image; app:upgrade runs on boot
docker compose exec app php artisan app:upgrade --verify   # 4. confirm, and prove the books
```

The app container's entrypoint runs `php artisan app:upgrade` on every boot —
pending migrations, then the post-upgrade data steps the release ships (for 1.1.0,
the two `banking:backfill-*` commands) — and the workers wait for it to become
healthy. Set `MIGRATE_ON_BOOT=false` in `.env` if you would rather the container
boot on the old schema and run `docker compose exec app php artisan app:upgrade`
yourself once the backup is confirmed. Step 4 is idempotent: with nothing left to
migrate it just runs `integrity:check` and exits non-zero if the ledger doesn't
reconcile, which is the moment to reach for `restore.sh`.

Image tags: `1.1.0` (exact release), `1.1` (moves with patch releases on the 1.1
line — the pin most self-hosters want), `latest` (newest tagged release, never
`main`), and `edge` (`main`, amd64-only, if you want to live dangerously). Release
tags are multi-arch, so a Raspberry Pi or Graviton host pulls the arm64 image with
the same tag. Scale workers with `docker compose up -d --scale queue=2`; don't scale
the `app` service — it's the one that runs `app:upgrade`.

### Backups

Two named volumes hold all your data, and **`docker compose down -v` deletes both**:

- **`dbdata`** — the MySQL data directory.
- **`storage`** — attachments, logos (on the default `public` disk), in-app backup
  archives, proof artifacts, the installed slip templates, and the Passport OAuth
  keys.

Plus `.env`, which holds `APP_KEY` — a dump restored under a different key is
unreadable.

`backup.sh` captures all three in one run — a `mysqldump` with triggers (executed
*inside* the mysql container, so no password ever touches the host shell), a tarball
of the `storage` volume, and a copy of `.env` — as timestamped files in `./backups`
(or `BACKUP_DIR`), and prints the matching restore command:

```bash
./backup.sh                                  # or: BACKUP_DIR=/mnt/backups ./backup.sh
./restore.sh backups/lineledger-db-<ts>.sql.gz backups/lineledger-storage-<ts>.tar.gz
```

`restore.sh` stops the app containers, drops and reloads the database as root (the
audit-log immutability triggers carry a root definer, so the app user can't recreate
them), replaces the storage volume when given the tarball, brings the stack back up
and runs `app:upgrade --verify`. Copy the saved `lineledger-env-<ts>` back over `.env`
first if `.env` has changed since the backup.

For unattended nightly database dumps enable the `backup` profile:

```bash
docker compose --profile backup up -d
```

A sidecar writes `lineledger-db-<ts>.sql.gz` into `BACKUP_DIR` (default `./backups`
— a **host** directory, so `down -v` can't take it) every `BACKUP_INTERVAL_SECONDS`
(default 86400) and prunes dumps older than `BACKUP_KEEP_DAYS` (default 14). It dumps
the database only; run `backup.sh` for the storage volume, and copy `./backups`
off-host either way.

If you'd rather dump by hand, read the password inside the container — Compose
never exports `.env` into your shell, so a bare `-p"$DB_ROOT_PASSWORD"` on the host
expands to nothing:

```bash
docker compose exec -T mysql sh -c \
    'mysqldump --single-transaction --triggers --routines --events \
        -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' > lineledger.sql
```

`--triggers` matters: the audit-log immutability triggers must survive a restore, and
restoring them needs a privileged (root) MySQL user.

The in-app backup feature (Settings → **Backup & Export**) writes per-organization
archives to `storage/app/private/backups` inside the `storage` volume — copy those
off-host too.

### HTTPS / reverse proxy

Three ways to serve it, from most to least common:

1. **Behind your reverse proxy** (Traefik, nginx, Caddy, …): keep the default
   `HTTP_PORT=8080`, terminate TLS at the proxy, and make sure it forwards
   `X-Forwarded-Proto` (proxies are already trusted). Without that header,
   secure cookies break under HTTPS and logins fail.
2. **Direct exposure with automatic HTTPS**: set `SERVER_NAME=books.example.com`
   in `.env` and uncomment the 80/443 ports on the `app` service — FrankenPHP's
   built-in Caddy obtains and renews certificates automatically (persisted in
   the `caddy_data` volume).
3. **Plain HTTP on a LAN**: the default. Fine for a homelab; passkeys require
   HTTPS (or localhost).

In every case `APP_URL` must match the URL users actually visit, scheme
included.

### Optional services

Compose profiles bundle common extras:

```bash
docker compose --profile backup up -d  # nightly DB dumps into BACKUP_DIR (see Backups)
docker compose --profile mail up -d    # Mailpit (MAIL_HOST=mailpit, MAIL_PORT=1025; UI at 127.0.0.1:8025)
docker compose --profile redis up -d   # Redis (set CACHE_STORE/QUEUE_CONNECTION/SESSION_DRIVER=redis, REDIS_HOST=redis)
docker compose --profile minio up -d   # MinIO S3 — needs MINIO_ROOT_USER + MINIO_ROOT_PASSWORD (see below)
```

MinIO deliberately has no default credentials: with `MINIO_ROOT_USER` /
`MINIO_ROOT_PASSWORD` unset the container exits immediately. Set both in `.env`, then
point the app at it with `AWS_ENDPOINT=http://minio:9000`,
`AWS_USE_PATH_STYLE_ENDPOINT=true` and the storage roles from the root
`.env.example`. Its API and console bind to `127.0.0.1:9000` / `:9001` — reach them
through your reverse proxy or an SSH tunnel.

**Queue `retry_after`.** The compose bundle's worker runs `queue:work --timeout=120`,
but the queue's `retry_after` defaults to 90 seconds, and Laravel requires the
worker's `--timeout` to stay several seconds *below* it — the same mismatch
[step 6](#one-time-setup) of the Forge guide explains. Worse, a backup export or
restore (up to 1800 s) or the QuickBooks GL replay (up to 3600 s) is handed back to
the queue at 90 s while the worker is still running it. Add `DB_QUEUE_RETRY_AFTER=3700`
to `.env` (the template does not include it) and `docker compose up -d` to restart the
worker; if you moved the queue to the `redis` profile, set `REDIS_QUEUE_RETRY_AFTER=3700`
instead.

### Health & verification

The app exposes `/up` for health checks (the container healthcheck uses it).
After first boot, and after every upgrade:

```bash
docker compose exec app php artisan storage:check          # every disk role round-trips
docker compose exec app php artisan app:upgrade --verify   # nothing pending, then integrity:check
docker compose exec app php artisan proof:generate         # optional: refresh /verification
```

## Deploy (Laravel Forge / DigitalOcean)

A standard Laravel deploy: PHP-FPM + Nginx, a **queue worker daemon**, the
**scheduler cron**, and (recommended) **S3** for files so backups and uploads
survive deploys — see [Object storage](#object-storage) below.

### One-time setup

**1. Create the server (Forge → Servers → Create Server).**
DigitalOcean, any size, **PHP 8.5** (Composer pins `^8.5`), **MySQL 8**, Redis optional.

> **System packages.** For **PDF** bank-statement import, install poppler on the
> server: `sudo apt-get install -y poppler-utils` (provides `pdftotext`). It is a
> should-have, not a must-have: without it the importer falls back to the bundled
> pure-PHP `smalot/pdfparser`, which reads ordinary statement PDFs but not
> encrypted ones; an encrypted or scanned PDF then goes to the AI layer when enabled
> (`BANK_IMPORT_AI_ENABLED=true` + `ANTHROPIC_API_KEY`), and otherwise the user is
> asked for a CSV/OFX export. CSV, Excel and OFX/QFX/QBO imports need no extra
> packages. (The Docker image ships `poppler-utils`.)

**2. Create the site (Forge → Server → Sites → New Site).**
Root domain `books.lineledger.ca`, project type **General PHP / Laravel**, web
directory `/public`. Connect this repo (`main` branch, or a release branch if you
prefer to upgrade by tag — see [Subsequent deploys](#subsequent-deploys)).

**3. Provision the database (Forge → Server → Database).**
Create a database and user; copy the generated password into `DB_PASSWORD`.

**4. Fill in the environment (Forge → Site → Environment).**
Paste `.env.example`, then set every production value from the
[Environment](#environment) table — at minimum `APP_KEY` (generate one),
`APP_URL`, the `DB_*` block, a real `MAIL_*` mailer (email verification is
enforced, so mail must send before the first user can create an organization),
`PASSKEYS_USER_HANDLE_SECRET`, the three alert addresses, and `DB_QUEUE_RETRY_AFTER`
(step 6). Add the `STRIPE_*` keys for portal card payments and the `TURNSTILE_*`
keys if the signup form is public. Set `APP_ENV=production` and `APP_DEBUG=false`.

**5. Set the deploy script (Forge → Site → Deployment → Deploy Script).**

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build
$FORGE_PHP artisan app:upgrade
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan queue:restart
```

`npm run build` is mandatory — Forge won't build assets for you. `app:upgrade`
replaces `migrate --force`: it runs the pending migrations and then whatever
post-upgrade data steps the release ships, so an upgrade never leaves the schema
ahead of the data. `queue:restart` gracefully cycles the worker daemon (step 6) so
it picks up new code.

**6. Create the queue worker daemon (Forge → Site → Queue, or Server → Daemons).**

```
php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=3
```

Run it as the `forge` user from the site directory. **Set `DB_QUEUE_RETRY_AFTER`
(or `REDIS_QUEUE_RETRY_AFTER`) in the environment first** — the queue's
`retry_after` defaults to 90 seconds, and Laravel requires the worker's `--timeout`
to stay several seconds *below* it. With the default, `--timeout=120` is backwards,
and worse, a backup export or restore (up to 1800 s) or a QuickBooks GL replay (up to
3600 s) is handed to a second worker at 90 s while the first is still running it.
`DB_QUEUE_RETRY_AFTER=3700` clears the longest job; alternatively drop `--timeout`
below 90 and accept that the long jobs' own timeouts are then the only limit.

This worker processes backups, restores, recurring-document generation, depreciation
drafting, daily insights, scheduled report emails, payment reminders, document-inbox
processing (incl. OCR), the QuickBooks full-history replay, and all outgoing mail.
(Bank-statement imports run inline in the request, not on the queue.)

**7. Enable the scheduler (Forge → Site → Scheduler, or Server → Scheduled Jobs).**
Add `php artisan schedule:run` to run **every minute**. This drives the
[scheduled jobs](#scheduled-jobs), including the hourly `ops:monitor-failed-jobs`
digest that tells you when the worker in step 6 dropped something.

**8. Configure Stripe (one webhook).**
In the Stripe dashboard create a webhook endpoint at
`https://books.lineledger.ca/stripe/webhook` (Connect; secret →
`STRIPE_WEBHOOK_SECRET`). Connect onboarding uses `STRIPE_CLIENT_ID`.

**9. Generate Passport keys (one-time, for MCP OAuth).**
The Business Q&A MCP server's one-click OAuth path needs Passport signing keys:

```bash
cd $FORGE_SITE_PATH && php artisan passport:keys
```

Run it **once** — it writes `storage/oauth-*.key`. Don't re-run it on every deploy;
regenerating the keys invalidates existing OAuth tokens. (Skip this if you only use
the API-key MCP connection. The Docker entrypoint does this step for you.)

**10. Issue SSL (Forge → Site → SSL → Let's Encrypt)** for `books.lineledger.ca`.

**11. Click Deploy Now.** Visit the site, register (the first user becomes the site
admin), create an organization, and confirm the queue daemon and scheduler are green
in Forge.

**12. Verify the deploy.** From the site directory:

```bash
php artisan storage:check          # exits non-zero if any disk role is misconfigured
php artisan app:upgrade --verify   # nothing pending, and the ledger reconciles
```

Both exit non-zero on a problem, so once your storage is settled you can append
them to the deploy script as a gate.

### Object storage

By default every uploaded file lives on the server's local filesystem under
`storage/app`. That is fine for a single server whose `storage/` directory is
persistent and backed up. It is **not** fine if deploys swap the release
directory, or if you ever run more than one app server — uploads written by one
release or one box are invisible to the next.

Three env vars decide where each kind of file goes. They're independent, so you
can move some and not others:

| Var | Holds | Local default | S3 value |
| --- | --- | --- | --- |
| `ATTACHMENT_DISK` | Receipts, bills, statements, documents | `local` | `s3` |
| `LOGO_DISK` | Organization logos (browser-visible) | `public` | `s3_public` |
| `BACKUP_DISK` | Organization backup ZIPs | `local` | `s3` |

**Two buckets, both in the region you serve** (`ca-central-1` for Canada — the
default if `AWS_DEFAULT_REGION` is unset):

1. **Private** (`AWS_BUCKET`) — attachments and backups. Leave **Block Public
   Access fully on**. Nothing here is ever served directly; the app streams
   attachments through an authorization-checked route and hands out 5-minute
   presigned links for backup downloads.
2. **Public-read** (`AWS_PUBLIC_BUCKET`) — logos only, because browsers load them
   by URL from the customer portal and the app chrome. Set `AWS_PUBLIC_URL` to
   the bucket's public base URL (or your CDN in front of it).

Grant the public bucket's read access with a **bucket policy, not an ACL** —
buckets created since April 2023 default to *Bucket owner enforced*, where
writing an object with a public ACL fails outright:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Principal": "*",
    "Action": "s3:GetObject",
    "Resource": "arn:aws:s3:::YOUR-PUBLIC-BUCKET/*"
  }]
}
```

The app's IAM user needs nothing beyond:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Effect": "Allow",
    "Action": ["s3:GetObject", "s3:PutObject", "s3:DeleteObject"],
    "Resource": [
      "arn:aws:s3:::YOUR-PRIVATE-BUCKET/*",
      "arn:aws:s3:::YOUR-PUBLIC-BUCKET/*"
    ]
  }, {
    "Effect": "Allow",
    "Action": "s3:ListBucket",
    "Resource": [
      "arn:aws:s3:::YOUR-PRIVATE-BUCKET",
      "arn:aws:s3:::YOUR-PUBLIC-BUCKET"
    ]
  }]
}
```

**Verify it with `php artisan storage:check`.** It reports which disk each role
resolved to, writes/reads/deletes a throwaway object on each one (the only way
to tell a correct IAM policy from one that merely looks correct), and then
fetches that object over plain HTTP with no credentials — confirming the logo
bucket really serves and the private bucket really doesn't. It also checks that
backup downloads can be presigned, that Livewire still stages uploads locally,
that the region matches `APP_REGION`, and that no stored file points at a disk
you've since removed. Exit code is non-zero on any problem, so it works as a
post-deploy gate (step 12). Run it after any change to the vars above;
`--skip-probes` reports the configuration and runs only the supporting checks,
skipping both the round-trip writes and the credential-less HTTP fetch, for a server
whose egress can't reach the bucket.

Notes:

- **Switching is forward-only.** The disk is recorded per file, so anything
  uploaded before the switch keeps being served from where it already is —
  nothing breaks, but the old files are not moved for you. Keep `storage/app`
  around, or copy its contents into the bucket yourself.
- **`storage:link` is still required** either way — it is what serves the `public`
  disk, which is where logos live until `LOGO_DISK` moves them. (Proof artifacts do
  *not* use the symlink: they are written to `storage/app/proof` and streamed by a
  controller.)
- **Uploads still stage locally.** Livewire writes the in-progress upload to
  `storage/app` before the app moves it to its permanent home, and backup ZIPs
  are assembled on local disk before being uploaded. Keep some free space.
- **S3-compatible stores work**: set `AWS_ENDPOINT` and
  `AWS_USE_PATH_STYLE_ENDPOINT=true` for MinIO, Cloudflare R2, or Backblaze B2.

### Subsequent deploys

Push to `main` → **Deploy Now** (or enable **Quick Deploy**). The deploy script
runs `app:upgrade` (migrations plus the release's post-upgrade data steps), rebuilds
assets and caches, and restarts the worker.

**Upgrading an existing install to a new release** is the same script with two
things around it — take a backup before you click, and read
[`UPGRADING.md`](UPGRADING.md) for anything the release needs beyond what
`app:upgrade` does on its own. To upgrade by tag rather than by whatever is on
`main`, swap the `git pull` line for
`git fetch --tags origin && git checkout v1.1.0` (or point the Forge site at a
release branch), so a deploy always lands on a version you chose. After the deploy,
`php artisan app:upgrade --verify` proves the ledger still reconciles, and
`proof:generate` refreshes `/verification` if you publish it.

### Troubleshooting

- **Backups/restores never finish, document-inbox items stay unprocessed, a QuickBooks
  replay never starts, or no mail goes out** — the queue worker isn't running. Check
  Forge → **Daemons** and that `jobs` / `failed_jobs` aren't backing up.
  `ops:monitor-failed-jobs` should already have emailed `OPS_ALERT_EMAIL`; if it
  didn't, the scheduler (next bullet) is down too.
- **A backup or restore runs twice, or a long import fails at 90 s** —
  `retry_after` is shorter than the job. Set `DB_QUEUE_RETRY_AFTER=3700` (step 6)
  and restart the worker.
- **`failed_jobs` is full of `…ForCompany` jobs dated before the upgrade** — 1.0.0's
  nightly schedulers queued recurring, depreciation, reminder, insight and
  scheduled-report jobs for soft-deleted organizations, which then failed (and paged
  ops hourly). 1.1.0 skips those organizations. The old rows are noise:
  `php artisan queue:prune-failed --hours=24` (or `queue:flush` for all of them).
- **Recurring documents didn't generate** — the scheduler cron isn't firing.
  Verify `schedule:run` is set to every minute; check `php artisan schedule:list`.
- **"Someone is editing this record", or the API returns `423`** — that is an edit
  lock working as designed: the other member has the record open. A lease renews
  every 30 s while their tab is open, expires about two minutes after it closes, and
  stops renewing after 15 minutes idle, so a lock never outlives its editor by much;
  an Owner or Admin can also **take over** from the record's page, which bumps the
  editor out. API clients should wait `Retry-After` seconds and retry unchanged (see
  [`docs/api-v1.md`](docs/api-v1.md)). A lock that appears stuck for longer than the
  lease means heartbeats are failing to land — check that the browser can reach the
  app (a proxy stripping the Livewire request, a stale cached config after changing
  `EDIT_LOCKS_*`). `EDIT_LOCKS_ENABLED=false` switches the subsystem off entirely
  (then `config:cache`); `edit-locks:prune` only deletes rows that are already
  released, it never breaks a live lock.
- **Uploaded logos / attachments vanish after deploy** — you're on the `local`
  disk and `storage/app` isn't on a shared/persistent path. Either make it one,
  or move uploads to object storage — see [Object storage](#object-storage).
- **Passkeys stop working after a config change** — `PASSKEYS_USER_HANDLE_SECRET`
  changed. It must stay stable; rotating it invalidates every registered passkey.
  Rotating `APP_KEY` does the same if the passkey secret was never set (it falls
  back to `APP_KEY`), as does adding the line blank.
- **Env change didn't take effect** — run `php artisan config:clear` (or redeploy);
  cached config is read at boot, not per request. This applies to config-file
  edits too — a cheque-alignment tweak in `config/cheque.php` prints nothing new
  until `config:cache` is re-run.
- **Stripe payments fail silently** — the webhook secret is wrong or the endpoint
  URL doesn't match `APP_URL`; check Stripe → **Developers → Webhooks → Logs**.
- **MCP OAuth connect fails with a 500 on the authorize or token step** and the log
  shows `Key path ".../storage/oauth-private.key" does not exist or is not
  readable` — Passport keys weren't generated. Run `php artisan passport:keys` once
  on the server (step 9). The API-key connection is unaffected.

## Disclaimer & no warranty

**This software is provided "as is", without warranty of any kind, and is used
entirely at your own risk.** To the maximum extent permitted by law, Local
Foundry Inc., the authors, and contributors are **not liable** for any claim, damages,
data loss, or other liability arising from the software or its use. This is the
plain-English summary of the warranty disclaimer and limitation of liability in
the [AGPLv3](LICENSE) (sections 15–17), which are the governing legal terms.

**Not professional advice.** LineLedger is record-keeping software — **not a
substitute for professional accounting, bookkeeping, tax, payroll, audit, or
legal advice**, and using it creates no professional relationship. You alone are
responsible for the accuracy, completeness, and legality of the data you enter
and of the records, reports, financial statements, returns, and forms you
generate, and for determining and meeting your own tax, payroll, and filing
obligations. **Review the software's outputs and consult a qualified accountant,
tax advisor, or lawyer before relying on them or making a filing.**

**Tax & payroll calculations are not guaranteed.** Built-in tax rates, payroll
formulas, and rules may be incomplete, out of date, or wrong, and the software
**does not file anything on your behalf**. Canadian payroll constants in
particular **must be refreshed yearly**, and the CRA / Revenu Québec e-file
schemas ship marked "verify before production" — see
[Operating & maintenance](#operating--maintenance). Verify every calculation
against the relevant authority (e.g. CRA's PDOC) before relying on it.

**AI features.** Optional AI features (e.g. the Business Q&A integration and the
AI bank-statement import fallback) produce **informational output that may be
inaccurate** and must be independently verified by a qualified person before you
rely on it for any financial, tax, or filing decision.

**This covers the source code, not a hosted service.** This repository and the
AGPLv3 govern the *software*. If you self-host, you operate it yourself — there
is **no service, support, SLA, uptime, or backup guarantee** from Local Foundry
Inc. The separate [Terms of Service](https://lineledger.ca/terms) apply only to
the hosted service operated by Local Foundry Inc., not to your own deployment.

## Trademarks & legal notice

Copyright © 2026 Local Foundry Inc. All rights reserved.

**"Line Ledger"**, **"LineLedger"**, the LineLedger logo, the LineLedger
wordmark, and any associated product names, slogans, taglines, and visual
identifiers are trademarks, service marks, trade dress, and/or registered
trademarks of **Local Foundry Inc.** in Canada and other jurisdictions
(collectively, the **"LineLedger Marks"**). All rights in the LineLedger Marks
are reserved.

The source code in this repository is licensed under the
[GNU Affero General Public License v3.0](https://www.gnu.org/licenses/agpl-3.0.html)
(AGPLv3). **The AGPLv3 grants you rights in the source code only. It does
not grant you any right, title, license, or interest, express or implied, in
or to the LineLedger Marks**, whether by implication, estoppel, exhaustion,
or otherwise. Nothing in the AGPLv3, this repository, or its documentation
constitutes a trademark license.

Without the prior written permission of Local Foundry Inc., you may **not**:

- use the LineLedger Marks, or any confusingly similar mark, in the name,
  branding, domain name, social media handle, package name, or marketing of
  any product, service, fork, derivative work, or distribution;
- represent or imply that any fork, derivative work, hosted instance, or
  third-party service is endorsed, sponsored, certified, affiliated with, or
  produced by Local Foundry Inc.; or
- reproduce, modify, or display the LineLedger logo other than as required
  for unmodified redistribution of this repository as permitted by the
  AGPLv3.

If you fork or self-host this project, you must remove or replace the
LineLedger Marks with your own branding. No fork is needed to do it: set
`APP_NAME`, `BRAND_LOGO` (and optionally `BRAND_LOGO_DARK`) and
`BRAND_FOOTER_OWNER` in your environment, and replace the favicons in `public/`.
See [Environment](#environment) and `config/brand.php`. Permitted nominative references
(e.g., "compatible with LineLedger", "imported from LineLedger") must be
truthful, non-misleading, and must not suggest endorsement or affiliation.

Third-party names, logos, and marks referenced in this repository are the
property of their respective owners and are used for identification purposes
only.

For trademark licensing inquiries, contact **hello@lineledger.ca**.

## License

[GNU AGPLv3](LICENSE). In plain English: you're welcome to read this code, run it
yourself, modify it, and share modifications — but if you run a modified version
as a network service for others, you must release your source under the same
license. See the [trademark notice](#trademarks--legal-notice) above for what the
AGPLv3 does **not** grant.

### Why AGPL?

The AGPL's network clause keeps LineLedger genuinely open: anyone who runs a
modified version as a hosted service has to share their changes back, rather than
taking the code closed and selling it as a proprietary product. It protects the
project (and its users) from one-way commercial forks while leaving you free to
self-host and modify for your own use.

Because all copyright is held by Local Foundry Inc., the AGPL binds downstream
users but not the copyright holder — which is why contributions are handled the
way [`CONTRIBUTING.md`](CONTRIBUTING.md) describes.
