# QuickBooks Online Feature-Parity Gap Analysis

Date: 2026-09-05
Scope: compare LineLedger's current GL/AP/AR/invoicing/payroll/reporting functionality
against QuickBooks Online (Plus/Advanced tier) to identify concrete feature gaps.
Companion to `docs/SECURITY_REVIEW_2026-08-18.md` (security review, completed first
per stated priority).

Methodology: full codebase inventory (models, Livewire pages, migrations, MCP tools)
compared against known QBO Plus/Advanced feature set. Gaps are ranked by how much they
block a bookkeeper from fully replacing QBO with LineLedger day-to-day.

## Summary

LineLedger's depth is well beyond a typical bookkeeping app — it already matches or
exceeds QBO in several areas (multi-currency with HCA revaluation, FIFO/weighted-average
inventory costing, full Canadian payroll with T4/RL-1/ROE/PD7A filing, an MCP-based AI
interface, GIFI/T2125/T3010/T5013 tax-form support). The gaps below are genuine, not
nitpicks — each is a real QBO capability with no LineLedger equivalent found in the
codebase.

## 1. Blocking / high-value gaps

**No live bank feed connection (Plaid/Yodlee/Finicity-style).** `BankStatementImport`
only supports file-based CSV/Excel/OFX upload (`app/Models/BankStatementImport.php`,
`BankImportProfile`). QBO's core daily workflow is automatic transaction download from
a connected bank/credit-card account; LineLedger requires manual export/import every
time. This is the single biggest parity gap — everything downstream (bank rules,
reconciliation) already exists and works well, but the feed itself is missing.

**No progress invoicing.** QBO lets an estimate be billed in percentage installments
(e.g., 3 invoices at 30/30/40% of one estimate), tracking billed-vs-remaining per line.
No `progress_pct` or partial-billing-from-estimate logic found on `Estimate`/`Invoice`.
Currently an estimate can only become one invoice.

**No project/job-costing entity.** QBO Online Plus's "Projects" feature groups income,
expenses, time, and profitability by job, independent of `Classification`/`Location`.
LineLedger has no `Project` model — `Classification` and `Location` can approximate this
for reporting-dimension purposes but don't provide a single project-profitability view,
sub-customer/job hierarchy, or "committed cost" tracking (open PO/estimate vs. actual).

**No delayed charges / delayed credits.** QBO's "Delayed Charge" and "Delayed Credit"
are non-posting placeholders a bookkeeper records now and batches into an invoice or
credit memo later (common for time-and-materials billing). No equivalent model exists;
the closest LineLedger analogue is a draft `Invoice` or `CreditMemo`, which already
posts AR-relevant metadata differently than a true non-posting placeholder.

## 2. Moderate gaps

**No receipt OCR / mobile receipt capture.** `Attachment` supports storing files against
transactions but there's no extraction pipeline (vendor/amount/date auto-fill from a
photographed receipt) as QBO's "Receipt Snap" provides.

**No accountant "reclassify transactions" tool.** QBO Accountant lets a bookkeeper
bulk-reassign the account/class on a batch of posted transactions during month-end
cleanup. No equivalent bulk-reclassification UI or MCP tool found; today this requires
editing entries one at a time (and is now gated by the posted-ledger immutability
triggers from Finding #5 of the security review, which is correct for direct SQL
tampering but means a legitimate bulk-reclass feature would need to go through
`PostedMutationGate` explicitly, the same pattern already used by
`MergeAccounts`/`MergeContacts`).

**No mileage tracking.** QBO Self-Employed/Online has a mileage log (manual or
GPS-tracked) that converts to a vehicle-expense deduction. No `MileageEntry`-style model
found; this is lower priority for a primarily-Canadian small-business bookkeeping tool
but is a named QBO feature with no equivalent.

**No custom fields on transactions.** QBO allows adding company-defined fields to
invoices/estimates/sales orders (e.g., "PO Reference", "Job Site"). No custom-field
infrastructure found on any transaction model — fields are all fixed schema.

