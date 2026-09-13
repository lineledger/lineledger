# Security Review Fixes — Commit Messages & Rationale

This document groups the changes from the `local/security-review` branch by security finding and provides detailed commit message templates ready to use.

See `docs/SECURITY_REVIEW_2026-08-18.md` for the full security review context, methodology, and verification results.

---

## Finding #1: Inconsistent Posted-Document Deletion Guards

**Status**: Fixed ✓

**Severity**: Medium — accounting integrity

**What was wrong**: Eight models (CreditMemo, Cheque, Deposit, TaxReturnPayment, Transfer, StockAdjustment, PayRun, JournalEntry) lacked the `GuardsPostedDeletion` trait. Direct DB access or artisan commands could delete these models after posting, orphaning their linked GL entries.

**Files modified**:
- `app/Concerns/GuardsPostedDeletion.php` — trait generalized to accept per-model hook
- `app/Models/Cheque.php`, `CreditMemo.php`, `Deposit.php`, `JournalEntry.php`, `PayRun.php`, `StockAdjustment.php`, `TaxReturnPayment.php`, `Transfer.php` — all now use trait
- `app/Observers/Audit/AuditableObserver.php` — added these eight models to ACTIONS so deletion attempts are logged
- `tests/Feature/Accounting/PostedDocumentDeletionGuardExtendedTest.php` (new) — verifies the guard

### Commit Message Template

```
Extend posted-document deletion guard to all GL-linked models

Apply GuardsPostedDeletion trait to CreditMemo, Cheque, Deposit,
TaxReturnPayment, Transfer, StockAdjustment, PayRun, and JournalEntry.
Previously only Invoice, Bill, BillPayment, and CustomerReceipt were
protected. Generalized the trait to use an optional isPostedForDeletionGuard()
hook per model since not all models use the same journal_entry_id check.

Add these eight models to AuditableObserver to log deletion attempts even
when the guard blocks them. This prevents artisan tinker-style force deletes
from orphaning GL entries on posted documents.

Fixes accounting-integrity gap described in security review finding #1.
Verified by tests/Feature/Accounting/PostedDocumentDeletionGuardExtendedTest.php.
```

---

## Finding #2: Silent Balance-Cache Healing

**Status**: Fixed ✓

**Severity**: Medium — accounting integrity

**What was wrong**: `CheckLedgerIntegrity --fix` would silently heal balance-cache drift without alerting. The `--fix` flag should correct the cache but still surface the drift for audit purposes.

**Files modified**:
- `app/Console/Commands/CheckLedgerIntegrity.php` — appends issue record and exits non-zero even on --fix
- `tests/Feature/Console/CheckLedgerIntegrityTest.php` — new test case for this behavior

### Commit Message Template

```
Alert on balance-cache drift even when --fix auto-heals it

CheckLedgerIntegrity now appends an issue record when a drift is found
and healed with --fix, instead of silently continuing. The command still
exits non-zero and alerts exactly as if --fix were not set; --fix only
changes whether the cache is corrected in the same run, never whether
the drift is surfaced.

This ensures drift events are never hidden, maintaining visibility of
balance-cache discrepancies for audit and forensic purposes. Prevents
operators from accidentally masking integrity issues.

Fixes accounting-integrity gap described in security review finding #2.
Verified by tests/Feature/Console/CheckLedgerIntegrityTest.php.
```

---

## Finding #3: Missing 2FA Enforcement on API/MCP

**Status**: Fixed ✓

**Severity**: Medium — authorization

**What was wrong**: 2FA enforcement only applied to the web UI. API keys and MCP OAuth tokens bypassed the requirement entirely.

**Files modified**:
- `app/Http/Middleware/EnforceTwoFactor.php` — extracted core logic to TwoFactorRequirement
- `app/Http/Middleware/EnforceTwoFactorForApi.php` (new) — applies enforcement to API/MCP
- `app/Support/Security/TwoFactorRequirement.php` (new) — shared enforcement logic
- `bootstrap/app.php` — registered EnforceTwoFactorForApi as enforce.2fa_api
- `routes/ai.php` — applied to all four MCP route groups
- `routes/api.php` — applied to versioned REST API
- `tests/Feature/Security/TwoFactorApiEnforcementTest.php` (new) — verifies enforcement

