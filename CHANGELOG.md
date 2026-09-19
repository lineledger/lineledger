# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-09-18

**Highlights.** An **Opening balances** workspace lets an organization that was set
up without an import backfill its trial balance, opening receivables and payables,
and outstanding cheques and deposits after the fact, with the ledger following every
edit. **Edit locks** stop two people from silently overwriting each other's work on
the same record. **Cheques** now print on Intuit pre-printed stock at Intuit's own
positions and sizes, carry the payee's mailing address for a window envelope, and can
be made out to an *Other name* that is not a vendor, customer, or employee. **Customer
statements** can be produced from the customer list or the AR statement page and
viewed, downloaded, or emailed. Reporting gains a **Vendor Activity** report and a
**Sales by Rep** drill-down with an income-statement view and prior-period comparison.

### Added

- **Opening balances workspace** — an owner-only page (Opening balances in the
  sidebar) for organizations that need to backfill history after setup. Enter or
  CSV-import a draft trial balance as targets, record per-customer opening
  receivables and per-vendor opening payables as real opening documents, list
  outstanding cheques and deposits in transit as posted cheques and deposits at
  their original dates, and import inventory and fixed assets. Nothing locks: one
  opening journal entry is kept in step with the targets and reposted in place
  after every save, so the ledger lands exactly on the figures as at the opening
  date. The opening AR/AP document number can be typed (or supplied as an optional
  CSV column) so a migrated balance matches the books it came from.
- **Edit locks** — opening a record to edit takes a short lease on it. Other
  members see who is editing instead of the form, show-page actions (void, post,
  delete) and list row actions wait, and Owners and Admins can take over. The lease
  renews while the page is open, is released on leave, expires about two minutes
  after the page closes, and pauses after 15 minutes idle. A save is also refused
  when someone else opened or changed the record since the page loaded, so a
  lapsed lease can never silently overwrite newer work. The REST API answers
  `423 Locked` with a `Retry-After` header while a record is held, and
  `php artisan edit-locks:prune` runs daily. On by default — see Upgrade notes.
- **Cheques: payee picker and Other names** — "Pay to the order of" on cheques (and
  "Paid to" on expenses) is one searchable picker over vendors, customers,
  employees, and a new *Other name* role, with a badge per role. Quick-add an Other
  name inline, or open "Create … as a new vendor / customer / employee" in a new
  tab with the name prefilled. Other names live under Settings → Lists → Other
  names, with a duplicate-name warning, a Transactions link, and a one-way Convert
  to vendor, customer, or employee.
- **Cheques: printed mailing address** — an Address block under the payee, filled
  from the contact's billing address and snapshotted onto the cheque so a reprint
  years later shows the address it was actually mailed to. Editing the address
  asks whether to update the contact's record or change just this cheque. It
  prints under PAY TO THE ORDER OF, so a mailed cheque fits a window envelope.
- **Cheques: whose receivable or payable a line settles** — a cheque line coded to
  Accounts Receivable or Accounts Payable now names its customer or vendor,
  pre-filled from the payee. A refund made out to a third party lands on the right
  customer's account instead of the wrong one, or the "Unattributed" row of the
  aging reports, and cached AR/AP balances recompute on post, repost, and void.
- **Cheques: repeatable numbers and Duplicate** — a cheque number may repeat on a
  bank account ("EFT", "DD", "e-transfer"), with a non-blocking warning when it is
  already used there. A Duplicate action on the cheque list and cheque page copies
  the bank, payee, memo, and lines onto a fresh draft dated today with the bank's
  next number; voided cheques can be duplicated to re-issue them.
- **Cheques: printing on Intuit stock** — every field is re-measured against a
  reference Intuit voucher cheque and printed at Intuit's own positions and sizes.
  Stub columns clip at their width instead of overprinting the memo, the voucher
  names the account ("6000 · Advertising", not "6000"), and the "Powered by TCPDF"
  footer is gone. The date comb prints YYYYMMDD with a matching legend and the PAY
  line always carries five stars before the amount in words.