**No automated sales-tax rate lookup / e-filing to tax agencies.** `TaxCode`/`TaxAgency`/
`TaxReturn` track rates and filing status internally and can produce filing-ready reports,
but there's no integration that automatically determines tax rate by ship-to address
(QBO's "Automated Sales Tax") or electronically files/remits to a government API. Given
LineLedger's Canadian focus (GST/PST/HST by province, not US nexus-based sales tax), this
gap matters less than it would for a US-market product — most Canadian tax codes are
static by province rather than address-looked-up — but electronic filing/remittance
integration is still absent.

## 3. Minor gaps

**No inter-company transaction posting.** `ReportGroup`/`ReportGroupCompany` allows
combined *reporting* across companies a user owns, but there's no mechanism to post a
transaction that simultaneously affects two companies' books (e.g., an intercompany loan
or expense allocation) with automatic elimination entries. QBO Online doesn't have true
intercompany accounting either (that's a QuickBooks Enterprise/NetSuite-tier feature), so
this is a minor gap relative to QBO specifically, though relevant for the "GL/AP/AR
equivalent" standard more broadly.

**No vendor self-service portal.** The customer portal (`pages/portal`) and employee
portal (`pages/employee-portal`) are both solid, but there's no vendor-facing portal
(e.g., for a vendor to submit invoices/W-9-equivalent info, view payment status). QBO
itself doesn't have a true vendor portal either (that's a Bill.com/Melio-style feature),
so this isn't a QBO parity gap per se, but is worth flagging if "AP-equivalent" is meant
to include self-service.

**No generalized workflow/approval engine.** Multi-step approval chains (e.g., "bills
over $5,000 require a second approver before payment") don't exist as a configurable
rule system. The closest analogue is the MCP write-proposal confirm gate
(`ProposesWrites`/`ConfirmProposalTool`, see security review Finding #4) — but that's
specific to AI-agent-initiated writes, not a general accounting approval workflow. QBO
Advanced has a similar capability ("Custom approval workflows"); this would be a genuine
feature addition if pursued.

**No batch invoice send / batch PDF print** confirmed absent for invoices specifically
(batch actions do exist for bill payments — `pages/bill-payments/⚡batch.blade.php` — so
the pattern is established and could be extended to invoices without new architecture).

## 4. Explicitly not gaps (verified present, sometimes exceeding QBO)

- **Undeposited Funds handling** — present as a real system account
  (`Account`/`AccountSubtype`, referenced across `SalesReceiptPoster`/`DepositPoster`/
  `ReceiptPoster`), matching QBO's model of receipts landing in a clearing account until
  batched into a bank deposit.
- **Multi-currency** — `CompanyCurrency`, `ExchangeRate`, and `CurrencyRevaluation` with
  auto-reversing home-currency-adjustment entries is more automated than QBO's manual
  revaluation workflow in several respects.
- **Inventory costing** — FIFO and weighted-average via `StockLayer`/`StockMovement`;
  QBO Online Plus only supports FIFO, so LineLedger's costing-method choice exceeds QBO.
- **Payroll** — full Canadian payroll (T4/T4A/RL-1/ROE/PD7A/Revenu Québec remittances,
  time-off accrual, workers' comp) is far deeper than anything in QBO Online proper
  (QBO's payroll is a separate, US-centric add-on product); no equivalent gap exists.
- **Batch bill payments** — already implemented (`pages/bill-payments/⚡batch.blade.php`).
- **Recurring transactions** — `RecurringDocument` covers invoices/bills/sales receipts
  with auto-post-vs-draft-for-review modes; `RecurringJournalEntry` separately covers
  recurring JEs. This meets or exceeds QBO's recurring-transaction feature.
- **Reporting depth** — ~45 built-in reports including nonprofit statements and
  jurisdiction-specific tax forms (T2125/T3010/T5013/GIFI) that QBO Online doesn't
  natively produce at all (those require QBO + a separate tax product in the real QBO
  ecosystem).

## Suggested prioritization

If the goal is "closest to full QBO replacement for day-to-day bookkeeping," the
highest-leverage next steps in order are:
1. Live bank feed integration (§1) — the most-used QBO feature with no LineLedger
   equivalent; likely the single change most likely to be noticed by a daily user.
2. Progress invoicing (§1) — common in service businesses billing off estimates.
3. Project/job-costing entity (§1) — valuable for any customer doing job-based work,
   and the reporting/dimension infrastructure (`Classification`, `Location`) already
   provides most of the underlying plumbing.
4. Delayed charges/credits (§1) — smaller lift, mostly a new non-posting document type
   reusing the existing `Invoice`/`CreditMemo` line infrastructure.

Everything in §2–§4 is real but lower-impact, and in some cases (inter-company, vendor
portal, automated approval workflows) isn't actually a QBO parity gap at all — those are
noted only in case "QBO-equivalent" is meant more broadly than literal feature parity.