### Commit Message Template

```
Enforce 2FA requirement across API keys and MCP OAuth tokens

Extract shared 2FA enforcement logic to TwoFactorRequirement::isUnmet()
and apply it via new EnforceTwoFactorForApi middleware on all MCP route
groups (routes/ai.php) and the versioned REST API (routes/api.php).

Previously 2FA enforcement only covered the web UI via EnforceTwoFactor.
API callers could bypass the requirement entirely. Now a company that
mandates 2FA for admins gets that protection uniformly across web UI,
API keys, and MCP tokens. Closes the gap once MCP_WRITE_ENABLED is turned on.

Fixes authorization gap described in security review finding #3.
Verified by tests/Feature/Security/TwoFactorApiEnforcementTest.php.
```

---

## Finding #5: Missing DB-Level Immutability on Posted Rows

**Status**: Fixed ✓

**Severity**: Low (defense-in-depth) — accounting integrity

**What was wrong**: Posted GL entries could be mutated at the database layer (via direct SQL, raw PDO, or compromised app container) without going through the posting logic. App-layer validation alone isn't sufficient.

**Files modified**:
- Database migration `2026_09_04_000000_add_posted_ledger_immutability_triggers.php` (new) — four MySQL triggers
- `app/Services/Audit/PostedMutationGate.php` (new) — session-scoped escape hatch for legitimate mutations
- `app/Services/Posting/JournalPoster.php` — wrapped in PostedMutationGate
- `app/Services/Posting/BillPoster.php`, others — wrapped in PostedMutationGate
- `app/Actions/Accounting/SaveJournalEntry.php` — repost-in-place path wrapped
- `app/Actions/Accounting/MergeAccounts.php` — account repointing wrapped
- `app/Actions/Contacts/MergeContacts.php` — contact repointing wrapped
- `resources/views/pages/banking/⚡register.blade.php` — bank-register reconciliation wrapped
- `resources/views/pages/reports/⚡unattributed-ar.blade.php` — AR attribution wrapped
- Many test files — wrapped test fixtures that deliberately fake posted-row tampering
- `tests/Feature/Accounting/PostedLedgerImmutabilityTriggerTest.php` (new) — exercises trigger directly

### Commit Message Template

```
Add MySQL triggers to prevent posted journal entry mutations

Create database triggers that block UPDATE/DELETE on posted journal_entries
and journal_lines unless a session-scoped escape hatch (PostedMutationGate)
is active. Defense-in-depth measure: even a compromised app container or
raw DB access can no longer mutate posted GL rows.

Wrap all legitimate code paths that need to mutate posted rows:
- bank-register reconciliation metadata (cleared-status toggling)
- account/contact merges (repointing account_id/contact_id)
- AR-line customer attribution
- SaveJournalEntry's repost-in-place flow

Each call site was individually reviewed to confirm it doesn't defeat the
trigger's intent (no debit/credit/date mutation on a posted row outside a
proper void+repost). This is defense-in-depth; app-layer posting logic
remains the primary control.

Fixes accounting-integrity gap described in security review finding #5.
Verified by tests/Feature/Accounting/PostedLedgerImmutabilityTriggerTest.php
and full test suite (3259 tests, 0 regressions).
```

---

## Finding #7: Livewire AJAX Missing Membership Re-Check

**Status**: Fixed ✓

**Severity**: Low (mitigated by SESSION_DRIVER=database) — authorization

**What was wrong**: Livewire AJAX requests only checked company membership on initial page load (`mount()`), not on subsequent AJAX calls. A revoked user's open tab could continue reading/writing until manual refresh or session expiry.