- **Customer statements** — from the customers list (click the Open balance, or the
  new Statement… row action) and from the AR statement report page: *Open invoices*
  as of a date, or *Account activity* over a period, in the customer-facing PDF
  style with an aging strip. View inline, download, or email with the PDF and a
  portal link attached. The printed statement now carries each invoice's memo, the
  AR statement page gains Edit customer / Edit vendor links, a new invoice setting
  hides zero-quantity lines on printed invoices, and the organization's legal name
  is editable (the *Show legal name* toggle previously had nothing to show).
- **Vendor Activity report** (Reports → Vendors & Payables) — every posted
  transaction with a vendor: bills, bill payments, vendor credits, cheques,
  expenses, and journal entries, one row per entry, with a Vendor picker, voids
  marked, and CSV / XLSX / PDF export. On the vendor list the Name now opens it and
  the Open balance opens the AP statement, so a vendor paid by cheques coded
  straight to an expense account no longer reads "No transactions in this range."
- **Sales by Rep drill-down** — clicking a rep opens the invoices and credit memos
  behind the total, grouped by revenue account, with an *Accounts only* view that
  reads like the income statement and accepts Compare (prior period / prior year).
  The internal AR statement gains a Rep column, staff-only and never on the
  customer's copy.
- **Combined cash flow: per-line activity** — an account mapped in a report group
  can be placed under operating, investing, or financing on the combined cash flow
  statement independently of its default bucket.
- **Ledger drill-through** — the account code and name on the Chart of Accounts
  link to that account's General Ledger, which now defaults to fiscal year to date
  instead of the current month. The undeposited-receipts picker on Make deposit
  shows each receipt's Payment type.
- **Undeposited receipts export** — the receipt picker on Make deposit exports PDF,
  CSV, or XLSX in its current sort with the tick state, so the download doubles as
  a deposit slip.
- **Contact list search** — the Customer and Vendor Contact Lists have a Search box
  over name, company, and email that Print, Email, and memorized reports keep.
- **Smarter bank statement import** — suggestions carry the payee and tax codes,
  not just the account, and rules match on the merchant part of the description so
  next month's line pre-fills. Suggestions are pre-fill only until confirmed. An
  outflow with a vendor records an Expense (tax-inclusive of the statement
  amount); an outflow against open bills records one Bill Payment across them,
  partials allowed; a per-row vendor picker, tax picker, "Pay bills…" allocation,
  and an "Always do this" rule button sit on the wizard and the For Review feed.
  The cash-flow forecast lands post-dated entries in their period, shows cleared
  balance, uncleared payments, and deposits in transit, and expects receivables by
  due date plus typical days late. A sidebar Insights link and a per-user "show
  daily insights" switch round it out.
- **Banking remembers your account** — the cheque form, bank register, statement
  import, reconcile, deposit, expense, and transfer screens share the last bank
  account used instead of snapping back to the lowest-numbered one.
- **Posted cheques can be edited** — the same journal entry is rebuilt in place
  under the audit trail, with the closed-period, filed-return, and
  completed-reconciliation guards enforced on both the old and new date. The API's
  `PATCH /api/v1/cheques/{id}` follows the same path.
- **Document memos in the bank register** — the bank leg of a deposit, cheque,
  expense, transfer, receipt, refund, sales receipt, bill payment, tax payment, or
  payroll remittance reads "Deposit: August 2026 Interac Deposits" instead of a
  bare "Deposit". `banking:backfill-line-memos` rewrites entries posted before
  this, and `app:upgrade` runs it for you.
- **Deposit receipt picker** — a new deposit opens with nothing ticked, the header
  checkbox selects or clears all, every column sorts, and the receipt number opens
  the receipt in a new tab.
