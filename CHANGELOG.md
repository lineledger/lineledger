# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- **Current assets can use the Use to pay expenses switch.** It is off by
  default. A petty-cash account kept as a current asset now works under Paid from
  on both the Expense form and inbox review, once switched on.

### Fixed

- **Edit buttons are back on Settings → Organizations.** In 1.5.0-rc.1 each
  organization in the list had no button to open its settings, and combined
  report groups had no Edit pencil. The check that hides links a role can't
  follow needs an organization to check against, and neither page has one.
  The organization buttons show as before; the pencil shows on groups you
  created and can still open.
- **Inbox review's Paid from matches the Expense form (#198).** It missed accounts
  switched on with Use to pay expenses, such as a Shareholder Loan, and offered
  every current asset, including Prepaid Expenses. **If you pay from a petty-cash
  account kept as a current asset, switch on Use to pay expenses for it in the
  Chart of Accounts**, or it no longer appears there. Existing drafts keep the
  account they were saved with.
- **Inbox review offers what the destination form accepts.** A bill now needs a
  vendor and a reimbursement an employee; a bill for anyone else could not be saved
  on the Bill form afterwards. Category lists leave out deactivated accounts and
  follow the document type, and a contact the page has cleared no longer comes back
  as a hidden payee.
- **Agents can't pay an expense from just any account.** The MCP expense tool
  accepted Prepaid Expenses, a revenue account or a loan that was not switched on.
  It now uses the Expense form's rule, and checks again when the proposal is
  confirmed.
- **The transfers API accepts accounts switched on with Include in transfers**, as
  the form always has. Neither the form nor the API accepts an accounts receivable
  or payable account any longer, even when switched on: a transfer has no contact,
  so that line would break the receivable and payable reports.
- **Tax pickers follow the document's direction.** Sales forms, customers and
  membership levels no longer offer a purchase-only code, and reimbursements,
  recurring bills and vendors no longer offer a sale-only one. Membership levels
  also stop offering deactivated codes. A document that already carries a code keeps
  it, including a second tax code, which some purchase forms used to drop from the
  list.
- **Defaults skip deactivated accounts.** A new pay run, an invoice from time
  entries, inbox review, and new invoice and sales receipt lines could all start on
  an account their own list no longer offered.
- **A deactivated account stays visible on documents that use it.** Bills,
  reimbursements, journal entries and their templates, deposits, and pay runs showed
  the field as blank, though the account was still saved.

## [1.5.0-rc.1] - 2026-11-05

**Highlights.** The first release candidate for 1.5.0, on the next channel
only. It adds users and roles in the manner of QuickBooks Desktop Enterprise:
predefined and custom roles that grant each activity None, Full or Partial
access, people who hold several roles, and API keys and MCP connections held to
the same permissions. Four new tables, and `app:upgrade` converts every member
so nobody's page access changes; see `UPGRADING.md`.

### Added

- **Users and roles.** Settings → Users and roles replaces the member list on
  the organization page, in the manner of QuickBooks Desktop Enterprise:
  - **Roles** say what people may do, area by area and activity by activity:
    None, Full, or Partial with View, Create, Modify, Delete, Print and View
    balance ticked. Fifteen predefined roles come with every organization
    (Full Access, Accountant, External Accountant, Bookkeeper, Accounts
    Receivable, Sales, Accounts Payable, Purchasing, Banking, Inventory, Payroll
    Manager, Payroll Processor, Time Tracking, Fundraising and View-only). Their
    permissions can be edited and reset to the default; their names can't. New
    roles can start blank or as a copy of another.
  - **Users** hold one or more roles, and may do whatever any of them allows.
    Invitations name the roles too. The Owner keeps full access and isn't
    assigned roles.
  - **Permission access by roles** (Reports → Accountant & Taxes) sets every
    activity against the roles you pick, with a CSV export.
  - Narrowing a role, or a user's roles, asks first and signs out whoever loses
    access. Nobody but the Owner can hand out more than they hold, change their
    own roles or edit a role they hold.
  - What a role can't do isn't offered: the sidebar leaves out pages it can't
    open, and New, Edit, Void and Delete buttons are hidden instead of
    answering "403". Someone who may create but not change a record lands on
    its page after saving rather than its edit form.
  - Balances need **View balance**: cash, receivables and payables on the
    dashboard, the chart of accounts' balance column, account balances in
    search, the API (`balance_cents` is `null`) and the MCP chart of accounts
    and contact lookups. Net income and the dashboard charts need financial
    reports, and each daily insight shows only to people who can open the
    report it points to.
  - Payroll detail on the general ledger (employee names on payroll cheques and
    net-pay lines, and the link to the pay run) shows only to people who can
    open pay runs; everyone else sees "Payroll" with the amount.
- **The API and MCP follow the same permissions.** An API key can do no more
  than the person who created it may do now, and stops working for anything
  they lose. MCP connections made with an API key are held to their creator's
  permissions too; until now they skipped section checks.
- **A next channel for release candidates.** Release candidates such as
  `1.5.0-rc.1` go to a `next` channel only. A Docker install joins it with
  `LINELEDGER_VERSION=next`, a Droplet with `lineledger channel next` (or
  `LINELEDGER_CHANNEL=next` for the installer), App Platform with
  `./deploy.sh --tag next`, and Forge by deploying the `next` branch. Stable
  installs never see a release candidate. README → Release channels explains
  both channels, and the new `RELEASING.md` is the maintainer's runbook.

