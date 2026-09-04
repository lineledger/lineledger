# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **API: invoice `balance_cents`, receipt `unapplied_cents`** — an invoice now
  reports what is still owed by the server's own arithmetic (total, less
  receipts applied, less anything reconciled away by a journal entry), and a
  receipt reports the part not yet applied to any invoice. Clients that
  computed `total_cents - amount_paid_cents` overstated the balance on a
  partly reconciled invoice; both fields are also documented in the OpenAPI
  spec and the v1 guide, alongside how to apply a receipt's credit later by
  re-saving it with its complete applications list.

### Changed

- **API: negative invoice lines** — `POST`/`PATCH /api/v1/invoices` now accept a
  negative `unit_price_cents` on a line (a discount or credit against the
  invoice), matching what the web form already allowed. The invoice total must
  still be greater than zero; a net credit is refused with a validation error
  pointing to credit memos instead of the poster's generic "zero total" failure.
- **Posting: negative revenue legs post as debits** — an invoice line that nets
  negative on its account (a discount line on a contra-revenue account) now
  writes a **debit** to that account rather than a negative credit. Applies to
  both first-time posts and reposts, and to invoices entered through the web
  form or the API.

## [1.0.0] - 2026-08-15

First public release of LineLedger — free, open-source double-entry accounting
built on Laravel 13, Livewire 4, and Flux UI. Multi-tenant by design, with the
general ledger as the single source of truth.

### Added

- **General ledger** — chart of accounts, manual and recurring journal entries,
  trial balance, and multi-company report groups.
- **Accounts receivable** — customers, estimates, sales orders, invoices, credit
  memos, customer receipts, statements with XLSX export, AR aging, and
  open-credit netting.
- **Accounts payable** — vendors, purchase orders, bills, bill payments, vendor
  credits, cheque printing, AP aging, and 1099 tracking (US).
- **Banking** — deposits, transfers, and bank reconciliation worksheets.
- **Inventory** — items with FIFO/average costing, stock adjustments, and
  received-stock on bill posting.
- **Fixed assets** — asset categories and depreciation.
- **Payroll (Canada)** — employees, pay schedules, and pay runs with CRA-formula
  CPP / EI / income tax (T4127) across all non-Quebec provinces, payroll cheques,
  employee reimbursements, and PD7A / T4 / T4A / ROE forms with CRA XML e-filing.
- **Multi-currency** — home-cents GL with foreign memo columns, daily exchange
  rate fetching, and realized + unrealized gain/loss.
- **Recurring documents** — scheduled recurring invoices and bills generated as
  drafts.
- **Customer payment portal** — magic-link portal at `/pay/{company}` with
  Stripe Connect card payments.
- **Budgeting** — account-level budgets built from scratch or from actuals, with
  budget-vs-actual, overview, and by-month reports.
- **Non-profit & fund accounting** — net-asset classes, ASNPO statement of
  operations and financial position, a fund dimension with interfund transfers,
  and deferred-revenue recognition.
- **Membership & fundraising** — membership levels with dues billing, donations
  and grants with deferral recognition, and CRA donation receipts (T3010).
- **Reporting** — income statement, balance sheet, cash flow statement, AR/AP
  aging, sales/purchases/inventory reports, custom report sections, QuickZoom
  drill-through, and memorized reports and favorites.
- **Tax & CRA filing** — sales-tax codes and tax returns, GIFI statements
  (Schedule 100/125), and entity-aware forms (T2125 with CCA, T5013) driven by an
  organization-type filing profile.
- **Classes & locations** — optional tracking dimensions on transaction lines for
  slicing reports without touching the chart of accounts.
- **Documents** — a folder-based document repository and transaction attachments,
  with auto-attach on reconcile.
- **QuickBooks migration** — an import wizard for QuickBooks data, including full
  general-ledger replay and opening balances.
- **Multi-tenancy & RBAC** — guided company onboarding, per-member section
  access (Owner / Admin / Accountant / Custom), and invitations.
- **Auth** — Laravel Fortify with passkeys (WebAuthn) and two-factor.
- **REST API v1** — versioned `/api/v1` endpoints with API-key abilities/scopes
  and full audit logging.
- **Business Q&A (MCP)** — a read-only Model Context Protocol server exposing
  reporting over per-company OAuth2 (Passport) connectors and API-key auth.
- **Audit log** — every accounting mutation is recorded, including which API key
  (if any) made the change.
- **Backup & restore** — export a company to a portable ZIP and restore it into a
  brand-new company.
- **Object storage** — attachments, organization logos, and backup ZIPs each get
  their own disk setting (`ATTACHMENT_DISK`, `LOGO_DISK`, `BACKUP_DISK`),
  defaulting to the local filesystem. Point them at S3, MinIO, Cloudflare R2, or
  Backblaze B2 when you outgrow a single server; every file records the disk it
  was written to, and `php artisan storage:check` proves a configuration end to
  end, including the public/private split.
- **PDFs** — invoices, statements, and cheques via DomPDF + TCPDF.
- **Public verification page** — `/verification` runs end-to-end accounting
  proofs (multi-year close, imported trial balance, journal import) on real
  seeded data, with downloadable source data and reports.
- **Customer email consent** — invoice emails and payment reminders are opt-in,
  per customer, via *Email invoices to this customer* and *Send payment
  reminders* switches on the customer's **Billing** tab. Sending by hand always
  works and leaves the preference untouched. The REST API accepts and returns
  `invoice_emails_enabled` and `reminder_emails_enabled` on the customer
  resource.
- **Separate Canadian and US sites.** `lineledger.ca` for Canada and
  `lineledger.com` for the US, with the app at `books.lineledger.ca` and
  `books.lineledger.com`. Guests see a one-time banner offering the other
  country's site, and the Terms, Privacy Policy, and other legal documents link
  to the right region.
- **Site admin portal** — platform dashboard, user and organization management
  (including disabling a user account, which revokes every session, OAuth token,
  and API key across all their organizations), organization soft-delete /
  restore / permanent purge, support tickets, and site-wide settings, all
  recorded in the security log.

### Notes for self-hosters

- `ATTACHMENT_DISK`, `LOGO_DISK`, and `BACKUP_DISK` default to local storage;
  set them only if you're moving to object storage, then run
  `php artisan storage:check`. See the README's **Object storage** section.

[1.0.0]: https://github.com/lineledger/lineledger/releases/tag/v1.0.0