- **Recurring schedules: last day / last business day** — month-based schedules
  (recurring journal entries, invoices, bills, and report emails) gain a *Runs on*
  choice: a day of the month, the last day, or the last business day. Quarterly
  and longer cadences anchor to the last month of each period.
- **API: invoice `balance_cents`, receipt `unapplied_cents`** — an invoice now
  reports what is still owed by the server's own arithmetic (total, less
  receipts applied, less anything reconciled away by a journal entry), and a
  receipt reports the part not yet applied to any invoice. Clients that
  computed `total_cents - amount_paid_cents` overstated the balance on a
  partly reconciled invoice; both fields are also documented in the OpenAPI
  spec and the v1 guide, alongside how to apply a receipt's credit later by
  re-saving it with its complete applications list.
- **API: negative invoice lines** — `POST`/`PATCH /api/v1/invoices` now accept a
  negative `unit_price_cents` on a line (a discount or credit against the
  invoice), matching what the web form already allowed. The invoice total must
  still be greater than zero; a net credit is refused with a validation error
  pointing to credit memos instead of the poster's generic "zero total" failure.
- **API: `sales_rep_id` on invoices and credit memos** — accepted on create and
  update (an employee contact in the key's organization) and returned on both
  resources. The Employees list shows a read-only *Contact ID (API)* so an
  integrator can read the value off the UI.
- **API: `/transfers` in the OpenAPI spec** — the transfers endpoints are now
  documented, and the schema fields are reconciled with what the API returns.
- **MCP: sales rep** — the sales report tool groups by rep, and the invoice list
  tool reports each invoice's rep and filters by it.
- **Escape goes back** — on any signed-in page, Escape does what the browser's
  Back button does. Open modals, dropdowns, and pickers close first, a form with
  unsaved changes asks "Leave this page?", and it never leaves the app. Per user,
  on by default, under Settings → Appearance.
- **Click anywhere in a date field** to open the browser's calendar (Chrome and
  Edge only opened it from the small icon at the edge).
- **The organization switcher opens the chosen organization in a new tab** and
  leaves the current one alone.
- **× and ÷ in the in-cell calculator** — dollar cells evaluate `+ - * /` with
  proper precedence (`100*1.13` no longer reads as `100 + 1.13`), and the
  calculator reaches the remaining dollar cells: recurring journal amounts, bank
  review splits, apply columns, budgets, unit prices, and tax overrides.
- **Version in the footer** — every page shows the running version, linking to
  its GitHub release.
- **`php artisan app:upgrade`** — one command for every deploy: runs pending
  migrations, then `banking:backfill-line-memos` and
  `banking:backfill-reconciliation-stamps`; `--verify` runs `integrity:check`
  afterwards without sending alert emails; `--dry-run` shows what would run;
  `--company=` narrows the backfills. The Docker image runs it on boot unless
  `MIGRATE_ON_BOOT=false`, and the Forge deploy script calls it in place of
  `migrate --force`.
- **Docker backup and restore** — `docker/backup.sh` writes a timestamped database
  dump (triggers included), a storage-volume archive, and a copy of `.env` into
  `./backups` (or `BACKUP_DIR`); `docker/restore.sh <db-dump.sql.gz>
  [storage.tar.gz]` puts them back. A `backup` compose profile runs a nightly dump
  sidecar into a host folder that `docker compose down -v` cannot delete
  (`BACKUP_DIR` default `./backups`, `BACKUP_KEEP_DAYS` default 14,
  `BACKUP_INTERVAL_SECONDS` default 86400). Release images are published for
  linux/amd64 and linux/arm64, tagged `1.1.0`, `1.1`, and `latest`; `main`/`edge`
  builds stay amd64-only.
- **AI assistant writes (MCP)** — a per-organization switch on the organization
  edit page. With it on, and the operator's `MCP_WRITE_ENABLED` set, a connected
  assistant can propose invoices, bills, expenses, and journal entries that a
  person confirms before anything is written. Off by default at both levels.