**Files modified**:
- `app/Livewire/Hooks/BindCurrentCompanyHook.php` — added re-validation to hydrate(), call(), update(), render()
- `tests/Feature/Security/LivewireCompanyRevocationTest.php` (new) — verifies revocation is enforced
- Multiple test fixtures — corrected to attach real company membership (CashFlowStatementTest, ColumnTogglesTest, ManagementReportPackageTest, ReportEmailTest, ReportNotesTest, ReportNumberFormatTest, InvoiceSecondaryTaxTest, etc.)

### Commit Message Template

```
Enforce company membership on every Livewire AJAX call, not just mount

BindCurrentCompanyHook now re-validates user's company membership in
hydrate(), call(), update(), and render() hooks on every AJAX request.
Previously the check only happened on initial page load; a revoked user's
open tab could continue reading/writing until manual refresh or session
kill server-side.

Scoped to web guard only (portal components use separate customer guard and
must not be affected). Exempts site admins (admin portal's authorization
model is "is site admin", not "belongs to this company"). This closes the
AJAX gap independent of SESSION_DRIVER setting; the previous mitigation via
database sessions is no longer load-bearing, though it remains best practice.

Fixes authorization gap described in security review finding #7.
Verified by tests/Feature/Security/LivewireCompanyRevocationTest.php.
```

---

## Finding #8: Unconfirmed Rate Limiting on Portal Login

**Status**: Fixed ✓

**Severity**: Low — web vulnerability

**What was wrong**: Portal magic-link request endpoint had no confirmed rate limiting implementation.

**Files modified**:
- `app/Actions/Portal/ThrottlePortalLoginRequest.php` (new) — implements two independent rate limits
- `resources/views/pages/portal/⚡login.blade.php` — integrated throttling, added validation message display
- `tests/Feature/Portal/ThrottlePortalLoginRequestTest.php` (new) — verifies rate limits

### Commit Message Template

```
Add rate limiting to portal login-request endpoint

Implement two independent RateLimiter caps on the magic-link login path:
- portal-login:{company}|{email}|{ip}: 5 attempts / 15 minutes
- portal-login-email:{company}|{email}: 10 attempts / hour

The per-email cap (10/hour) prevents rotating-IP bypasses of the per-IP-per-email
cap (5/15min). Throws ValidationException when limits are exceeded. Wired into
both the portal login and employee-portal login pages. Users receive validation
messages when throttled.

Fixes web-vulnerability gap described in security review finding #8.
Verified by tests/Feature/Portal/ThrottlePortalLoginRequestTest.php.
```

---

## Finding #3i: Bonus — Audit Trail Gap on AR Attribution

**Status**: Fixed ✓

**Severity**: Medium (bonus finding) — accounting integrity

**What was wrong**: AR customer attribution via the unattributed-ar page performed a raw `DB::table()->update()` with no audit-log entry. AuditableObserver only instruments Eloquent model mutations, not raw query builder calls.

**Files modified**:
- `app/Enums/AuditAction.php` — added JournalLinesArAttributed action
- `resources/views/pages/reports/⚡unattributed-ar.blade.php` — added audit recording + PostedMutationGate wrap
- `app/Observers/Audit/AuditableObserver.php` — registered the new action
- Test coverage — implicit via existing unattributed-ar tests now wrapped in PostedMutationGate

### Commit Message Template

```
Add audit logging for AR customer attribution

Fix audit-trail gap where raw DB::table() update in unattributed-ar
assignment path generated no audit-log entry. AuditableObserver only
instruments Eloquent mutations, not raw query builder calls.

Add JournalLinesArAttributed audit action and call AccountingAuditRecorder
after a successful attribution. Wrap the mutation in PostedMutationGate
since the new DB triggers (finding #5) require it. This ensures every
user-initiated data change is captured in the audit trail per standing
requirements (AppServiceProvider::73-74, AuditableObserver design).

Found and fixed as a byproduct of finding #5 verification. Not part of
the original 9 findings but addresses the same audit-trail integrity gap.
```

---

## Test Fixture Corrections

**Status**: Fixed ✓

**Severity**: None (correctness) — test infrastructure

