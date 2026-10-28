# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Statutory accounts (UK)** — year-end accounts for small companies as one PDF,
  laid out the way an accountant presents them: cover, company information and
  contents, then numbered pages with the directors' report, the Statement of
  Comprehensive Income, Statement of Financial Position, Statement of Changes in
  Equity and notes (accounting policies, taxation, tangible fixed assets, debtors,
  creditors, share capital, related parties), each with the prior year. Choose
  FRS 102 Section 1A or FRS 105 (micro-entity). Periods are explicit dates, so a
  5 April year end works; figures are rounded line by line to whole pounds and
  cast. Every account maps onto a statutory line with a default from its type and
  name (dividends become a distribution, a director's loan a creditor) and an
  optional caption that groups accounts in the statements and notes. The company
  details — regime (UK Companies Act 2006 or the Isle of Man Acts), directors and
  shareholdings, policies, note text, approval and signatories — live on the
  report. Download as a PDF to file, or as a Word document (same layout, live
  page-number fields) to edit before filing; the Accounts tab summarises every
  year from the one picked to the latest. The documents are always in English,
  as Companies House takes them, and changing the mapping or the company
  details needs access to Accounting. New `accounts.statutory_line` /
  `statutory_label` columns; adds `phpoffice/phpword`.
- **Airy invoice layout** — a second invoice layout, chosen per form style
  (Settings → Lists → Form styles → Layout; the list shows each style's layout):
  your details top left and logo top right, a large INVOICE heading, Invoice to
  (and Ship to, when the customer has a shipping address) beside the number and
  dates, an Activity / Qty / Rate / Amount table with each line's item name in
  bold above its description, and the balance due under a dotted rule beside the
  customer message and payment instructions, with any payment or applied credit
  shown above it. It prints everything the classic layout does — terms, P.O.
  number, sales rep, shipping details, the payment schedule and the GST/HST,
  QST/PST or VAT numbers — in French for French-speaking customers and with any
  currency symbol. Make an Airy style the default to use it for every invoice,
  or pick it on one invoice. Existing styles keep the classic layout, which
  prints exactly as before. New `form_styles.layout` column.

### Changed

- **Bank register sorting** — the register now opens newest-first, and every
  column (Cleared, Date, Entry #, Memo, Payment, Deposit) sorts from its header;
  the choice is kept in the URL. A new **Balance** column shows the account's
  running balance after each line, always worked out in date order, so it stays
  correct whatever the sort, page or Show cleared setting. Long registers page
  50 rows at a time instead of loading every line at once.

## [1.3.0] - 2026-10-22

**Highlights.** LineLedger installs on a **DigitalOcean Droplet in one click**: create
a Droplet with a one-line user-data script (or, once DigitalOcean lists it, from the
Marketplace image), open its address and register. Every secret is generated on
first boot, and the `lineledger` command on the Droplet adds your own domain with
automatic HTTPS and upgrades to new releases. Site admins can now **set up outgoing
email in the app** (Admin → Email), and a fresh install works before email does. No
migrations, no backfills and no configuration changes; see `UPGRADING.md`.

### Added

- **One-click install on a DigitalOcean Droplet.** A Marketplace image (built
  from `docker/digitalocean/marketplace/` with Packer), or a one-line Droplet
  user-data script (`docker/digitalocean/droplet/install.sh`), installs Docker
  and the LineLedger stack and generates every secret on first boot. Open the
  Droplet's IP and register. `lineledger domain books.example.com` switches on
  HTTPS for your own domain, and `lineledger upgrade` backs up and moves to the
  newest release. See README → Deploy on a DigitalOcean Droplet (one click).
  ([#139](https://github.com/lineledger/lineledger/pull/139))
- **Email can be set up in the app.** Site admins enter SMTP settings in
  Admin → Email (the password is stored encrypted) and send themselves a test
  message. Settings saved there win over the `MAIL_*` environment variables.
  ([#139](https://github.com/lineledger/lineledger/pull/139))

### Changed

- **A fresh install works before email does.** With SMTP selected but no host
  (how a one-click install starts), outgoing mail is written to the log instead
  of failing the request that sent it, and the first account, which becomes
  the site admin, can sign in without verifying its address. Every later
  account still verifies. Site admins see a banner until email is set up.
  ([#139](https://github.com/lineledger/lineledger/pull/139))
- **Operator alerts with a blank address go to the site admins.** Security,
  ledger-integrity, failed-job, scheduled-task and exchange-rate alerts used to
  be skipped when their `*_ALERT_EMAIL` was set but blank; now they are emailed
  to every active site admin. An unset variable still falls back as before.
  ([#139](https://github.com/lineledger/lineledger/pull/139))

## [1.2.2] - 2026-10-22

**Highlights.** LineLedger can be deployed to **DigitalOcean App Platform** with
one command: `docker/digitalocean/deploy.sh` creates the database, file storage
and app in your own DigitalOcean account and generates every secret. This
release also makes backup restores and QuickBooks ledger imports work when the
queue worker runs on a different machine from the web server, and brings five
usability fixes reported from production. Two migrations, both run by
`php artisan app:upgrade`; no backfills and no configuration changes; see
`UPGRADING.md`.

### Added

- **One-command deploy to DigitalOcean App Platform.**
  `docker/digitalocean/deploy.sh` creates a managed MySQL cluster, two Spaces
  buckets with an access key limited to them, and the app (web, queue worker,
  scheduler, and a pre-deploy job that runs `php artisan app:upgrade`). It
  generates `APP_KEY`, the OAuth signing keys and the passkey secret, and stores
  them as encrypted app secrets. The only prompts are for mail. Running it again
  deploys the newest image. See README → Deploy on DigitalOcean App Platform.
  ([#137](https://github.com/lineledger/lineledger/pull/137))
- **The Docker image runs on hosts without a `.env` file or persistent disk.**
  The entrypoint writes a managed database's CA certificate (`DB_CA_CERT`) to a
  file for TLS. It skips generating OAuth key files when `PASSPORT_PRIVATE_KEY`
  is set, and reports why the database is unreachable on the first failed
  attempt instead of after two minutes.
  ([#137](https://github.com/lineledger/lineledger/pull/137))

### Fixed

- **Restoring from a backup and the QuickBooks ledger import work when the
  queue worker runs on a different machine from the web server.** Both stored
  the upload on the web server's own disk, so a worker elsewhere failed with
  "Restore bundle missing from disk". Uploads now wait on the backups disk
  (`BACKUP_DISK`), and the worker copies them down. Installs that keep backups
  on local disk behave exactly as before.
  ([#137](https://github.com/lineledger/lineledger/pull/137))
- **`storage:check` no longer warns that an S3-compatible store is outside
  the deployment's country** because of the AWS-style region it signs with.
  When `AWS_ENDPOINT` is set it says the region can't be checked.
  ([#137](https://github.com/lineledger/lineledger/pull/137))
- **Reconcile no longer loses a statement dropped in just before you finish.**
  The attachment drop zone uploaded without telling the page, so "Reconcile
  now" stayed clickable mid-upload and the late file was discarded. The button
  (and the Begin dialog's Continue) now waits for the upload, and the
  confirmation says how many files were attached.
  ([#134](https://github.com/lineledger/lineledger/pull/134))
- **Suggested cheque numbers skip labels.** After a cheque numbered `10300`
  and one labelled `e-transfer`, the next suggestion is `10301`, and it steps
  past numbers already used on the account, payroll cheques included.
  ([#134](https://github.com/lineledger/lineledger/pull/134))

### Changed

- **Screens remember your choices.** AR and AP aging remember "Owing only",
  the register remembers "Show cleared", and the last bank account you used is
  kept per user and organization, so it survives signing out (it was kept in
  the session before; pick it once more after upgrading). An explicit link or a
  memorized report still wins. Saving a cheque now counts as using its account.
  ([#134](https://github.com/lineledger/lineledger/pull/134))

## [1.2.1] - 2026-10-19

**Highlights.** Bank statements downloaded in **QuickBooks (QBO)**, **Quicken
(QFX)** or **OFX** format can now be imported. These are the formats most
banks offer as "Download to Quicken/QuickBooks", and the import page recommends
them, but uploads were rejected before they reached the parser. This release
also takes the October dependency updates, including a PDF-parser hardening
that matters because statement PDFs are untrusted uploads. No migrations, no
backfills and no configuration changes; see `UPGRADING.md`.

### Fixed

- **OFX, QFX and QBO statements are accepted on upload.** Banking → Import
  rejected real bank downloads in these formats with "The statement file field
  must be a file of type: csv, xlsx, xls, ofx, qfx, qbo, pdf.", even though
  they are supported. The files are plain text, so the upload check detected
  them as `.txt` (or `.xml` for OFX 2.x). The check now accepts that for the
  OFX family and CSV, while still requiring a supported file extension; PDF
  and Excel are checked as strictly as before.
  ([#130](https://github.com/lineledger/lineledger/issues/130),
  [#131](https://github.com/lineledger/lineledger/pull/131))

### Security

- **`smalot/pdfparser` 2.13.1** adds a nesting-depth limit and page-tree cycle
  detection against denial-of-service PDFs. LineLedger uses it to read PDF bank
  statements, which are user uploads.
  ([#132](https://github.com/lineledger/lineledger/pull/132))
- **`laravel/framework` 13.37** (from 13.35) includes the 13.36 fix that
  escapes inline Markdown in user input in Markdown mail, so a name or memo
  that reaches an email can't inject links or formatting.
  ([#132](https://github.com/lineledger/lineledger/pull/132))

### Changed

- Dependency updates: `laravel/passport` 13.10, `laravel/mcp` 1.0.2,
  `laravel/nightwatch` 1.30.4, `laravel/sail` 1.69, `openspout/openspout`
  5.12.2, `setasign/fpdi` 2.6.9, Symfony components 8.1.9, Vite 8.3.4, and the
  CodeQL SARIF upload action 4.39.
  ([#132](https://github.com/lineledger/lineledger/pull/132), superseding
  #118–#129)

## [1.2.0] - 2026-10-18

**Highlights.** LineLedger now speaks **French**: the staff app, customer and
vendor documents, emails, and both portals, with a language for each user, each
organization's documents and each customer. **United Kingdom** organizations
join Canada and the United States, with pounds sterling, an HMRC VAT chart and
codes, and a **VAT Return** that works out the nine boxes from the books. Tax
returns can be **adjusted and reconciled** to the ledger before filing, a
customer's **overpayment can be refunded** straight from the invoice, and the
lock date can **warn instead of block**, so year-end adjustments can be posted
after the bank is reconciled. Upgrading from 1.1.0 has no new backfills, but one
migration (BC and Yukon time zones) can't be rolled back, so take a backup first;
see `UPGRADING.md`.

### Added

- **French.** The staff app, customer and vendor documents (invoices, estimates,
  sales orders, credit memos, receipts, statements, bills, purchase orders,
  cheques), emails, and the customer and employee portals are available in
  French. Pick your own language under Settings → Profile; an organization has a
  document language, and a customer can have their own, which their documents,
  emails and portal use. Emails go out in the recipient's language. Signed-out
  pages (sign-in, onboarding, the portals) have a language switcher, and
  `?lang=fr` picks it from a link. A new Québec organization starts in French;
  existing organizations stay in English. Seeded account, tax and payment-method
  names are stored in English and shown in French, so renaming nothing changes
  how they post. French documents print ISO dates (`2026-03-04`) — a UK
  organization's keep day-first — and most amounts keep the `1,234.56` style. QST shows as
  9.975%, not 9.98%. Most report PDFs, the reconciliation and donation-receipt
  PDFs, and the in-app help's screenshots are still English.
  ([#51](https://github.com/lineledger/lineledger/pull/51),
  [#109](https://github.com/lineledger/lineledger/pull/109))
- **Provincial sales tax numbers on documents.** Settings → Invoices takes the
  QST, PST or RST registration number where the province requires one, and
  invoices, estimates and the other sales documents print it beside the GST/HST
  number. ([#51](https://github.com/lineledger/lineledger/pull/51))
- **United Kingdom** — a third jurisdiction (`GB`) beside Canada and the United
  States. A UK organization starts on pounds sterling, London time and an April
  fiscal year, with a British chart (Current Account, VAT Payable, Ordinary
  Shares), HM Revenue & Customs as its VAT agency — Isle of Man Customs and Excise
  for a Manx company — and Standard 20%, Reduced 5%, Zero-rated and Exempt codes.
  The wizard and settings speak UK (sole trader, Ltd, LLP; nation; postcode; "Are
  you VAT-registered?"), check VAT numbers for the GB format and store them
  `GB`-prefixed, and record a Companies House number that prints beside the
  "VAT No." on invoices and other forms. Printed forms use the document's own
  currency symbol and, for UK organizations, day-first dates (04/03/2026) and
  addresses without the nation code ("Reading, RG1 2EQ").
  Deploy it at a `.co.uk` / `.uk` host or with `APP_REGION=GB`; `APP_URL_GB` and
  `MARKETING_URL_GB` join the country switcher once set — both default to empty,
  so existing deployments show no UK link until the UK twin is live.
- **VAT Return (UK)** — a report that works out the nine boxes of an HMRC VAT
  return for a quarter from the books: VAT on sales and purchases (agreeing to the
  penny with the tax return for the same period, exclusions and adjustments
  included) and the ex-VAT value of sales and purchases coded to a VAT rate, in
  whole pounds; a void lands in the quarter of its reversing entry, as it does in
  the ledger. A UK tax return shows the same boxes and freezes them when filed.
  LineLedger does not submit the return — copy the boxes into Making Tax
  Digital software to file.
- **Insights** — the check that keeps AI-written insight copy to the amounts it
  was given now covers every currency symbol (£, €, …), not only `$`.
- **Branding from the environment** — a self-hosted instance replaces the mark on
  the sign-in, registration and onboarding screens with `BRAND_LOGO` (and, for a
  separate dark-theme variant, `BRAND_LOGO_DARK`), and names its own operating
  company in the footer's copyright line with `BRAND_FOOTER_OWNER`. Any path the
  browser can fetch works, including a `/storage/…` path that survives a deploy.
  `BRAND_SHOW_PROJECT_LINKS=false` drops the upstream version, licence, source and
  legal links beside it, keeping the copyright line and a plain version number —
  read the note in `config/brand.php` about AGPL-3.0 §13 before you do. Together
  with `APP_NAME` and the favicons in `public/`, this is the whole of what the
  trademark notice asks a self-host to replace, with no fork to maintain.
- **Support desk switch** — `SUPPORT_ENABLED=false` on a deployment whose support
  runs somewhere else: the Support entry leaves both account menus and `/support`
  stops answering. Nothing is deleted — tickets already raised stay readable in
  the admin portal, so switching it back on loses nothing. See
  `config/support.php`.
- **Pay an expense from a loan account** — a new **Use to pay expenses** switch on
  a current, long-term, or other liability in the Chart of Accounts adds it to an
  expense's Paid from, alongside bank and credit card. When an owner pays a business
  cost personally you can post it straight to their Shareholder Loan: debit the
  expense and any recoverable tax, credit the loan, in one entry with no clearing
  account. Off by default; system accounts such as the payroll payables can't be
  switched on. ([#50](https://github.com/lineledger/lineledger/issues/50))
- **Adjust a tax return before filing.** A draft return takes adjustments: a
  change to the tax collected or the input tax credits by any amount, or another
  amount such as a collector's commission, a bank fee, or a balance carried from
  an earlier period, each with an account and a memo. Coded to the agency's tax
  payable account, an adjustment only changes the return (the amount is already
  in the ledger). Coded to any other account, filing posts it against the tax
  payable account, dated the period's last day: a PST commission posts debit PST
  Payable, credit PST Commission Income. Voiding the return reverses that entry.
  The API takes `adjustments` on a tax return.
- **Reconcile a tax return to the ledger.** The return form and page set the
  return beside the agency's tax payable account. The panel shows the opening
  balance, the payments and refunds in the period, the tax collected, the ITCs,
  any excluded lines and the adjustments filing will post, then the balance after
  filing and the difference from the net owing. A return that doesn't agree with
  the ledger files only when you accept that difference. Filing freezes the
  reconciliation on the return.
- **Refund an overpayment — no credit memo needed.** When a customer pays twice
  (by card, then again by cheque) the second payment is an overpayment, kept as
  credit on their account. An invoice now lists its **Payments** and the
  customer's **Overpayment (customer credit)**, and **Refund to client** pays that
  credit back by card — a negative receipt to Undeposited Funds that nets into
  the next card deposit — or by cheque, as a draft refund cheque. Nothing is
  credited, so revenue is untouched, and the invoice stays paid by its original
  payment. A payment's own page shows its unapplied credit and offers the same
  refund. The API takes `POST /api/v1/invoices/{id}/refund`.
- **Refund-only deposits.** A deposit whose card refunds outweigh its receipts —
  or that holds only a refund — now posts with a negative total, as money out of
  the bank, matching the card processor's debit on the statement. It lists with
  the payments when you reconcile. Only a deposit netting to zero is refused.
- **The lock date can warn instead of block.** Close the books now has a mode.
  **Block** (the default, and what every existing organization keeps) still
  refuses a posting on or before the lock date, but an Owner or Admin can
  override that one transaction with their password. **Warn and confirm** lets
  anyone using the form carry on after confirming. Every override is recorded in
  the audit log (`period.lock_overridden`). The API, MCP and scheduled jobs never
  override. ([#105](https://github.com/lineledger/lineledger/pull/105))
- **Post year-end adjustments after reconciling.** A reconciliation now protects
  the lines it ticked, not every date it covered, so an adjusting entry dated
  July 31 can be posted after the bank is reconciled through September 30. It
  shows as uncleared on the next reconciliation. The journal form warns before
  posting into a reconciled period or a prior fiscal year.
  ([#105](https://github.com/lineledger/lineledger/pull/105))
- **Drag to resize line-item columns.** On the line tables of the sales, purchase
  and banking forms, drag a column edge to widen it; double-click the edge to
  reset it. Each browser remembers the widths per form. The Account column also
  starts wider. ([#79](https://github.com/lineledger/lineledger/pull/79))
- **Search opens an account's General Ledger.** Choosing an account in ⌘K search
  opens its General Ledger report instead of the Chart of Accounts (a member
  without Reports access still lands on the Chart of Accounts).
  ([#81](https://github.com/lineledger/lineledger/pull/81))
- **A UK demo.** `php artisan db:seed --class=UkDemoCompanySeeder` adds Thames
  Valley Trading Ltd (`uk-demo`), with two VAT quarters of trading (one filed,
  one left as a draft to file), and Laxey Craft Brewery Ltd (`iom-demo`) on the
  Isle of Man. It is separate from the main demo seeder and safe to re-run.
- **A Docker development stack.** The root `docker-compose.yml` runs the app,
  queue, scheduler, Vite and MySQL from your checkout for development; run the
  tests with `sh docker/pest.sh`. It is separate from the self-hosting compose
  file in `docker/`. See README → Develop with Docker.
  ([#51](https://github.com/lineledger/lineledger/pull/51))
- **The nightly integrity check reports over-refunded receipts** — a payment
  refunded for more than it brought in.

### Changed

- **Only two-decimal currencies can be a home currency.** Yen and other zero- or
  three-decimal currencies (JPY, KRW, BHD, KWD, …) are refused as an
  organization's home currency: amounts are kept in hundredths, so a JPY
  organization booked fractions of a yen, and a card payment would have charged
  a hundred times the invoice. The currency box in Settings → Organizations is
  now a list. An organization already on such a currency keeps it and can still
  save its other settings, but card payments and multi-currency are off for it,
  and a backup that holds one is refused on restore.
  ([#103](https://github.com/lineledger/lineledger/pull/103))
- **Reconciled lines are protected wherever they're edited.** Editing a posted
  invoice, bill, receipt, bill payment, sales receipt, credit memo or vendor
  credit could rewrite a bank line a completed reconciliation had ticked; that is
  now refused, as it already was for cheques and deposits. Editing a posted
  journal entry checks both its old and new date against the lock date, so an
  entry can no longer be moved out of a locked period.
  ([#105](https://github.com/lineledger/lineledger/pull/105))
- **Other deposits lines offer every account**, including expense and cost of
  goods sold, so a refund cheque can go back to the account it was paid from.
  ([#106](https://github.com/lineledger/lineledger/pull/106))
- **Switching organizations opens the Dashboard.** The organization switcher used
  to reopen the page you were on, which 404'd on any page naming a record of the
  organization you left. ([#75](https://github.com/lineledger/lineledger/pull/75))
- **The cheque payee address folds behind a one-line summary** of what will
  print, with Show / Hide; it opens by itself when a field needs fixing.
  ([#76](https://github.com/lineledger/lineledger/pull/76))
- **`storage:check` expects `eu-west-2`** for object storage when the region is
  the United Kingdom.
- **`ext-intl` is now a declared requirement.** Money formatting already used it;
  `composer install` now says so instead of failing later. The Docker image has
  always included it.
- **Type a GL number or a name to pick a line's account.** Every line "Account"
  picker (cheques, expenses, bills, deposits, journal entries, invoices and the
  rest of the sales and purchase forms, recurring documents and templates,
  reimbursements, inbox review, tax return adjustments, budgets, and the bank
  review categories and splits) is now one search box instead of a long
  dropdown. "24" lists 2400, 2410… first; "disb" or "bmo usd" finds the account
  by name, words in any order, ignoring case and punctuation. Arrow keys and
  Enter pick, Tab takes the highlighted match after you type, Escape backs out,
  and emptying the box clears the account.
- **Customer statements get their own page.** Statement… (on the Customers list,
  a customer's Open balance, or the AR statement report) now opens a Customer
  statement page that previews the statement like any report, then exports it to
  PDF or emails it exactly as shown; the old dialog is gone. Pick a Start and End
  date: an Open invoices statement lists the invoices dated in that range and
  carries anything older still owing as one **Balance forward** row, so Total Due
  still matches AR Aging (the default period, All, prints every open invoice as
  before). A **Columns** menu adds the invoice's **P.O. #**, Terms, Amount Paid
  and Days Past Due, or hides Memo, Due Date and Original Amount; Account activity
  can add P.O. # or hide Type and Memo. **Save as default columns** makes a choice
  the company default (Settings access required).
- **The browser tab title follows `APP_NAME`** instead of a hardcoded "Line
  Ledger". Deployments that want the spaced form should set
  `APP_NAME="Line Ledger"`.
- **The guest country-switcher banner now renders only on the project's own
  deployments** — the hosts in `APP_URL_CA` / `APP_URL_US` / `APP_URL_GB` —
  rather than on every installation. `APP_REGION` still picks which marketing site the legal links
  point at, but no longer forces the banner, so a self-host that set it to get the
  right legal documents is no longer offered a trip to someone else's app.
- **API: filing a tax return that doesn't reconcile is refused.** `POST
  /tax-returns/{id}/file` answers 422 when the return differs from the tax payable
  account, unless `accepted_difference_cents` names that exact difference.
- **Sales tax figures leave out payments to the agency.** The Sales Tax report,
  the sales tax MCP tool, and the tax set-aside insight used to count a remittance
  as an input tax credit. That covered a recorded tax payment, and a cheque or
  journal entry moving money between the payable account and a bank. A period
  that contains a remittance now reports a lower Paid figure and the correct,
  higher net. Credit-memo tax now lowers Collected instead of adding to
  Paid, and vendor-credit tax lowers Paid instead of adding to Collected. Net
  figures are unchanged by that split.
- **Dependencies refreshed** — Laravel 13.35, Livewire 4.4.7, Fortify 1.41,
  Passport 13.9, Flux 2.20.1, laravel/mcp 1.0, stripe-php 22 and symfony/yaml 8;
  the PHP ^8.5 and Laravel 13 requirements are unchanged. TCPDF stays on 6.x:
  TCPDF 7 drops the internals FPDI builds on, and the payroll slips and the
  management report package both print through FPDI.
- **MCP servers speak protocol revision 2026-07-28.** Connectors that open with
  `initialize` keep working: 2025-06-18 and 2025-11-25 are answered as asked,
  and a client asking for 2025-03-26 or 2024-11-05 is offered 2025-11-25. There
  is no `Mcp-Session-Id` any more; every request stands alone. An unexpected
  error inside a tool now comes back as a tool error rather than a JSON-RPC
  error, without the exception's message unless `APP_DEBUG` is on.
- **Stripe requests use API version `2026-09-30.endive`** (was
  `2026-06-24.dahlia`), the version stripe-php 22 pins. Webhook payloads follow
  the API version set on the webhook endpoint in Stripe, which this doesn't
  change.
- **A tax amount for each tax on a purchase line.** On cheques, expenses, bills,
  reimbursements and the inbox review screen, every tax code ticked on a line now
  gets its own amount field, labelled with the code and showing its calculated
  amount. With GST and PST on one line you can correct either or both; the single
  field used to override only the first tax. A line with no tax code shows no
  field.
- **A receipt's `unapplied_cents` nets out refunds.** The API's figure is now
  what the payment neither applied nor refunded, and a refund receipt reports 0
  instead of its negative amount. Receipts also return `refunded_cents`,
  `credit_memo_id`, `refunded_receipt_id` and `from_invoice_id`; cheques return
  `refunded_receipt_id` and `from_invoice_id`. Deposit lines accept a negative
  `amount_cents` (a card refund or a fee) and refuse only 0 — they used to refuse
  anything below 1, so the API couldn't net a fee out of a deposit.
- **A refunded payment keeps its refunded credit.** While a refund stands, the
  payment can't be voided or moved to another customer, and its refunded credit
  can't be applied to an invoice or auto-applied to a new one. Remove the refund
  first. A refund receipt opens on its own page and no longer offers Edit (the
  form always refused its negative amount); remove it and record it again.
- **Refund cheques from a credit memo fill in the payee's mailing address**, and
  every refund is paid from a bank or Undeposited Funds account in your home
  currency.

### Fixed

- **Delete a draft journal entry.** A manual journal entry saved as a draft had
  no way out: Void is only for posted entries, and the page offered nothing
  else. Its Actions menu now has **Delete draft**, which removes the entry and
  its lines (the deletion is recorded in the audit log). A posted entry still
  offers Void, and an entry created by another document is still managed from
  that document. `DELETE /api/v1/journal-entries/{id}` now goes through the same
  action, so it also refuses a source-linked draft (422) instead of deleting it
  out from under its document.
- **A paid invoice can't be edited below what its receipts applied.** Lowering
  the total of an invoice that receipts had already paid saved anyway, leaving
  more applied than the invoice was worth. Its balance cache stopped matching
  its payments, and the nightly integrity check reported an over-application.
  The edit is now refused with the amount applied. Reduce the amount applied on
  the receipt first, or issue a credit memo for the difference. A failed post
  from the invoice form also no longer leaves the edit half-saved.
- **A refunded credit memo can't be voided or edited below its refunds.**
  Voiding a credit memo that had a refund cheque or card refund against it, or
  editing its total below the amount refunded, left refunds with no credit
  behind them. Both are now refused until the refund is voided. The API answers
  either with 422.
- **BC organizations keep BC time through the winter.** British Columbia stops
  changing its clocks on November 1, 2026 and stays on UTC−7, but BC and Yukon
  organizations were set to US Pacific time, which still falls back to UTC−8.
  From November to March their "today" would have run an hour behind: between
  midnight and 1 am new entries defaulted to the day before, report stamps read
  an hour early, and payment reminders and scheduled report emails went out a
  day late. They now use British Columbia and Yukon time, which the
  Timezone picker offers as choices, and existing organizations left on US
  Pacific time move across automatically when you upgrade. The server's timezone
  data must know about the change: `php -r 'echo (new
  DateTime("2026-12-01", new DateTimeZone("America/Vancouver")))->format("P");'`
  should print `-07:00`.
- **Clearing a cheque line's account no longer breaks the form.** Choosing "—"
  on a line that had an account threw an error instead of emptying the line.
- **Pressing Post twice on a new journal entry no longer errors.** A
  double-click, or Enter pressed again before the page moved on, reached the
  server holding the entry the first click had just posted: it rewrote that
  posted entry's lines and then failed with "already posted" (a server error on
  journal entries). The repeat now changes nothing and opens the posted entry.
  New expenses and transfers get the same guard, and Save draft never rewrites
  a posted entry.
- **Two people posting at once no longer breaks a new journal entry.** The New
  journal entry page fills in the next JE number when it opens, but cheques,
  bills, invoices and every other posting share that sequence. If anyone posted
  before you clicked Post, the save failed with a server error. A suggested
  number is now only a preview: the entry takes the next free number as it
  saves. A number you type yourself is kept, and if it is already used you get a
  message under Entry # instead. The number sequence also never hands out a
  number that is already taken.
- **A saved draft tax return shows its figures.** Its page read 0.00 and "No
  snapshot lines yet" until the return was filed, and the list showed 0.00 too. A
  draft's page now works its figures out live from the ledger. Each save also
  stores them for the list (existing drafts pick them up on their next save).
- **Updating a tax return through the API no longer clears its excluded lines.**
  A `PATCH` without `excluded_journal_line_ids` used to wipe the lines unchecked in
  the web form. Omitting the key now leaves them as they are.
- **"Include in transfers" now saves.** The switch on a Chart of Accounts
  account was shown and read back, but saving never stored it, so a line of
  credit or other non-bank account could not be added to the transfer form.
- **A credit memo's form total now counts both taxes.** With two taxes on a line
  (GST and PST), the Total on the new / edit credit memo form added only the
  first, so a memo the credit memo page showed as 140.00 read 130.75 while being
  edited. Display only — the saved memo, its journal entry and refunds always used
  the right amount.
- **Cheque and expense lines list every account.** The line Account picker on the
  cheque and expense forms left out revenue accounts, so a customer refund or a
  reversed commission couldn't be coded there. It now lists every active account,
  as the journal entry form does. Picking a revenue account doesn't bring along a
  sales-only default tax code, which never applies to a purchase.
- **A tax amount without a tax code is ignored.** A cheque, expense or bill line
  could save a typed tax amount with no tax code. The amount went into the total,
  but with no tax agency to post it to, the journal entry put it in the expense
  account instead.
- **Cheque and expense pages show the first tax.** The totals on a cheque's or
  expense's page left out each line's first tax: a GST-only cheque showed no tax
  row, and a GST + PST one showed only PST. Display only — the saved amounts and
  the journal entry were right.
- **A draft refund cheque can't be edited past its credit.** Raising the amount
  of a credit memo's draft refund cheque in the cheque form saved, so posting it
  refunded more than the memo granted. Saving, posting and reposting a refund
  cheque now refuse an amount above the credit left.
- **Picking a tax code on a bank line no longer fails with a server error.** On
  the For Review feed and the statement import, choosing a row's tax code (and on
  the import, its category or Record as) could answer with a TypeError instead of
  saving. Once it had, every later action on the page failed the same way,
  including each keystroke in the Split dialog. The browser sometimes sends the
  whole set of rows rather than the one that changed; those updates are now
  handled, applying only the rows that actually changed.
  ([discussion #107](https://github.com/lineledger/lineledger/discussions/107))
- **Opening a search result no longer logs calculator errors** in the browser
  console. ([#83](https://github.com/lineledger/lineledger/pull/83))
- **Saving Settings → Invoices no longer blanks the CRA registration number.**
  That number is edited under Lists → Tax codes.
  ([#109](https://github.com/lineledger/lineledger/pull/109))

### Security

- **A customer-portal session no longer signs in on another organization's
  portal.** The portal's organization was bound after the customer guard had
  already loaded the signed-in contact, so a session from one organization's
  portal passed authentication on another's. The pages still showed only the
  portal's own organization's data, but the sign-in itself should have failed;
  it now does, on the customer and employee portals and on their Livewire
  updates. ([#116](https://github.com/lineledger/lineledger/pull/116))
- **source-map-js 1.2.2**
  ([GHSA-68fv-2mgg-jv7q](https://github.com/advisories/GHSA-68fv-2mgg-jv7q)) and
  **concurrently 10.0.6 / shell-quote 1.12.0**
  ([GHSA-pqg4-j6r4-53mv](https://github.com/advisories/GHSA-pqg4-j6r4-53mv)).
  Both are build- or development-time only and never reach the browser or the
  server. ([#88](https://github.com/lineledger/lineledger/pull/88),
  [#92](https://github.com/lineledger/lineledger/pull/92))

### Upgrade notes

- **Back up first: one migration can't be rolled back.**
  `move_bc_and_yukon_companies_off_us_pacific_time` moves British Columbia and
  Yukon organizations still on US Pacific time to `America/Vancouver` /
  `America/Whitehorse`, and its `down()` does nothing. To go back to 1.1.0,
  restore the pre-upgrade backup. Ten migrations run in all; the other nine add
  columns or a table, and the locale one sets every existing organization's
  document language to English.
- **No new backfills.** `php artisan app:upgrade --verify` runs the same steps as
  in 1.1.0; on Docker the image runs `app:upgrade` on boot.
- **Check the server's time zone data** knows BC stays on UTC−7:
  `php -r 'echo (new DateTime("2026-12-01", new DateTimeZone("America/Vancouver")))->format("P");'`
  should print `-07:00`. Update the system's tzdata (or PHP) if it prints `-08:00`.
- **Bare-metal installs need `ext-intl`**, which `composer install` now checks.
- **API clients:** `POST /tax-returns/{id}/file` answers 422 for a return that
  doesn't reconcile unless `accepted_difference_cents` names the difference;
  receipts' `unapplied_cents` nets out refunds; deposit lines accept negative
  amounts; and `DELETE /journal-entries/{id}` refuses a draft created by another
  document. MCP connectors speak protocol 2026-07-28 with no `Mcp-Session-Id`.
- **New settings are optional.** `APP_LOCALE` (default document language),
  `APP_REGION=GB` / `APP_URL_GB` / `MARKETING_URL_GB`, `BRAND_LOGO`,
  `BRAND_LOGO_DARK`, `BRAND_FOOTER_OWNER`, `BRAND_SHOW_PROJECT_LINKS` and
  `SUPPORT_ENABLED` all default to 1.1.0's behaviour.
- See `UPGRADING.md` for the full step-by-step.

- **league/commonmark 2.10.3**, which renders the Markdown in outgoing mail. It
  fixes a quadratic-time denial of service in the GitHub-flavoured table
  extension ([GHSA-3q6v-r5mr-hxv8](https://github.com/advisories/GHSA-3q6v-r5mr-hxv8))
  and a bypass of the raw-HTML tag filter
  ([GHSA-97jj-33gv-5xf9](https://github.com/advisories/GHSA-97jj-33gv-5xf9)).
- **laravel/mcp 1.0.1**, which registers MCP connectors over OAuth. It matches a
  loopback redirect URI on its parsed host instead of a `http://localhost:`
  prefix, which a crafted URI could use to get past `MCP_REDIRECT_DOMAINS`, and
  refuses redirect URIs that carry a username or password
  ([GHSA-mx2h-h55v-pm44](https://github.com/laravel/mcp/security/advisories/GHSA-mx2h-h55v-pm44)).

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

[Unreleased]: https://github.com/lineledger/lineledger/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/lineledger/lineledger/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/lineledger/lineledger/compare/v1.2.2...v1.3.0
[1.2.2]: https://github.com/lineledger/lineledger/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/lineledger/lineledger/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/lineledger/lineledger/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/lineledger/lineledger/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/lineledger/lineledger/releases/tag/v1.0.0