### Changed

- **The `latest` image follows the newest final release by version.** It used
  to move to whichever release tag was pushed last. A release candidate would
  have moved it, and so would a patch to an older line.
- **`lineledger upgrade` never moves a Droplet backwards on its own.** With no
  version given, it leaves the install alone when the channel's newest release
  is older than the one installed. Naming a release still works, and points at
  the rollback steps first.

## [1.4.3] - 2026-11-05

**Highlights.** A security patch that updates the Symfony components LineLedger
runs on to 8.1.11, for eleven advisories Symfony published on November 4, 2026.
LineLedger's own code is unchanged. No migrations, no backfills and no
configuration changes; see `UPGRADING.md`.

### Security

- **Symfony components updated to 8.1.11**, and the Symfony contracts to 3.8.1.
  Only `composer.lock` changed. The advisories they fix:
  - `symfony/http-foundation`: generated URLs could point to a host chosen by the
    client; the `private_ranges` trusted-proxies shortcut (`PRIVATE_SUBNETS` in
    `TRUSTED_PROXIES`) trusted clients presenting an IPv4-mapped, 6to4, Teredo
    or NAT64 address as reverse proxies; path rules enforced in front of PHP
    could be bypassed by appending the path to the script name; HEAD requests
    bypassed the `methods` option of access-control rules (CVE-2026-118514).
  - `symfony/http-kernel`: inline fragments let untrusted forwarded headers set
    the host, scheme and port of URLs generated for the main request.
  - `symfony/routing`: host placeholders could match the start of the path in
    compiled URL matchers (CVE-2026-118516).
  - `symfony/serializer`, which passkey sign-in uses to read what the browser
    sends: the serializer built uploaded-file objects from request data
    (CVE-2026-118515); `DataUriNormalizer` opened data URIs with a non-lowercase
    scheme as local files; dotted keys got past `#[Ignore]` on nested objects.
  - `symfony/filesystem`: `Path` treated any prefix before `://` as a root.
  - `symfony/yaml`: some documents took exponential or quadratic time to parse.
    LineLedger only parses its own API spec.

## [1.4.2] - 2026-11-05

**Highlights.** A security patch for organizations with Custom members, whose
access is limited to the sections ticked for them: combined report groups,
search, scheduled report emails and attachment downloads now respect those
sections too. Editing a member on the organization page works again. No
migrations, no backfills and no configuration changes; see `UPGRADING.md`.

### Security