**What was wrong**: New Finding #7 enforcement on every Livewire AJAX call surfaced pre-existing test-fixture gaps: 17 tests created Livewire-testing users without attaching real company membership, and 2 tests omitted the `company` prop from `Livewire::test()` entirely.

**Files modified**:
- `tests/Feature/Reporting/CashFlowStatementTest.php`
- `tests/Feature/Reporting/ColumnTogglesTest.php`
- `tests/Feature/Reporting/ManagementReportPackageTest.php`
- `tests/Feature/Reporting/MemorizedGroupExportTest.php`
- `tests/Feature/Reporting/MemorizedReportTest.php`
- `tests/Feature/Reporting/ReportEmailTest.php`
- `tests/Feature/Reporting/ReportNotesTest.php`
- `tests/Feature/Reporting/ReportNumberFormatTest.php`
- `tests/Feature/Reporting/ScheduledReportEmailTest.php`
- `tests/Feature/Invoices/InvoiceSecondaryTaxTest.php`
- And several migration/restore tests

### Commit Message Template

```
Fix test fixtures for new company-membership enforcement

Update test fixtures to attach real company membership to Livewire test
users. Previously many fixtures created a testing user without proper
company association, which was masked by the old enforcement model that
only checked company access on mount(). New enforcement on every AJAX call
(finding #7) surfaced these gaps.

No test assertions were weakened—fixtures are corrected to align with
production usage patterns. Every other test in the suite already exercises
these components with proper company membership; these updates bring
lagging fixtures into consistency.

This is a side effect of finding #7 enforcement, not a bug in finding #7
itself—it merely revealed pre-existing fixture gaps.
```

---

## Database Migration (if separate)

**Files modified**:
- `database/migrations/2026_09_04_000000_add_posted_ledger_immutability_triggers.php`

### Commit Message Template (if committed separately)

```
Add posted ledger immutability triggers

Database migration adding four MySQL `BEFORE UPDATE`/`BEFORE DELETE`
triggers on journal_entries and journal_lines that prevent any mutation
when is_posted = 1, unless app-layer escape hatch (@ll_allow_posted_mutation
session variable via PostedMutationGate) is active.

Defense-in-depth measure: even direct DB access or a compromised app
container can no longer mutate posted GL rows. App-layer posting logic
remains the primary control; this closes the direct-DB gap.

Related to security review finding #5. See that section in
docs/SECURITY_REVIEW_2026-08-18.md for full rationale and the whitelist
of legitimate code paths wrapped in PostedMutationGate.

No-ops on non-MySQL drivers (up/down).
```

---

## Suggested Commit Order

1. **Finding #1** — Posted-document deletion guards (narrowest scope, easiest to verify)
2. **Finding #2** — Balance-cache healing (small, isolated change)
3. **Finding #3** — 2FA on API/MCP (middleware + routes, self-contained)
4. **Finding #5** — Posted ledger immutability (largest, most interconnected—includes migration + PostedMutationGate + all wrapping)
5. **Finding #7** — Livewire AJAX enforcement (medium-sized; test fixture corrections can be part of this or separate)
6. **Finding #8** — Portal rate limiting (self-contained action + route + tests)
7. **Bonus #3i** — AR attribution audit logging (small, leverages existing Finding #5 infrastructure)
8. **Test fixtures** — (optional separate commit, or roll into Finding #7)

Or combine all fixes into a single commit with subsections in the message if the review prefers a unified "security review fixes" commit.

---

## Post-Commit Steps

1. Push to GitHub (this branch is local-only per §1 of security review)
2. Create PR with title: `Security review fixes: findings #1-3, #5, #7-8, #3i`
3. Reference `docs/SECURITY_REVIEW_2026-08-18.md` in PR body
4. Note that full test suite (3259 tests, 0 regressions) has been run; see §5 of review doc
5. Highlight that Findings #4 and #6 remain unchanged (documented tradeoffs, not bugs)

---

Generated: 2026-09-05
Branch: local/security-review
Review doc: docs/SECURITY_REVIEW_2026-08-18.md
