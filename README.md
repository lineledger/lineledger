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

LineLedger is at **1.0.0**, launched August 9, 2026 (see [`CHANGELOG.md`](CHANGELOG.md)). It is source-available for
transparency and to share how a real Laravel SaaS-style app is structured.
**This repository is not actively soliciting external contributions or issues**
— you're welcome to read, learn, and fork under the
terms of the [license](#license) below. The software is provided **as is, used at
your own risk, and is not a substitute for professional accounting or tax
advice** — please read the [Disclaimer & no warranty](#disclaimer--no-warranty).

## What's in here

- **General ledger** — chart of accounts, manual journal entries, recurring
  journal entries, trial balance, and class/location dimensions tracked on every
  transaction line.
- **Accounts receivable** — customers, estimates, sales orders, invoices, credit
  memos, customer receipts, statements (XLSX export), AR aging, open-credit
  netting.
- **Accounts payable** — vendors, purchase orders, bills, bill payments, vendor
  credits, cheque printing, AP aging, 1099 tracking (US).
- **Banking** — deposits, transfers, bank reconciliation worksheets.
- **Inventory** — items, FIFO/average costing, stock adjustments, received-stock
  on bill posting.
- **Fixed assets** — asset categories, depreciation, and a nightly job that drafts
  the period's depreciation entries.
- **Payroll** *(Canada)* — employees, pay schedules, and pay runs with CPP / EI /
  income-tax calculation (CRA T4127), full **Quebec** support (QPP / QPP2 / QPIP /
  EI-QC + federal abatement and Revenu Québec provincial tax), payroll cheques, and
  PD7A / T4 / T4A / RL-1 / ROE filings with CRA- and Revenu Québec-ready XML. Rates
  need a yearly refresh — see [Operating & maintenance](#operating--maintenance).
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
  aging, sales/purchases/inventory reports, combined multi-organization report groups,
  custom report sections, QuickZoom drill-through, memorized reports and favorites,
  and scheduled report emails.
- **Daily insights** — a per-organization "Did you know?" dashboard card computed
  nightly, with a browsable history and a profit-insights report.
- **Payment reminders** — per-customer opt-in dunning that emails overdue invoices
  on a nightly schedule.
- **Tax** — sales-tax codes, sales-tax returns, and GIFI Schedule 100/125
  statements *(Canada)*.
- **Documents** — a per-organization document repository (folders + private-by-default
  sharing) plus a unified transaction-attachment index.
- **Document inbox** — drop receipts and bills in by drag-and-drop or by forwarding
  them to a per-organization email address, with optional OCR extraction, then turn
  them into bills or expenses.
- **Multi-tenancy & RBAC** — guided organization onboarding, per-member section
  access (Owner / Admin / Accountant / Custom), invitations.
- **Site admin portal** — a platform-operator area at `/admin` (site admins only,
  2FA + password re-challenge): every user and organization, platform-wide account
  disable, organization soft-delete / restore / permanent purge, support-ticket
  triage, and site-wide maintenance-mode and registrations-open switches.
- **Support tickets** — in-app tickets users raise and site admins answer.
- **Customizable navigation** — per-user sidebar preferences (Settings →
  Navigation) backed by the `nav_preferences` table.
- **Auth** — Laravel Fortify with passkeys (WebAuthn) and two-factor, with an
  optional organization-wide 2FA requirement (`require_two_factor`) that makes
  owners/admins enrol before they can use the app. Removing a member or downgrading
  their role immediately tears down their sessions, company API keys, and OAuth
  tokens (`AccessRevoker`).
- **REST API v1** — versioned `/api/v1` endpoints with API-key abilities/scopes
  and full audit logging (see [`docs/api-v1.md`](docs/api-v1.md)).
- **Business Q&A (MCP)** — a read-only [Model Context Protocol](https://modelcontextprotocol.io)
  server (20 reporting tools, 7 resources, 4 prompts) that answers plain-language
  finance questions from Claude Desktop / claude.ai over an API key or one-click OAuth.
- **Agentic MCP writes** *(off by default)* — a second MCP server that can draft
  invoices, bills, expenses, and journal entries through a **propose → confirm**
  handshake: the assistant stages a proposal, a human confirms it, and only then does
  it post. Requires **both** the operator switch `MCP_WRITE_ENABLED=true` and a
  per-organization opt-in, and every write goes through the same actions, permission
  checks, and audit log as the UI.
- **Data migration** — guided QuickBooks import: full-history general-ledger
  replay or an opening trial balance, with account mapping.
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
| Framework | Laravel 13 |
| UI | Livewire 4 + Flux UI Free + Tailwind CSS 4 (Vite) |
| Auth | Laravel Fortify + passkeys (WebAuthn, configured in `config/fortify.php`); OAuth2 via Laravel Passport (MCP) |
| Database | MySQL 8 (local) / SQLite (CI) |
| Queue & cache | database driver by default (Redis-ready) |
| Payments | Stripe Connect — an organization's own customers pay its invoices by card, via `stripe/stripe-php` |
| AI / MCP | Laravel MCP server (`laravel/mcp`) — read-only Q&A tools + an opt-in write server |
| Mail | Any Laravel mailer; Resend bundled (`resend/resend-php`), `log` by default |
| PDFs | `barryvdh/laravel-dompdf` + `tecnickcom/tcpdf` + `setasign/fpdi` (slip templates) |
| Spreadsheets | `openspout/openspout` (XLSX export) |
| Observability | Laravel Nightwatch (`laravel/nightwatch`), opt-in via `NIGHTWATCH_ENABLED` |
| Tests | Pest 4 / PHPUnit 12 |
| Static analysis | PHPStan / Larastan (baselined, runs in CI) |
| Tooling | Laravel Pint, Laravel Boost (MCP) |

## Develop

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
> install, not the demo data. Use the block above if you want the seeded demo.

> **Heads-up:** the queue worker matters in dev too — backups, restores,
> recurring-document generation, and emails are queued jobs. Without a worker
> running they'll sit in the `jobs` table.

| Command | Action |
| --- | --- |
| `composer run dev` | Server + queue + logs + Vite together (recommended) |
| `php artisan serve` | PHP dev server only |
| `npm run dev` | Vite dev server with hot reload |
| `npm run build` | Build front-end assets to `public/build/` |
| `composer run test` | Clear config → Pint check → full Pest suite (what CI runs) |
| `php artisan test --compact` | Run the test suite |
| `vendor/bin/pint --dirty` | Auto-format changed PHP files |

### Bank-statement PDF import (poppler)

Importing a bank statement as **PDF** needs [poppler](https://poppler.freedesktop.org/)'s
`pdftotext` for text extraction:

- macOS: `brew install poppler`
- Debian/Ubuntu: `sudo apt-get install -y poppler-utils`

CSV, Excel and OFX/QFX/QBO imports need nothing extra. The importer reads a PDF in
two tiers: first `pdftotext` (a pure-PHP `smalot/pdfparser` fallback is bundled, but
it can't read secured/encrypted PDFs), then — when text extraction comes up empty
*and* the optional AI layer is on — it sends the PDF to Claude, which reads secured
and scanned PDFs natively. Enable the AI fallback with `BANK_IMPORT_AI_ENABLED=true`
and an `ANTHROPIC_API_KEY` (see the [Environment](#environment) table). With neither
`pdftotext` nor AI available, PDF import asks the user for a CSV/OFX export instead.

## Project structure

A standard Laravel layout; the accounting-specific logic lives in a few places:

- **`resources/views/pages/`** — the UI. Every page is a Livewire 4 **Volt
  single-file component** (`⚡name.blade.php`, ~280 of them) registered with
  `Route::livewire()`. Forms, lists, detail pages, and reports all live here.
  (`app/Livewire/` holds only three support classes, not the pages.)
- **`app/Actions/`** — shared write paths invoked by both Livewire and the API
  (`SaveInvoice`, `SaveBill`, `FulfillSalesOrder`, …). The API controllers and
  the UI call the *same* action so behavior never diverges.
- **`app/Services/`** — 27 domain engines: `Posting/` (journal-entry posting),
  `Reporting/`, `Reconciliation/`, `Inventory/`, `Currency/`, `Tax/`, `Payroll/`
  (CRA deduction engine), `Migration/` (QuickBooks import), `Banking/`, `Inbox/`,
  `Insights/`, `Classification/`, `Recurring/`, `Reminders/`, `Printing/`, `Pdf/`,
  `Proof/`, `Backup/`, `Restore/`, `Stripe/`, and more.
- **`app/Mcp/`** — both MCP servers: `BusinessQaServer` (read-only reporting tools)
  and `BusinessActionsServer` (the opt-in propose→confirm write tools).
- **`app/Models/`** — Eloquent models. The GL core is `JournalEntry` /
  `JournalLine` (posting state is denormalized onto lines for fast balance sums);
  documents (`Invoice`, `Bill`, `Cheque`, …) own their lines and post through the
  services.
- **`app/Enums/`** — typed domain vocabulary (`AccountType`, `InvoiceStatus`,
  `CompanyRole`, `Section`, `ApiAbility`, `Country`, …).
- **`routes/`** — `web.php` (tenant-scoped UI under `{company}`), `api.php`
  (`/api/v1`), `ai.php` (MCP servers), `admin.php` (the site-admin portal, which is
  deliberately *not* tenant-scoped), `docs.php`, `settings.php`, `console.php`
  (the scheduler).
- **`database/migrations/`** — schema (270+ migrations); `database/seeders/`
  has `DemoCompanySeeder`, which builds two sample organizations: a for-profit demo and
  **Demo Community Society**, a registered charity for the non-profit / fundraising docs.
- **`tests/`** — Pest feature + unit tests. CI runs against SQLite.

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
| `APP_REGION` | Country this deployment serves (`CA` / `US`). Drives the guest country-switcher banner and which marketing site legal links point at. Leave **unset in production** to derive it from the request host (a `.ca` host → CA, anything else → US); set it explicitly on host-agnostic environments like local dev | `CA` | unset (derived from host) |
| `APP_URL_CA` / `APP_URL_US` | The two sibling app deployments, used by the country-switcher banner's "Go to …" link | `https://books.lineledger.ca` / `https://books.lineledger.com` | your two app hosts |
| `MARKETING_URL_CA` / `MARKETING_URL_US` | Marketing sites hosting the legal documents linked from the footer, chosen by region | `https://lineledger.ca` / `https://lineledger.com` | your marketing sites |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | MySQL connection | `mysql` @ `127.0.0.1:3306`, db `lineledger` | the DB + user you create in Forge → **Database** |
| `QUEUE_CONNECTION` | Queue driver (backups, restores, recurring, mail) | `database` | `database` or `redis` |
| `SESSION_DRIVER` / `CACHE_STORE` | Session + cache backing | `database` | `database` or `redis` |
| `MAIL_MAILER` + `MAIL_*` | Outgoing mail (invitations, statements, portal links) | `log` (writes to log, sends nothing) | `smtp`/`resend`/`postmark` + verified sender |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | From identity on all mail | `hello@example.com` | an address on your verified sender domain |
| `RESEND_API_KEY` | API key when `MAIL_MAILER=resend` (the one bundled API mailer) | — | your Resend key |
| `SECURITY_ALERT_EMAIL` / `LEDGER_INTEGRITY_ALERT_EMAIL` | Where `security:monitor` and `integrity:check` send their alerts. Both fall back to `EXCHANGE_RATE_HEALTH_ALERT_EMAIL`, then to a project default — **set at least one or the nightly alerts go nowhere useful** | — | your ops address |
| `NIGHTWATCH_ENABLED` / `NIGHTWATCH_TOKEN` | Laravel Nightwatch application monitoring (errors, queries, queue). Off unless enabled | `false` | `true` + your token |
| `ATTACHMENT_DISK` / `LOGO_DISK` / `BACKUP_DISK` | Which disk holds each kind of uploaded file. See [Object storage](#object-storage) | `local` / `public` / `local` | `s3` / `s3_public` / `s3` (so uploads and backups survive deploys) |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_DEFAULT_REGION` | S3 credentials + region (only when a disk above is set to S3) | region `ca-central-1` | your IAM key; keep the region in the country you serve |
| `AWS_BUCKET` / `AWS_PUBLIC_BUCKET` / `AWS_PUBLIC_URL` | Private bucket (attachments + backups) and public-read bucket (logos) with its base URL | — | your two buckets |
| `AWS_URL` | Optional base URL override for the **private** disk (CDN or custom domain) | — | — |
| `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` | Scratch space for in-progress uploads. Deliberately stays **local** even when the disks above are on S3 | `local` | `local` |
| `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` | Point at an S3-compatible store instead of AWS (MinIO, Cloudflare R2, Backblaze B2) | — | endpoint URL + `true` |
| `PASSKEYS_USER_HANDLE_SECRET` | Secret used to derive passkey user handles | any random string | a stable, secret random string (**don't rotate** — invalidates existing passkeys) |
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
| **Agentic MCP writes** | `MCP_WRITE_ENABLED=true` **and** a per-organization opt-in. `MCP_REDIRECT_DOMAINS` allow-lists OAuth redirect hosts | `config/mcp.php` |
| **AI daily insights** | `INSIGHTS_AI_ENABLED=true` + `ANTHROPIC_API_KEY`; `INSIGHTS_AI_MODEL` / `_MAX_CANDIDATES` / `_TIMEOUT` | `config/insights.php` |
| **Bank-import AI fallback** | `BANK_IMPORT_AI_ENABLED=true` + `ANTHROPIC_API_KEY`; `BANK_IMPORT_PDF_EXTRACTOR`, `BANK_IMPORT_AI_MODEL` / `_DRIVER` / `_TIMEOUT` / `_SAMPLE_ROWS`, `BANK_IMPORT_MAX_KILOBYTES`, `BANK_IMPORT_DATE_TOLERANCE_DAYS` | `config/banking.php` |
| **Transaction classification** | On by default; tune history depth with `CLASSIFICATION_HISTORY_DAYS`, `CLASSIFICATION_MAX_HISTORY_ROWS`, `CLASSIFICATION_DESCRIPTION_HISTORY_LIMIT`, `CLASSIFICATION_AI_MAX_DESCRIPTIONS` | `config/classification.php` |
| **Security-alert thresholds** | `SECURITY_ALERT_WINDOW_MINUTES`, `SECURITY_ALERT_FAILED_LOGIN_THRESHOLD`, `SECURITY_ALERT_API_KEY_REVOCATION_THRESHOLD` (defaults 60 / 10 / 5) | `config/services.php` |

> **Filing payroll slips needs your transmitter identity.** The T4 / T4A XML carries a
> CRA transmitter number and the RL-1 XML carries Revenu Québec preparer numbers. The
> defaults are **placeholders** (`MM000000`, `NP000000`) that CRA and RQ will reject —
> set `CRA_TRANSMITTER_NUMBER`, `CRA_TRANSMITTER_TYPE`, `CRA_TRANSMITTER_LANGUAGE`,
> and (for Quebec) `RQ_TRANSMITTER_NUMBER`, `RQ_PREPARER_NUMBER`,
> `RQ_AUTHORIZATION_NUMBER`, `RQ_RL1_SLIP_TYPE` before you file. See
> `config/payroll.php`.

## Scheduled jobs

Several commands run on the scheduler (`routes/console.php`). They need either a
cron entry calling `schedule:run` every minute (Forge does this for you) or a
long-running `schedule:work` process:

| Command | Cadence | Does |
| --- | --- | --- |
| `payroll:accrue-time-off` | daily 01:00 | Accrue time-off balances per each organization's policies |
| `recurring:generate` | daily 02:00 | Generate due recurring invoices/bills/journal entries as **drafts** |
| `depreciation:generate` | daily 02:30 | Draft the period's depreciation entries for due fixed assets |
| `integrity:check` | daily 04:00 | Verify the ledger reconciles (hash chain + GL balance + balance cache); email ops on failure |
| `insights:generate` | daily 05:00 | Compute each organization's daily "Did you know?" dashboard insight (one queued job per organization; idempotent per day) |
| `rates:fetch` | daily 06:00 | Pull the day's FX rates (multi-currency) |
| `reports:send-scheduled` | daily 07:00 | Email memorized reports on their schedules |
| `reminders:send` | daily 07:30 | Email payment reminders for overdue invoices (opted-in customers only) |
| `rates:health` | daily 08:30 | Alert if FX rates are stale (the fetch missed or failed) |
| `security:monitor` | hourly | Scan the security log for anomalies (failed-login spikes, lockouts, mass API-key revocation, privilege escalation); email ops on any finding |
| `backups:prune-expired` | daily | Delete expired organization backup files |

The per-organization jobs — `recurring:generate`, `depreciation:generate`,
`insights:generate`, `reports:send-scheduled`, `reminders:send` — all take an optional
`{company?}` (id or slug) plus `--sync`, so you can run one organization in-process
when you're debugging. `payroll:accrue-time-off` takes `{company?}` and `--date=`.

### On-demand operator commands

Not scheduled — run these by hand when you need them:

| Command | Does |
| --- | --- |
| `audit:verify {company?}` | Verify the accounting audit-log **hash chain**; checks one organization, or all when omitted. (Also runs nightly as part of `integrity:check`.) |
| `integrity:check {company?} [--fix] [--no-alert]` | The nightly reconciliation check, on demand. `--fix` recomputes drifted account-balance caches in place; `--no-alert` reports without emailing. |
| `backup:export --company=<id> [--sync]` | Produce a ZIP backup of one organization on the configured backup disk; `--sync` runs in-process so exceptions surface in the shell (handy for smoke tests). |
| `backup:import <file> --user=<id> [--sync] [--dry-run]` | Restore a backup ZIP into a **new** organization owned by the given user. `--dry-run` inspects the bundle without writing. |
| `storage:check [--skip-probes]` | Audit the storage configuration and **prove** it: round-trips a throwaway object through every configured disk, then fetches it over plain HTTP with no credentials to confirm logos are public and attachments/backups are not. See [Object storage](#object-storage). |
| `security:monitor [--window=] [--no-alert]` | Run the hourly anomaly scan by hand over a custom look-back window. |
| `rates:fetch [--date=]` / `rates:health [--no-alert]` | Fetch FX rates for a specific date, or check freshness without emailing. |
| `payroll:verify-slip-templates {year?}` | Verify the official T4 / T4A / RL-1 PDF templates still import, map, and render. Run it alongside the two `payroll:verify-*` commands after a year-end update. |

A couple of one-off data backfills also ship for upgrading existing data and are
**not** part of normal operation — run once if/when relevant:
`migration:backfill-contact-links` (backfill `contact_id` onto AR/AP journal lines
so GL-driven statements match aging) and `payroll:backfill-accounts` (create the
system payroll GL accounts on existing Canadian organizations).

## Operating & maintenance

The [scheduler](#scheduled-jobs) covers the recurring *automated* work. The tasks
below are the *manual* ones an operator owns on a calendar. Most are rare — but
the payroll one is mandatory every year before the first January pay run.

| Cadence | Task | What to do |
| --- | --- | --- |
| **Annually, by Jan 1** | Refresh payroll tax constants | Append the new effective-date tables to `FederalConstants.php` + `ProvincialConstants.php` from CRA's new T4127 (details below). Payroll is **blocked** for any date/province not loaded. |
| **Mid-year, if CRA issues a Jul 1 T4127** | Add the mid-year payroll table | Same as above, keyed `YYYY-07-01`. |
| **Each filing season** | Verify CRA e-file schemas | Before filing PD7A / T4 / T4A / ROE, confirm the generated XML still matches CRA's current schema for the year — these are shipped marked "verify before production." |
| **When a jurisdiction changes a rate** | Update sales-tax codes | Edit the affected organization's codes in **Settings → Sales Tax**, and update `app/Support/Defaults/CanadianDefaults.php` so *new* organizations seed the new rate. |
| **Every deploy** | Regenerate verification proofs | Run `php artisan proof:generate` so `/verification` reflects the current code (add it to the deploy script). |
| **Periodically** | Dependency & security updates | `composer update` / `npm update`, run the suite, redeploy; keep PHP and MySQL patched. |
| **Periodically** | Confirm backups + worker health | Check organization backups complete and land on `BACKUP_DISK` (`php artisan storage:check` proves the disks), and that the queue worker + `schedule:run` are alive — FX rates, recurring docs, backup pruning, scheduled report emails, payment reminders, time-off accrual, and auto-depreciation all depend on them. |

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
Québec. As shipped, the **2025** and **2026** tables are loaded, so each new year
(once the next edition is published) you must:

1. Get the new T4127 edition from CRA.
2. **Append** one entry keyed by its effective date (e.g. `'2026-01-01' => [...]`)
   to the federal file and to every province you support. Do **not** edit prior
   years — old pay runs must still recompute identically.
3. Run the two verification commands (below) and spot-check a pay run against CRA's
   [Payroll Deductions Online Calculator](https://www.canada.ca/en/revenue-agency/services/e-services/digital-services-businesses/payroll-deductions-online-calculator.html).

Two artisan commands guard the payroll data — run both whenever you load a new
table (and ideally in CI / on deploy):

```bash
php artisan payroll:verify-constants     # re-derives CPP/CPP2/EI + QPP/QPP2/EI-QC/QPIP
                                         # maxima from rate × ceiling; must match published maxima
php artisan payroll:verify-calculations  # runs the engine against its CRA PDOC reference
                                         # matrix; must match to the cent (exits non-zero on any miss)
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
php artisan test --compact                 # everything
php artisan test --compact --filter=Invoice # one feature/filter
composer run test                          # config:clear + Pint check + full suite (CI parity)
```

CI runs against **SQLite**, local dev against **MySQL** — date columns must store
date-only strings (cast `date:Y-m-d` + `->toDateString()`) or SQLite comparisons
break where MySQL silently coerces. Every change should come with a test.

CI also runs **Pint** and **PHPStan / Larastan** as a separate `linter` workflow.
PHPStan is baselined (`phpstan-baseline.neon`), so the gate fails only on *new*
findings; run it locally with `vendor/bin/phpstan analyse --memory-limit=1G`.

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
php artisan test --compact tests/Feature/Proof   # the same scenarios, run as the CI gate
```

`proof:generate` builds each scenario inside a database transaction that is always
rolled back (artifacts are flushed to storage first), so it leaves no organization
behind. The one `ScenarioBuilder` backs both the command and the Pest tests, so the
published page and the test suite can never drift. Run it on deploy to keep the
page current.

## Self-hosting with Docker

The easiest way to run LineLedger yourself. One prebuilt image
(`ghcr.io/lineledger/lineledger`, linux/amd64) runs the web app, the queue
worker, and the scheduler as three containers alongside MySQL — Docker and
Docker Compose are the only requirements.

### Quick start

No clone needed — fetch the compose bundle, configure, and start:

```bash
mkdir lineledger && cd lineledger
curl -fsSLO https://raw.githubusercontent.com/lineledger/lineledger/main/docker/docker-compose.yml
curl -fsSL  https://raw.githubusercontent.com/lineledger/lineledger/main/docker/.env.example -o .env

# Edit .env: set DB_PASSWORD, DB_ROOT_PASSWORD, APP_URL, and your mail settings.
# Then generate the encryption key and paste it into .env as APP_KEY=...
docker compose run --rm --no-deps app php artisan key:generate --show

docker compose up -d
```

Open <http://localhost:8080> and register — the first registered user creates
the first organization. Migrations, the `storage:link`, and the Passport OAuth
keys are handled automatically by the app container on boot; the queue and
scheduler containers wait until the app is healthy before starting.

To build the image locally instead of pulling from GHCR:
`git clone`, `cd lineledger/docker`, then `docker compose build && docker compose up -d`.

**Back up your `.env`** — `APP_KEY` encrypts sessions and secrets, and
`PASSKEYS_USER_HANDLE_SECRET` anchors every registered passkey. Losing either
is unrecoverable. The `.env` template documents both, plus the alert-email
addresses you should point at your own inbox.

### Upgrading

```bash
docker compose pull && docker compose up -d
```

Migrations run automatically in the app container's entrypoint, and workers
wait for it to become healthy. Pin `LINELEDGER_VERSION` in `.env` to a release
tag (e.g. `1.0.0`) for controlled upgrades; `latest` tracks tagged releases,
never `main` (`edge` does, if you want to live dangerously). Scale workers with
`docker compose up -d --scale queue=2`; don't scale the `app` service — it's
the one that runs migrations.

### Backups

Two things hold all your data:

- **The `storage` volume** — attachments, in-app backup archives, proof
  artifacts, and the Passport OAuth keys.
- **The database** — dump it with triggers included:

  ```bash
  docker compose exec mysql mysqldump --triggers --routines \
      -u root -p"$DB_ROOT_PASSWORD" lineledger > lineledger.sql
  ```

  `--triggers` matters: the audit-log immutability triggers must survive a
  restore, and restoring them needs a privileged (root) MySQL user.

The in-app backup feature (Settings → Backups) writes archives to
`storage/app/private/backups` inside the volume — copy those off-host too.

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
docker compose --profile mail up -d    # Mailpit (set MAIL_HOST=mailpit, MAIL_PORT=1025; UI at :8025)
docker compose --profile redis up -d   # Redis (set CACHE_STORE/QUEUE_CONNECTION/SESSION_DRIVER=redis, REDIS_HOST=redis)
docker compose --profile minio up -d   # MinIO S3 (see AWS_ENDPOINT + storage roles in the root .env.example)
```

### Health & verification

The app exposes `/up` for health checks (the container healthcheck uses it).
After first boot:

```bash
docker compose exec app php artisan storage:check     # every disk role round-trips
docker compose exec app php artisan integrity:check   # audit chain + GL balance
```

## Deploy (Laravel Forge / DigitalOcean)

A standard Laravel deploy: PHP-FPM + Nginx, a **queue worker daemon**, the
**scheduler cron**, and (recommended) **S3** for files so backups and uploads
survive deploys — see [Object storage](#object-storage) below.

### One-time setup

**1. Create the server (Forge → Servers → Create Server).**
DigitalOcean, any size, **PHP 8.5** (or newer), **MySQL 8**, Redis optional.

> **System packages.** For **PDF** bank-statement import, install poppler on the
> server: `sudo apt-get install -y poppler-utils` (provides `pdftotext`). Without it,
> PDF import falls back to the AI layer when enabled (`BANK_IMPORT_AI_ENABLED=true` +
> `ANTHROPIC_API_KEY`), and otherwise asks the user for a CSV/OFX export. CSV, Excel
> and OFX/QFX/QBO imports need no extra packages.

**2. Create the site (Forge → Server → Sites → New Site).**
Root domain `books.lineledger.ca`, project type **General PHP / Laravel**, web
directory `/public`. Connect this repo (`main` branch).

**3. Provision the database (Forge → Server → Database).**
Create a database and user; copy the generated password into `DB_PASSWORD`.

**4. Fill in the environment (Forge → Site → Environment).**
Paste `.env.example`, then set every production value from the
[Environment](#environment) table — at minimum `APP_KEY` (generate one),
`APP_URL`, the `DB_*` block, a real `MAIL_*` mailer, and
`PASSKEYS_USER_HANDLE_SECRET`. Add the `STRIPE_*` keys for portal card payments. Set
`APP_ENV=production` and `APP_DEBUG=false`.

**5. Set the deploy script (Forge → Site → Deployment → Deploy Script).**

```bash
cd $FORGE_SITE_PATH
git pull origin $FORGE_SITE_BRANCH
$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build
$FORGE_PHP artisan migrate --force
$FORGE_PHP artisan storage:link
$FORGE_PHP artisan config:cache
$FORGE_PHP artisan route:cache
$FORGE_PHP artisan view:cache
$FORGE_PHP artisan queue:restart
```

`npm run build` is mandatory — Forge won't build assets for you. `queue:restart`
gracefully cycles the worker daemon (step 6) so it picks up new code.

**6. Create the queue worker daemon (Forge → Site → Queue, or Server → Daemons).**

```
php artisan queue:work --queue=default --tries=3 --timeout=120 --sleep=3
```

Run it as the `forge` user from the site directory. This processes backups,
restores, recurring-document generation, and mail.

**7. Enable the scheduler (Forge → Site → Scheduler, or Server → Scheduled Jobs).**
Add `php artisan schedule:run` to run **every minute**. This drives the
[scheduled jobs](#scheduled-jobs).

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
the API-key MCP connection.)

**10. Issue SSL (Forge → Site → SSL → Let's Encrypt)** for `books.lineledger.ca`.

**11. Click Deploy Now.** Visit the site, register, create an organization, and confirm
the queue daemon and scheduler are green in Forge.

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
post-deploy gate. Run it after any change to the vars above.

Notes:

- **Switching is forward-only.** The disk is recorded per file, so anything
  uploaded before the switch keeps being served from where it already is —
  nothing breaks, but the old files are not moved for you. Keep `storage/app`
  around, or copy its contents into the bucket yourself.
- **`storage:link` is still required** either way — proof artifacts and the local
  fallback use it.
- **Uploads still stage locally.** Livewire writes the in-progress upload to
  `storage/app` before the app moves it to its permanent home, and backup ZIPs
  are assembled on local disk before being uploaded. Keep some free space.
- **S3-compatible stores work**: set `AWS_ENDPOINT` and
  `AWS_USE_PATH_STYLE_ENDPOINT=true` for MinIO, Cloudflare R2, or Backblaze B2.

### Subsequent deploys

Push to `main` → **Deploy Now** (or enable **Quick Deploy**). The deploy script
runs migrations, rebuilds assets and caches, and restarts the worker.

### Troubleshooting

- **Backups/restores never finish** — the queue worker isn't running. Check
  Forge → **Daemons** and that `jobs` / `failed_jobs` aren't backing up.
- **Recurring documents didn't generate** — the scheduler cron isn't firing.
  Verify `schedule:run` is set to every minute; check `php artisan schedule:list`.
- **Uploaded logos / attachments vanish after deploy** — you're on the `local`
  disk and `storage/app` isn't on a shared/persistent path. Either make it one,
  or move uploads to object storage — see [Object storage](#object-storage).
- **Passkeys stop working after a config change** — `PASSKEYS_USER_HANDLE_SECRET`
  changed. It must stay stable; rotating it invalidates every registered passkey.
- **Env change didn't take effect** — run `php artisan config:clear` (or redeploy);
  cached config is read at boot, not per request.
- **Stripe payments fail silently** — the webhook secret is wrong or the endpoint
  URL doesn't match `APP_URL`; check Stripe → **Developers → Webhooks → Logs**.
- **MCP OAuth connect fails (`Authorization server keys missing`)** — Passport keys
  weren't generated. Run `php artisan passport:keys` once on the server (step 9).

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
LineLedger Marks with your own branding. Permitted nominative references
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