- **Docs** — five new in-app pages (Opening balances, Insights, Employee portal,
  Site administration, Self-hosting & upgrades), the docs navigation now mirrors
  the sidebar, every screenshot is recaptured, and the PDF manual is regenerated.

### Changed

- **Bank register is read-only** — ticking rows, Clear all / Unclear all, and the
  Statement balance / Difference tiles duplicated the reconciliation by hand and
  could drift from it. The register is now a chequebook view whose green tick
  reports what a reconciliation cleared; *Show cleared* stays. Re-saving the
  reconciliation details no longer reverses and re-posts service-charge and
  interest entries that were not touched.
- **Reconciliation offers every posted line except unsettled voids** — service
  charges and their reversals are reconcilable, as is a voided entry and its
  reversal (the pair nets to zero, as the bank shows). A voided cheque that was
  never cashed is different: both halves stay off the screen until one is settled,
  then both come back so nothing is stranded. Replacing a service-charge or
  interest entry mid-edit now clears the old line's cleared stamp;
  `banking:backfill-reconciliation-stamps` removes stale stamps left by earlier
  reconciliations, and `app:upgrade` runs it for you.
- **Posting: negative revenue legs post as debits** — an invoice line that nets
  negative on its account (a discount line on a contra-revenue account) now
  writes a **debit** to that account rather than a negative credit. Applies to
  both first-time posts and reposts, and to invoices entered through the web
  form or the API.
- **Picking an item keeps a priced line as it is** — choosing an item on an
  invoice line that already has a price no longer overwrites its description,
  unit price, or tax codes; those fill in only when blank. Lines entered by an
  external system can be tagged with a catalog item without rewriting what was
  billed.
- **Exports are stamped in the organization's timezone** — the "Generated" line on
  every PDF and XLSX export read raw UTC, so an afternoon export could carry
  tomorrow's date.
- **Management package periods and comparison** — presets are completed or
  to-date periods only (last / this month, quarter, fiscal year), so as-of
  reports never age everything into 90+ against a future date; the cover uses the
  document logo like every other PDF; a new *Compare to* option (prior period /
  prior year) is stamped onto every report in the package.
- **Cheque date comb prints YYYYMMDD by default** — configurable through
  `cheque.date_comb_format`, and the legend under the comb follows it.
- **Dependencies refreshed** — Laravel 13.32; the PHP ^8.5 and Laravel 13
  requirements are unchanged.

### Fixed

- **Line descriptions reach the ledger** — the expense, cheque, reimbursement, and
  inbox review forms dropped each line's Description on save (a re-saved
  reimbursement wiped the ones it already had), and the expense, cheque, bill,
  and vendor credit posters wrote every expense leg with an empty memo. The
  descriptions are saved and posted now; entries posted earlier are not
  rewritten.
- **A cheque line that settles a receivable or payable is never taxed** — the
  invoice or bill already recorded the tax, so taxing the settlement again moved
  the customer's or vendor's balance by too much, could claim an input tax credit
  on a refund, and printed the cheque for more than the refund. The tax cell now
  says "Included in the invoice" or "Included in the bill". A new report-only
  `integrity:check` names cheques posted the old way; open and save one to repost
  it correctly.
- **An edited document number now saves** — on all 11 document types with an
  editable number, a renumbered document toasted success and kept its old number.
  Each form also validates the number is unique within the organization, so a
  clash is a readable message instead of a database error.
- **Numbering never hands out a taken number** — the generator continued from the
  newest row rather than the highest number, so a back-dated, imported, or
  renumbered document could make the next number collide. It now continues from
  the highest number in the adopted format and skips any that exist.
- **A re-assigned payment recomputes the document it left** — moving a receipt or
  bill payment from one invoice or bill to another left the old one showing paid.
  `integrity:check` gains a paid-cache drift check that `--fix` repairs.