- **Section access now reaches combined reports, search, report emails and
  attachments.** Section access only guarded the organization's own pages, so a
  member walled off from Reports could still read full statements through a
  combined report group, and search, scheduled report emails and attachment
  downloads ignored sections altogether.
  - **Combined report groups** need Reports access in every member
    organization, to view and to edit, and re-check it on every action on an
    open page. A deleted organization drops out of every group, so its ledger no
    longer appears in combined statements. The New group and Add company lists
    only offer organizations whose reports you can open.
  - **Search** only returns the kinds of records whose pages you can open: a
    member with Customers alone sees invoices, credit memos, receipts and
    customers, not bills or journal entries. Contacts appear for the roles you
    can open.
  - **Scheduled report emails** pause when the person who scheduled them is
    removed from the organization, has their account disabled, or loses Reports
    access. Every send also re-checks that they can still open each report in
    it. The schedule shows as paused on Memorized Reports, and saving it again
    resumes it.
  - **Memorized reports** can only be filed into your own groups. A group id
    from a colleague was accepted, which would have emailed the report under
    their schedule.
  - **Attachment downloads** need the section of the record the file is
    attached to (a bill's receipt needs Vendors), or Documents, whose
    attachment index lists every record's files.
  - **Management report packages** only offer, and only download, reports you
    can still open.
  - **Reassigning an account's GIFI line** from the GIFI statement needs
    Accounting, as editing the account does; Reports alone only reads the
    statement.
  - Removing a member also moves their current organization off the one they
    left, so settings pages and search stop answering "403 Forbidden" for them.
  - A new test fails when an organization page is added without saying which
    section it belongs to.

### Fixed

- **Edit on a member opens its dialog again.** On the organization page, Edit
  beside a member did nothing, and saving a member's access or a closing date
  left the dialog open.

## [1.4.1] - 2026-11-02

**Highlights.** A patch for one serious 1.4.0 bug: on any installation whose
`APP_URL` isn't `localhost`, every action on an open page answered "400 Bad
Request". No migrations, no backfills and no configuration changes; see
`UPGRADING.md`.

### Fixed

- **Actions on an open page work again on a real hostname.** Since 1.4.0, every
  click that updates a page without reloading it answered "400 Bad Request", and
  nothing was logged. That included switching accounts or sorting in the bank
  register, moving between periods in a report and saving a form. It happened on
  any installation whose `APP_URL` isn't `localhost`. The check that re-confirms
  your access on each action looked the page up on `localhost`. With cached
  routes, which every deployment uses, that host was refused as untrusted. It now
  looks the page up on the host you are using.
  ([#180](https://github.com/lineledger/lineledger/issues/180),
  [#191](https://github.com/lineledger/lineledger/pull/191))

## [1.4.0] - 2026-10-29

**Highlights.** UK companies get **statutory accounts** (FRS 102 Section 1A or
FRS 105) as a PDF to file or a Word document to edit, and invoices get a second,
**Airy** layout. The rest is fixes. **Security:** an open page re-checks your
access on every action, the accounts, payments and deposits a form sends are
checked against your organization on the server, and outside local development
the app answers only on `APP_URL` and the new `TRUSTED_HOSTS` and trusts
forwarded addresses only from the new `TRUSTED_PROXIES`. **Foreign currency:**
bank-feed lines and journal entries on a foreign-currency account keep their
foreign amount, those accounts reconcile in their own currency, and a void takes
its foreign amount off too. **Payroll:** the T4, T4A, RL-1 and ROE files follow
the current CRA, Revenu Québec and Service Canada formats, TD1 claims, annual
maximums, vacation pay and Indian Act exempt income are handled correctly, and a
pay date past the loaded tax tables is refused. Restores keep employee SINs
readable and no longer collide with the organization they came from, and the
1099 Summary uses the $2,000 threshold for 2026. The late-October dependency
updates are in too, among them `laravel/mcp` 1.1.1, which limits what MCP's open
client registration accepts. Nine migrations and two new `app:upgrade` steps;
behind a load balancer, CDN or reverse proxy, set `TRUSTED_PROXIES` before you
upgrade, and on Forge or bare metal check `.env` for an unclosed double quote.
See `UPGRADING.md`.

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
- **No built-in sibling deployments.** `APP_URL_CA` and `APP_URL_US` no longer
  default to `books.lineledger.ca` and `books.lineledger.com`, which aren't run any
  more, so the guest country-switcher banner stays off unless you list your own
  sibling deployments. `SECURITY.md` now scopes reports to the source code; running
  deployments, including the public demo, are out of scope.
- Dependency updates: `laravel/framework` 13.39, `livewire/flux` 2.21,
  `resend/resend-php` 1.17, Guzzle 8.3.1, Carbon 3.15.1, `vlucas/phpdotenv` 5.8
  (see the upgrade notes), Larastan 3.13.1, Vite 8.3.5,
  `@rollup/rollup-linux-x64-gnu` 4.64.12 and the CodeQL SARIF upload action
  4.39.1.
  ([#145](https://github.com/lineledger/lineledger/pull/145)–[#152](https://github.com/lineledger/lineledger/pull/152))

### Fixed

- **Tax rows print the rate once.** The built-in tax names already include
  their rate, so totals read "GST (5%) 5.00%" on invoices, estimates, sales
  orders, credit memos, bills, purchase orders and vendor credits, on screen
  and in the PDF. The rate is now added only when the name doesn't already
  show it ("PST 7.00%" for a code named "PST"). If a name still shows an old
  rate, the real rate is printed beside it.
- **The classic invoice prints its document discount.** An invoice with a
  discount on the whole document printed the subtotal, tax and total with
  nothing between them, so the figures did not add up on the page. The
  discount now prints between the subtotal and the tax.
- **The classic invoice shows credits and write-offs.** When a credit memo or
  a write-off had closed part of an invoice's balance, the classic layout left
  it out: with no payment it printed only the total, as if the whole amount
  were still owed, and with a payment the balance due was less than the total
  minus the payment with nothing to say why. It now prints a Credits and
  adjustments line, and the balance due whenever anything has been settled.
- **The classic invoice prints your payment instructions.** The "How customers
  can pay" text from Settings → Invoices showed in the customer portal but
  never on the classic invoice PDF. It now prints under a How to pay heading
  in the footer of any invoice that still has a balance, as the portal does.
- **Backups of organizations with API keys restore again.** A backup never
  holds a key's secret, so the restore stopped with a database error for any
  organization that had ever created an API key, from the app or with
  `backup:import`. The keys now come back revoked, still linked to the audit
  entries they made, and the owner creates new ones. A restore that fails
  partway also no longer leaves an empty organization behind. Reported in #158.
- **Employee SINs survive a restore.** A restore wrote each SIN into the new
  organization unencrypted, where the app expects it encrypted, so every SIN in
  a restored organization was unreadable and sat unencrypted in the database.
  The T4 filed 000000000 in its place, the T4 PDF printed only the last four
  digits, the RL-1 and ROE exports failed, and the organization could never be
  backed up again. Nothing flagged it: `integrity:check` and `audit:verify`
  still passed. A restore now encrypts SINs with the server's own key, so they
  come back readable whichever server made the backup, with the same `APP_KEY`
  or another. Organizations restored before this release are repaired by
  `app:upgrade`, which now runs `backup:repair-encrypted-columns`: it encrypts
  the SINs a restore left unencrypted and lists any that this `APP_KEY` cannot
  decrypt, which have to be entered again (`--dry-run` shows both without
  changing anything). A SIN that can't be decrypted no longer stops a backup
  either: it is left out, and the restore preview says how many employees come
  back without one.
- **RL-1 and ROE exports file the unknown-SIN placeholder.** When a SIN can't be
  decrypted, for example after a database is restored under a different
  `APP_KEY`, the RL-1 and ROE XML exports failed. They now file 000000000,
  while the T4 XML export refuses such a SIN and names the employee.
- **Backups restore next to the organization they came from.** On the server
  that made it, a restore stopped with a database error for any organization
  that had ever invited a teammate, turned on inbox email, taken a card payment
  through Stripe or had an AI assistant propose a write, because it copied
  values no two organizations may share. The restored organization now gets
  its own: invitations never accepted, and an assistant's proposals still
  waiting to be confirmed, come back expired; inbox email comes back off, so
  mail to the original's forwarding address never reaches the copy; card
  payments keep the Stripe payment id in their reference. The same kind of
  failure as #158.
- **Voiding a foreign-currency document takes its foreign amount off too.** A
  void reversed each line in your home currency but not its foreign amount and
  rate, so a voided USD invoice, bill, credit memo, receipt, payment, deposit,
  cheque or transfer stayed on its accounts in USD. Every period-end revaluation
  then booked an unrealized gain or loss on that phantom balance, and voiding a
  credit memo or vendor credit that had closed an invoice or bill (Close settled
  balance) never re-opened it. Voids now carry the foreign amount and rate onto
  the reversing entry, and so does the draft Reverse creates from a journal
  entry. `app:upgrade` repairs earlier voids with a new step,
  `currency:backfill-reversal-memos`: it copies each original line's foreign
  amount onto the reversal, re-opens what a voided credit had kept closed, and
  lists any reversal edited since, which it leaves alone. `integrity:check` now
  reports a void that leaves foreign units behind. Revaluations already posted
  are not changed; see `UPGRADING.md`.
- **Foreign-currency bank lines post at their home value.** On a bank or
  credit-card account in a foreign currency, a line added from For Review or
  the statement import without a vendor or tax code (every deposit, and most
  fees and charges) was booked at the statement's foreign amount as if it were
  home currency, with no foreign amount kept: 100.00 USD went in as 100.00 CAD
  instead of 135.00 at a 1.35 rate. Home balances, exchange gains and losses
  and the period-end revaluation were then off by the exchange rate. These
  lines are now converted at the rate for the transaction date and keep the
  foreign amount, as expenses, deposits and transfers already did, so a line
  posts the same home value however it is recorded. Paying a bill from an
  account in the bill's own currency now also lowers that account's foreign
  balance, not only its home balance. A line with no rate for its date is
  refused until you enter one, and a line can't be categorized to an account
  in a third currency; record a transfer instead. Entries posted before this
  fix keep their amounts.
- **Foreign-currency accounts reconcile in their own currency.** A bank or
  credit-card account in a foreign currency could not be reconciled: the
  reconcile screen added up each transaction's home value, so a USD statement
  showing 1,000.00 never agreed with a 1,000 USD deposit booked at 1,400.00
  CAD, and the Difference reached 0.00 only if you typed a made-up balance in
  your home currency. Statement import compared the same home values, so it
  never matched the account's existing transactions and offered them to be
  added again. The reconcile screen, completed reconciliations and their CSV,
  Excel and PDF downloads, statement matching and the bank register now all
  work in the account's own currency, the one its statements are in. A
  service charge or interest entered when you reconcile is posted at its home
  value at that day's rate and keeps its foreign amount, instead of being
  booked as if it were home currency. A customer payment deposited straight
  into an account in the payment's own currency now raises that account's
  foreign balance too, so it reconciles and the period-end revaluation no
  longer counts it as a loss. A transaction recorded before its foreign amount
  was kept counts at the amount it was booked at when that was the
  statement's own figure (a line added from the statement feed, or a service
  charge or interest from an earlier reconciliation); any other counts as zero
  and is marked on the screen. If you reconciled a foreign account before by
  entering home-currency figures, correct the beginning balance of its next
  reconciliation with Edit details.
- **Journal entries on foreign-currency accounts record the foreign amount.** A
  journal line on a foreign bank or credit-card account, or a currency's
  receivable or payable account, kept only its home amount. So the documented
  way to open a USD bank account — a journal entry at the right exchange rate —
  left no US dollars behind its balance, and the next period-end revaluation
  wrote the whole balance off as an unrealized loss, even at an unchanged rate.
  A journal entry writing off part of a foreign invoice didn't reduce what the
  customer owed in their currency either, so Close settled balance couldn't
  close it. The journal form now takes such a line in the account's currency at
  an exchange rate filled in for the entry date (the rate a document would
  lock), shows its home equivalent read-only, and the entry's page shows the
  foreign amount and rate. The API takes and returns `currency_code`, `fx_rate`,
  `foreign_debit_cents` and `foreign_credit_cents` on journal lines and works out
  whichever is missing; a line that sends only home cents, like a recurring or
  template line, gets its foreign amount at the entry date's rate. The Opening
  Balances workspace no longer posts a draft trial balance target on a
  foreign-currency account: record that balance with a journal entry in the
  account's currency. Lines saved before the upgrade keep no foreign amount;
  `UPGRADING.md` says how to find and correct them.
- **An in-kind receipt created from a donation no longer books the gift
  twice.** Such a receipt can't be issued until the property is described, and
  the receipt form that takes the description also offered an Asset / expense
  account to debit. Picking one made Issue receipt post the gift a second time,
  doubling donation revenue and the asset in the statements and the T3010. A
  receipt created from a donation now never posts, whatever its draft says: its
  form shows the donation's donor, gift type, date and amount read-only and asks
  only for the receipt's own details, and saving or issuing drops any debit
  account. A stand-alone in-kind receipt still posts as before. An issued
  receipt that shows both a View donation and a View journal entry link was
  booked twice: Reissue it to reverse the extra entry, then issue the corrected
  draft and send it to the donor.
- **A receipt created from a donation stays in step with it.** Correcting a
  draft donation left its draft receipt at the amount, date, donor and gift type
  first keyed, and voiding a donation (a returned cheque) left its receipt
  issued and counted on the T3010. Saving a draft donation now updates its draft
  receipt, and voiding a donation voids its receipt, issued or draft, keeping
  the number. Issue receipt waits until the donation is posted — a draft may
  still be a pledge — and takes the donation's figures as they then stand. An
  issued receipt is never edited: if its donation still changes, which only a
  receipt issued before its donation was posted allows, it is voided and
  replaced by a corrected draft, as Reissue does. Reissue keeps the corrected
  draft linked to the donation.
- **The 1099 Summary uses the $2,000 threshold for 2026 and leaves out card
  payments.** It held every year to $600, so a vendor paid $600 to $1,999.99
  in 2026 showed as needing a 1099-NEC. The IRS threshold is $2,000 for
  payments made in 2026 and stays $600 for earlier years. The threshold now
  follows the report's year on screen, in the PDF and in the MCP
  `form1099-tool`, and a later year whose inflation-adjusted figure the IRS
  has not published yet says so and uses the latest published one. The report
  also counted card payments, which the card company reports on Form 1099-K
  and which don't belong on a 1099-NEC. It now leaves out bill payments and
  expenses paid from a credit card account, or made with a payment method
  marked **Card or payment app** (new in Settings → Lists → Payment methods
  for US organizations), such as a debit card or PayPal paid from the bank.
  Credit card starts marked, as does any existing method with "card" in its
  name; mark PayPal, Venmo and the like yourself. Cheques, ACH and wire
  payments always count. New `payment_methods.is_card` column, also on the
  payment methods API.
- **Taking accrued vacation settles Vacation Payable.** Under Accrue to
  liability, the default, vacation pay is expensed as Vacation Pay Expense as
  it is earned. But when an employee then took that vacation, the pay was
  expensed again as wages, Vacation Payable was never reduced, and the vacation
  pay balance on their setup page and in the employee portal didn't go down.
  Payroll expense was overstated by every dollar of vacation paid, and
  Vacation Payable only ever grew. Posting a run now charges the part of the
  vacation pay that the employee's accrued balance covers to Vacation Payable
  (or a Dollars policy's own liability account) instead of wages, and lowers
  the balance by the same amount. This applies to time off on a policy whose
  code is `vacation`, or on a Dollars policy. A salaried employee's salary
  keeps paying through vacation, so the hours they take count at their hourly
  rate. Pay beyond what has accrued is still paid and stays wage expense, and a
  Dollars policy no longer draws down an hours balance it doesn't have. Gross
  pay, source deductions, remittances and T4s are unchanged, voiding a run
  puts everything back, and runs posted earlier are left as they were (see
  `UPGRADING.md` to correct them). New `pay_run_line_earnings.accrual_relief_cents`
  column.
- **Pay runs dated past the loaded tax tables are refused.** A pay run dated
  in a year with no tables yet was calculated on the previous year's: a January
  2027 run reused the July 2026 CPP, EI and income-tax figures, although base
  CPP drops to 4.75% on January 1, 2027. A table now covers pay dates only to
  December 31 of its own year, so from January 1 a run can't be calculated or
  posted until that year's tables are added. The run stays a draft and says
  why, in English or French, instead of showing an error, and Use current basic
  amounts on the employee form no longer fills in last year's amounts. A run
  calculated on the old tables before this release can't be posted either. Pay
  dates through December 31, 2026 are unchanged.
- **Payroll withholds the right tax for TD1 claims of $0 and partial claims.**
  A TD1 total claim of $0 (claim code 0, for an employee with a second job) and
  a partial claim below the basic personal amount (a newcomer's prorated
  claim) were both credited the full basic amount, so too little tax was
  withheld: about $2,960 a year for an Ontario second job paid $1,500 every two
  weeks. The federal and provincial credits now use the claim as entered, as
  CRA's T4127 says, and a Quebec TP-1015.3-V line 10 of $0 gets no Quebec
  credit (deduction code O). A claim of an earlier year's basic amount still
  counts as this year's. Three hand-worked T4127 cases join
  `payroll:verify-calculations`.
- **Two pay runs can no longer both take the last of the CPP and EI room.** A
  regular run and a bonus run on the same pay date, or a run calculated before
  an earlier one was posted, each took the full room left under the annual
  CPP, CPP2, EI, QPP, QPP2 and QPIP maximums, so the employee and the employer
  paid too much. The year-to-date now counts every posted pay run and every
  calculated one that comes before it (by pay date, then the order the runs
  were created). Posting stops while an earlier calculated run for the same
  employee is unposted (post it, or save it back as a draft), asks for a
  recalculation when another run was calculated, posted or voided after this
  one was calculated, and refuses an adjustment that would go past a maximum.
  A run calculated before this release is recalculated before it posts. New
  `pay_run_lines.ytd_basis_hash` column.
- **Indian Act exempt income goes in T4 code 71, not box 14.** A new **Indian
  Act exempt income** switch on the Tax credits (TD1) card withholds no income
  tax and reports the pay under code 71 with box 14 at zero, and RPP
  contributions and union dues on it under codes 94 and 95; the T4 XML files
  them as `indn_empe_amt`, `indn_xmpt_rpp_amt` and `indn_xmpt_unn_amt`. EI
  still applies, and CPP or QPP only where you elected coverage on form CPT124
  (RR-2-V in Quebec), so without that election also turn on CPP exempt.
  **Income tax exempt** is unchanged: no tax withheld, but the pay is still
  taxable in box 14. If you used it for a status Indian employee, turn on the
  new switch for them: pay runs calculated before this release follow it, so
  their 2026 T4 moves to code 71. New `employee_payroll_profiles` and
  `pay_run_lines` `indian_act_exempt` columns.
- **T4 and T4A XML follow the CRA's current schema.** The CRA would have
  rejected every T4 and T4A file the app produced: they used the retired T619
  transmittal (an "MM" transmitter number, a hyphenated submission reference),
  element names the CRA doesn't define (`cpp2_cntrb_amt`, `ei_insur_ern_amt`,
  `fees_srvc_amt`, `tot_cpp_cntrb_amt`…) and no box 24, box 45 or summary
  contact. Both now follow the CRA's 2027 XML specifications (T619 2027V1,
  T4 2027V2, T4A 2027V1) and pass its schema package 1-27-1, which the tests
  check every generated file against. The T4 reports employer CPP and CPP2
  apart, QPP and QPIP rather than CPP for Quebec employees, and amounts tagged
  with an other-information code (30–99) or box 20, 44, 46 or 52, moving any
  past six onto an additional slip as the CRA asks. A T4A names an individual
  by name and SIN, or a business by its corporation name and business number.
- **T4 and T4A files carry the payroll (RP) account, not the GST/HST number.**
  Slips, summaries and the transmittal now use the Business Number and Payroll
  (RP) account from Settings → Payroll. The CRA XML download is refused, with
  what to fix listed on the T4 and T4A pages, until that account, the payroll
  contact (name, a 10-digit phone, email) and the new **Employer-offered dental
  benefits (T4 box 45)** code are set; an employee's payroll setup can override
  the code. An optional **Representative ID** covers a representative filing
  through Represent a Client. `CRA_TRANSMITTER_NUMBER` and
  `CRA_TRANSMITTER_TYPE` are no longer read, and `CRA_TRANSMITTER_LANGUAGE`
  now defaults to each organization's language. New
  `companies.payroll_dental_benefits_code` and `payroll_cra_rep_id`, and
  `employee_payroll_profiles.dental_benefits_code` columns.
- **Employee SINs are checked when entered.** Payroll setup accepts nine digits
  that pass the SIN check digit, or 000 000 000 when the employee hasn't given
  one yet (saved as no SIN). A SIN already on file that fails the check or no
  longer decrypts blocks the T4 XML and names the employee, instead of filing
  a bad number or 000000000, which the CRA allows only when no SIN was given.
  An employee with no SIN still files as 000000000, with a warning.
- **RL-1 slips report every box they compute the way Revenu Québec asks.**
  Box A left out taxable benefits given in kind (an employer-paid group life
  premium, say), so it came out lower than T4 box 14; box B added the second
  additional QPP contribution to the first; and the Employment Insurance
  premiums withheld from Quebec employees appeared nowhere. Following the RL-1
  guide (RL-1.G-V, 2026-10), box A now includes those benefits, box B is split
  into B.A (base and first additional QPP) and B.B (second additional QPP), and
  box C reports the EI premiums, on the RL-1 page, in the slips CSV, the slip
  and summary PDFs and the XML; boxes the guide leaves blank stay blank, and
  the summary splits the employer QPP contribution into its lines 22 and 24.
  The XML now files the slips under your Revenu Québec identification number
  and file number (for example 1234567890 and RS0001), set in a new Revenu
  Québec source deductions block in Settings → Payroll, instead of the GST/HST
  number, and waits until both are set. Slips finalized before this fix are
  shown as issued, with a note to unlock and finalize them again (and to file
  amended slips for any already filed). Official paper RL-1 slips still need a
  Revenu Québec authorization number, and Revenu Québec takes RL-slip XML only
  from software it has certified. New `companies.payroll_rq_identification_number`
  and `payroll_rq_file_number` columns.
- **The ROE Web XML is a file ROE Web accepts.** The download used made-up
  element names and numbered the blocks wrongly (the SIN as Block 14, the
  employee as Block 19), so it failed Service Canada's schema. It is now the
  ROE Web payroll extract, version 2.0, checked against that schema: Block 5
  carries your CRA payroll account (the Business Number and RP account from
  Settings → Payroll, never the GST/HST number), Block 6 the pay period type,
  Block 8 the SIN, Block 9 the employee's name and address, Block 15A the
  insurable hours rounded up to whole hours, Block 15C every consecutive pay
  period (0.00 for one with no pay), and Block 16 the three-character reason
  code with your payroll contact. It downloads as a `.BLK` file to upload in ROE
  Web with Upload a Payroll Extract File, where it arrives as a draft to check
  and submit. Without a payroll account or payroll contact, or before any pay
  is posted for the employee, the page says what to fix instead of downloading
  a file ROE Web would refuse.
- **The ROE reports all insurable earnings.** Each pay period on the ROE
  showed only the earnings under the EI maximum insurable earnings ($68,900
  for 2026), so a high earner's last pay periods looked like periods with no
  pay and their EI benefit rate came out too low. The ROE now reports every
  insurable dollar, as Service Canada requires, and the worksheet's Block 15B
  totals the last 14 biweekly (27 weekly, 13 semi-monthly, 7 monthly) pay
  periods, as the ROE guide counts them. T4 box 24 stays capped. New
  `pay_run_lines.roe_insurable_earnings_cents` column; pay runs calculated
  before it are read from their earning lines, so there is nothing to backfill.

### Security

- **Losing access now applies to pages already open.** Buttons, forms and
  searches on an open page checked that you were signed in, but not that you
  could still use the page, so someone removed from an organization, moved to a
  role or sections without that page, or no longer a site admin could keep
  working in it. Each action now re-checks what loading the page does:
  membership and role, section access, sections switched off for the platform,
  the organization's two-factor requirement, the portal audience and site-admin
  status. The organization the page works in must also still be yours,
  including in search and navigation on settings pages; the site admin area and
  the customer and employee portals keep their own rules.
- **Every account a form sends is checked against your organization.** Some
  forms took an account id from the page and used it as it came, so a tampered
  request could name an account of another organization. Each of these now
  accepts only your organization's own accounts, of the kind the field holds (a
  bank account to pay from, an expense to charge, a liability to owe): the
  journal entry form, including Save changes on a posted entry and Save as
  template, and the invoice form's Save as template; the penalty, interest and
  commission accounts on a tax-return payment; the service charge and interest
  accounts on a reconciliation; the For Review feed (Accept, bulk categorize,
  Split and Always do this) and the statement import wizard; and the payroll
  pages: pay runs, payroll cheques, the PD7A, Revenu Québec and workers'
  compensation remittances, and the wage, earning, deduction, pay schedule and
  time-off policy fields of an employee's setup. A bank-feed category also can't
  be the Accounts Receivable, Accounts Payable or Undeposited Funds control
  account, which the category picker already leaves out. Behind the forms,
  the ledger itself now refuses to post, repost or save a journal line on
  another organization's account, whichever form, API call or job built it.
  The API already checked these ids.
- **Payments and deposits are checked on the server before they are saved.**
  Receive payment, Pay bills (one payee or the batch page), Make deposit and
  the API now check every invoice or bill a payment is applied to, and every
  receipt on a deposit, against the database instead of trusting the copy the
  page or the request carried. A payment applies only to posted invoices (or
  bills) of the same organization and the same customer (or payee, and the
  same kind: vendor bill or reimbursement), for no more than each still owes.
  A deposit takes only posted receipts of the same organization that were
  received into Undeposited Funds, each once, not already on another deposit
  that hasn't been voided, and for the receipt's own amount. On the forms only
  the amounts you type and the receipts you tick come from the browser; which
  document a row names, and its open balance, stay with the server. Posting a
  draft payment or deposit later checks it again, in case the invoice was paid
  or the receipt deposited meanwhile. A refused line shows as a form error, or
  a 422 from the API, naming the document. Overpayments still stay on account
  as credit, and card refunds still net out of a deposit. **Self-hosters with
  more than one organization should upgrade.**
- **Pages stay within the organization you're working in.** The audit trail on
  a journal entry, the bank register, and the summary in the account, customer
  and vendor merge dialogs could be made to show another organization's audit
  events, bank and card balances, or record counts. Each now reads only the
  current organization's records, and the register answers another
  organization's account with "not found". Likewise, creating a fixed asset
  from a transaction line prefills only from your own organization's lines,
  the restore page shows and cancels only restores you uploaded, and removing
  a member acts only on a non-owner member of that organization. A new test
  fails the build when a page lets an id sent by the browser reach a query the
  organization filter doesn't cover.
- **`symfony/mime` 8.1.10** (from 8.1.7) fixes an email header injection through
  MIME parameter names that contain non-token characters
  ([CVE-2026-45070](https://symfony.com/cve-2026-45070)). LineLedger never takes a
  parameter name from user input (Symfony sets them: `filename`, `charset`, …),
  so it wasn't exposed; the update keeps the dependency audit clean. The release
  also escapes quotes and backslashes in quoted parameter values, such as
  attachment filenames.
  ([#160](https://github.com/lineledger/lineledger/pull/160))
- **`laravel/mcp` 1.1.1** (from 1.0.2) caps MCP's dynamic client registration
  at 10 redirect URIs of up to 1,024 characters each. MCP clients call that
  endpoint without signing in, and until now one registration could store any
  number of redirect URIs of any length.
  ([#145](https://github.com/lineledger/lineledger/pull/145))
- **Links in email always point at `APP_URL`, and the app answers only on its
  own host names.** Password-reset, email-verification, invitation and portal
  sign-in links were built on whatever host name the request that sent them
  arrived with, so a genuine email could carry its link to a site that isn't
  yours. Every link in an email is now built on `APP_URL`. Outside local
  development, a request for any host other than `APP_URL`'s, or one listed in
  the new `TRUSTED_HOSTS`, is refused with 400 Bad Request, and
  `X-Forwarded-Host` is no longer honoured from anyone.
- **Forwarded client addresses count only from proxies you name.** Every peer's
  `X-Forwarded-For`, `-Proto` and `-Port` headers were trusted, so on an install
  reached directly (the one-click Droplet, Docker publishing ports 80/443) a
  client could choose the IP address the sign-in throttle, the security monitor
  and the security and audit logs saw. The new `TRUSTED_PROXIES` lists the load
  balancers and proxies that may set them; it is empty by default, which trusts
  none, and the App Platform spec sets it to `*`.
- **The sign-in throttle also counts per account.** Besides five attempts a
  minute for one email address from one IP, an address now gets ten a minute in
  all, however many IPs they come from. `security:monitor` and Admin → Security
  also flag failed sign-ins grouped by the address tried, so guessing spread
  across many IPs still raises an alert.

### Upgrade notes

- **`APP_URL` must be the address people use.** Requests for any other host get
  400 Bad Request; list the other names an installation answers on, including
  any that Stripe's webhook or an inbound-email provider calls, in
  `TRUSTED_HOSTS`.
- **Behind a load balancer, CDN or reverse proxy, set `TRUSTED_PROXIES`** to the
  address it connects from, and have it pass the `Host` header through.
  Otherwise every visitor shows up with the proxy's IP, HTTPS goes undetected,
  and signed links (email verification, file uploads) fail. On **App Platform**,
  add `TRUSTED_PROXIES=*` to an existing app's environment before redeploying:
  `deploy.sh` doesn't update an existing app's spec. On **Forge** with a
  Cloudflare-proxied domain, use Cloudflare's IP ranges. The Droplet and Docker
  reached directly need nothing.
- **On Forge or bare metal, check `.env` for an unclosed double quote.**
  `vlucas/phpdotenv` 5.8 refuses to start the app ("The environment file is
  invalid!") where 5.7 silently ignored that setting and every line after it.
  Docker, the Droplet and App Platform pass settings as environment variables
  and aren't affected.
- See `UPGRADING.md` for the step-by-step.

## [1.3.1] - 2026-10-24

**Highlights.** A patch for the first sign-in on a new install: a site admin
without two-factor authentication is now told to turn it on before setting up
email, instead of landing on Settings → Security with no explanation. The
DigitalOcean Marketplace image build also passes DigitalOcean's image check, and
Livewire moves to 4.4.8 for a security advisory. No migrations, no backfills and
no configuration changes; see `UPGRADING.md`.

### Fixed

- **"Set up email" no longer dead-ends for a new site admin.** The site admin
  area needs two-factor authentication, so the banner's link sent an admin
  without it to Settings → Security with no explanation. The banner now says
  two-factor authentication comes first and links to it, and the Security page
  says why you were sent there (the site admin area, or an organization that
  requires two-factor authentication).
  ([#143](https://github.com/lineledger/lineledger/pull/143))
- **The Marketplace image passes DigitalOcean's image check.** The Packer
  build installs the pending Ubuntu security updates, removes DigitalOcean's
  agents and `/opt/digitalocean`, and ends by running DigitalOcean's
  `99-img-check.sh`, so a build that would be rejected fails before it makes a
  snapshot. ([#142](https://github.com/lineledger/lineledger/pull/142))

### Security

- **`livewire/livewire` 4.4.8** (from 4.4.7) fixes an authorization bypass in
  the `#[Authorize]` attribute
  ([GHSA-mhf7-xp6r-6qrc](https://github.com/advisories/GHSA-mhf7-xp6r-6qrc)).
  LineLedger doesn't use `#[Authorize]` or `#[Json]`, so it wasn't exposed;
  the update keeps the dependency audit clean.
  ([#144](https://github.com/lineledger/lineledger/pull/144))

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

[Unreleased]: https://github.com/lineledger/lineledger/compare/v1.5.0-rc.1...HEAD
[1.5.0-rc.1]: https://github.com/lineledger/lineledger/compare/v1.4.3...v1.5.0-rc.1
[1.4.3]: https://github.com/lineledger/lineledger/compare/v1.4.2...v1.4.3
[1.4.2]: https://github.com/lineledger/lineledger/compare/v1.4.1...v1.4.2
[1.4.1]: https://github.com/lineledger/lineledger/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/lineledger/lineledger/compare/v1.3.1...v1.4.0
[1.3.1]: https://github.com/lineledger/lineledger/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/lineledger/lineledger/compare/v1.2.2...v1.3.0
[1.2.2]: https://github.com/lineledger/lineledger/compare/v1.2.1...v1.2.2
[1.2.1]: https://github.com/lineledger/lineledger/compare/v1.2.0...v1.2.1
[1.2.0]: https://github.com/lineledger/lineledger/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/lineledger/lineledger/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/lineledger/lineledger/releases/tag/v1.0.0