- **The scheduler skips soft-deleted organizations** — nightly recurring
  documents, depreciation, reminders, insights, and scheduled reports were queued
  for deleted organizations and failed every run.
- **Journal-entry templates** — the tax code column is gone from template lines
  (applying a template fills each line's tax from the account's default, as
  picking the account by hand does); a bare fraction such as `.05` is accepted in
  every amount field; a blank account, class, location, or fund select saves.
- **Sorting the deposit receipt picker ticked the wrong row.**
- **The API-keys panel is pinned to the organization it loaded with** — with
  another organization open in a second tab, creating, rotating, or revoking a
  key could hit the other organization's keys.
- **Restore remaps every reference into the new organization.** A restored
  opening balances workspace kept pointing at the source organization's journal
  entry and accounts, as did an employee payroll profile's fund and a filed tax
  return's line sources. A new test derives the foreign keys from the schema so a
  table registered for backup can no longer be left out of the restore map.
- **The Transactions report prints dates without a time.** Every row, and the CSV, Excel,
  and PDF exports, showed a line's date as `2026-05-01 00:00:00`.
- **Copying another organization's chart leaves out its deleted accounts.** The setup
  wizard's *Copy an existing organization* option read the source chart without the
  soft-delete filter, so an account merged away in the source came back, active, in the
  new organization.
- **A command run for one organization stays with that organization.** The
  per-company commands (`recurring:generate`, `reminders:send`,
  `depreciation:generate`, `insights:generate`, `reports:send-scheduled`, the time-off
  accrual, and the backfills) matched their `{company}` argument as an id *or* a
  slug, and MySQL coerces `149st-street-bakery` to `149`, so naming one organization
  could sweep a second one into the run. An all-digit argument is now an id and
  anything else a slug.

### Security

- **The Transactions report read other organizations' ledger lines.** Unfiltered,
  the report matched every organization's posted lines in the date range (and
  errored trying to render them); CSV, XLSX, and PDF exports and the group
  subtotals listed those lines outright; and an `?account=` or `?contact=` id
  hand-edited into the URL read another organization's ledger by that id. The
  report is now confined to the current organization's journal entries on every
  path. **Self-hosters running 1.0.0 with more than one organization should
  upgrade.**

### Upgrade notes

- **21 migrations run automatically.** One of them, `allow_duplicate_cheque_numbers`,
  drops a unique index and cannot be reversed once duplicate numbers exist. Back
  up before upgrading (`docker/backup.sh`, or your usual dump).
- **Run `php artisan app:upgrade --verify` once the deploy is up.** The Docker image
  runs `app:upgrade` on boot unless `MIGRATE_ON_BOOT=false`, and the Forge deploy
  script calls it in place of `migrate --force`, so the migrations and both banking
  backfills are already done. Neither passes `--verify`, so the integrity check has
  not run yet: run it by hand, and it finds nothing left to migrate or backfill and
  then runs the check. On Docker that is
  `docker compose exec app php artisan app:upgrade --verify`.
- **Edit locks are on by default.** Set `EDIT_LOCKS_ENABLED=false` to turn them
  off; the lease, heartbeat, idle, and prune windows are `EDIT_LOCKS_*` settings.
- **`config/cheque.php` was rewritten.** Every coordinate is now a text baseline
  measured against Intuit stock, so any layout you customised must be re-measured
  rather than carried over.
- **Expect findings from the first nightly `integrity:check`.** It now reports
  pre-existing invoice and bill paid-cache drift (`integrity:check --fix` repairs
  it) and any historical cheque whose AR/AP line was taxed (open and save each
  one to repost it).
- **API clients must handle `423 Locked`** on update, delete, and action
  endpoints while a person is editing the record, retrying after `Retry-After`.
- See `UPGRADING.md` for the full step-by-step.

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

[Unreleased]: https://github.com/lineledger/lineledger/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/lineledger/lineledger/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/lineledger/lineledger/releases/tag/v1.0.0
