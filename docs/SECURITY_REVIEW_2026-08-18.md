# LineLedger Security & Accounting-Integrity Review — 2026-08-18

Scope: fresh clone of `main` (`0725eb5`), reviewed for known-CVE exposure, common web
vulnerability patterns, accounting-integrity/audit-trail soundness, and multi-tenant
authorization. This is a point-in-time review of a self-hosted install at
`/srv/lineledger`; it has not been re-verified dynamically (no live exploitation
attempted) except where noted.

Local-only document — this branch (`local/security-review`) is not pushed to GitHub.

**Update — 2026-09-05**: Findings #1, #2, #3, #5, #7, and #8 have been fixed and
verified (code changes described in their sections below, plus a full test-suite run
against an isolated database — see §5 for methodology and final results). Findings #4
and #6 were re-reviewed and are unchanged from the original assessment — both remain
deliberate, documented tradeoffs rather than bugs, see their sections below for the
current reasoning. Finding #9 (base-image CVE tracking) is an ongoing operational
practice, not a one-time fix — see §1. A related audit-trail gap not in the original 9
findings was also found and fixed during verification — see §3i.

## Risk-ranked summary

| # | Finding | Area | Severity | Status |
|---|---|---|---|---|
| 1 | Posted-record deletion guard inconsistent across models | Accounting integrity | Medium | **Fixed 2026-09-05** |
| 2 | `integrity:check --fix` silently heals balance-cache drift | Accounting integrity | Medium | **Fixed 2026-09-05** |
| 3 | 2FA enforcement doesn't cover API keys / MCP OAuth tokens | Authorization | Medium | **Fixed 2026-09-05** |
| 4 | MCP write-proposal "confirm" is not a real human-in-the-loop gate | Authorization | Low–Medium | Open (feature is off by default) |
| 5 | Live ledger tables have no DB-level immutability (app-layer only) | Accounting integrity | Low (defense-in-depth) | **Fixed 2026-09-05** |
| 6 | Posted entries editable in place with no visible correction trail in reports | Accounting integrity | Low (by-design tradeoff) | Noted — re-reviewed, unchanged |
| 7 | Livewire AJAX doesn't re-check access after initial page load | Authorization | Low (mitigated by `SESSION_DRIVER=database`) | **Fixed 2026-09-05** (no longer dependent on session driver) |
| 8 | Portal magic-link *request* endpoint rate limiting unconfirmed | Web vuln | Low | **Fixed 2026-09-05** |
| 9 | `mysql:8.4` / `frankenphp` base images carry upstream CVEs | Dependency/image | Low–Medium | Track upstream (ongoing) |

No SQL injection, stored XSS, webhook-auth bypass, mass-assignment, or IDOR
vulnerabilities were found. `composer audit` and `npm audit` both report zero known
vulnerabilities in application dependencies.

---

## 1. Dependency & container image CVEs

- `composer audit --locked`: **0 vulnerabilities**.
- `npm audit`: **0 vulnerabilities**.
- `grype ghcr.io/lineledger/lineledger:latest --scope all-layers`: 2 Critical/High CVEs,
  both in Go modules compiled into the `frankenphp`/Caddy binary (`github.com/getkin/kin-openapi`
  GHSA-r277-6w6q-xmqw, `google.golang.org/grpc` GHSA-hrxh-6v49-42gf). Low real exposure —
  Caddy's admin API only binds to `localhost:2019` inside the container (confirmed in
  runtime logs), and neither module's vulnerable code path is reachable from the app's
  own Caddyfile config.
- `grype mysql:8.4`: large batch of upstream CVEs (Go stdlib, OpenSSL, gnutls, and
  Python packages bundled with MySQL Shell tooling) — not exposed via the `mysqld`
  network port itself, but the `mysql:8.4` tag floats, so periodic re-pulls are needed
  to pick up fixes as Oracle ships them.
- **Recommendation**: pin `LINELEDGER_VERSION` to a specific built commit/tag once one
  exists upstream (currently only `edge`/`sha-*` tags are published — this install
  builds from source instead, see prior conversation). Re-pull `mysql:8.4` periodically
  (`docker compose pull mysql && docker compose up -d mysql`).

## 2. Web application vulnerability patterns — clean

Reviewed: raw SQL/injection, file upload & attachment serving, Stripe/inbound-email
webhook signature verification, mass-assignment, stored XSS in Blade/Livewire, rate
limiting on auth-sensitive routes, hardcoded secrets.

No exploitable finding in any category. Notable hardening observed:
- `app/Support/Text/LineDescription.php` explicitly `e()`-escapes user text before any
  `{!! !!}` unescaped Blade output.
- `app/Models/Attachment.php:25-38` (`isInlineViewable()`) hard-whitelists inline
  Content-Types (PDF/PNG/JPEG/GIF/WEBP only, **no SVG/HTML**), backed by
  `X-Content-Type-Options: nosniff` in `app/Http/Middleware/SecurityHeaders.php:41`.
- `app/Http/Controllers/Stripe/WebhookController.php:26-38` fails closed
  (`abort_if($secret === '', 500, ...)`) before verifying the Stripe signature.
- `app/Http/Controllers/Inbound/InboundEmailController.php:40-79` — three-layer
  defense (per-company token, HMAC-SHA256 with `hash_equals()`, sender allow-list).

**Fixed (#8)**: `app/Actions/Portal/ThrottlePortalLoginRequest.php` now enforces two
independent `RateLimiter` caps on the login-request path — `portal-login:{company}|
{email}|{ip}` (5 attempts / 15 min) and `portal-login-email:{company}|{email}` (10
attempts / hour, so rotating IPs can't bypass the per-IP cap) — throwing a
`ValidationException` once tripped. Wired into both
`resources/views/pages/portal/⚡login.blade.php` and the equivalent employee-portal
login page. Covered by `tests/Feature/Portal/ThrottlePortalLoginRequestTest.php`.

## 3. Accounting integrity & audit trail

### 3a. Audit trail design — sound, tamper-evident within its scope
- `accounting_audit_logs` table (migration
  `2026_05_20_120000_create_accounting_audit_logs_table.php`): append-only, hash-chained
  (`row_hash = sha256(previous_hash . canonical_json(contents))`), unique per
  `(company_id, sequence)`.
- Chain-building is race-safe: `app/Services/Audit/AccountingAuditRecorder.php:22-67`
  locks the last row (`lockForUpdate()`) inside a transaction before computing the next
  link.
- MySQL `BEFORE UPDATE`/`BEFORE DELETE` triggers `SIGNAL SQLSTATE '45000'` to hard-reject
  any mutation of `accounting_audit_logs` at the DB layer, backed by an Eloquent-level
  guard in `app/Models/AccountingAuditLog.php:35-44` (belt-and-braces).
- Every accounting source document is auto-captured via
  `app/Observers/Audit/AuditableObserver.php` (registered globally,
  `AppServiceProvider.php:73-74`), with field-level dirty diffs and PII/derived-field
  stripping.

### 3b. Finding #5 — live ledger tables lack DB-level immutability — Fixed
Migration `2026_09_04_000000_add_posted_ledger_immutability_triggers.php` adds four
MySQL triggers (`journal_entries_no_update_when_posted`,
`journal_entries_no_delete_when_posted`, `journal_lines_no_update_when_posted`,
`journal_lines_no_delete_when_posted`) that `SIGNAL SQLSTATE '45000'` on any
`UPDATE`/`DELETE` of a row whose `is_posted = 1`, unless a session-scoped escape hatch
(`app/Services/Audit/PostedMutationGate.php`, a reentrancy-safe
`@ll_allow_posted_mutation` session variable) is active. No-ops on non-MySQL drivers.
This closes the direct-DB-access gap described in the original finding: even a
compromised app container or an operator with raw DB credentials can no longer mutate
a posted line without going through the gate.

`PostedMutationGate::within()` is deliberately used by every legitimate code path that
still needs to touch a posted row's data without going through `JournalPoster`/
`SaveJournalEntry`'s normal void-and-repost flow: bank-register cleared-status toggling
(`resources/views/pages/banking/⚡register.blade.php`), account/contact merges
(`app/Actions/Accounting/MergeAccounts.php`, `app/Actions/Contacts/MergeContacts.php` —
repointing `journal_lines.account_id`/`contact_id` without touching amounts or dates),
AR-line customer attribution (`resources/views/pages/reports/⚡unattributed-ar.blade.php`,
see §3i), and `SaveJournalEntry`'s repost-in-place path (§3f). Each call site was
reasoned through individually to confirm it doesn't defeat the trigger's intent (no
debit/credit/date mutation on a posted row outside a proper void+repost).

Verified: `tests/Feature/Accounting/PostedLedgerImmutabilityTriggerTest.php` (5/5
passing) exercises the trigger directly; the full test suite (§5) confirms no other
production code path was broken by the new restriction.

### 3c. Double-entry enforcement — app-layer only (same caveat as above)
No DB `CHECK` constraint requires `debits == credits`. Enforced at posting time:
`JournalPoster::post()` (`app/Services/Posting/JournalPoster.php:46-48`) throws
`UnbalancedJournalException` via `JournalEntry::isBalanced()`
(`app/Models/JournalEntry.php:113-117`). Draft entries are intentionally allowed to be
unbalanced. A write path that bypasses `JournalPoster`/`SaveJournalEntry` (raw insert,
a buggy importer) is not blocked — only caught later by
`CheckLedgerIntegrity::checkCompany()`.

### 3d. `integrity:check` / `audit:verify` — well-engineered detective control
`app/Console/Commands/CheckLedgerIntegrity.php` + `VerifyAccountingAuditCommand.php`:
1. Walks the hash chain in `sequence` order, verifying sequence continuity,
   `previous_hash` linkage, `row_hash` recomputation (`hash_equals()`), and a
   cross-check that queryable columns still match the canonical `hash_input` JSON.
2. Resumes from a per-company checkpoint (`audit_chain_checkpoints`) for performance, but
   a rotating 1/30 shard of companies gets a full genesis-to-tip walk nightly, so every
   chain is fully re-verified at least monthly. Checkpoints are only trusted if they
   still hash to a real row (guards against a forged/rolled-back watermark).
3. Double-entry balance check (`SUM(debit_cents - credit_cents) = 0`) per company.
4. Balance-cache drift check: recomputes each account's balance from posted
   `journal_lines` and compares to the denormalized `accounts.balance_cents` cache.

**Finding #2 — Fixed**: `CheckLedgerIntegrity::checkBalanceCache()` now appends an issue
(`"...recomputed to %d, and has been healed (--fix)."`) even on the `--fix` path,
instead of silently `continue`-ing past a healed drift. A drift found while `--fix` is
enabled now alerts and exits non-zero exactly like a drift found without `--fix` —
`--fix` only changes whether the cache is also corrected in the same run, never whether
the drift is surfaced. Verified by
`tests/Feature/Console/CheckLedgerIntegrityTest.php::'detects a drifted account-balance
cache and heals it with --fix, but still alerts'`.

**On failure generally**: this command is purely detective — it emails
`LEDGER_INTEGRITY_ALERT_EMAIL` and exits non-zero, but never blocks posting, locks a
company, or quarantines data.

### 3e. Finding #1 — inconsistent deletion guards on posted documents — Fixed
`app/Concerns/GuardsPostedDeletion.php` is a model-level `static::deleting()` guard
(fires for soft *and* force deletes) that throws `PostedDocumentDeletionException`.
Previously applied only to `Invoice`, `Bill`, `BillPayment`, `CustomerReceipt`,
`SalesReceipt`.

`GuardsPostedDeletion` now also applies to `CreditMemo`, `Cheque`, `Deposit`,
`TaxReturnPayment`, `Transfer`, `StockAdjustment`, `PayRun`, and `JournalEntry` (the
eight models originally missing it). The trait was generalized to accept an
`isPostedForDeletionGuard()` hook per model, since not every model uses the same
`journal_entry_id !== null` posted-check (`JournalEntry` itself checks `is_posted`
directly, for example). `Cheque`, `Deposit`, `Transfer`, `StockAdjustment`, and `PayRun`
were also added to `AuditableObserver::ACTIONS`
(`app/Observers/Audit/AuditableObserver.php`), so a deletion attempt on any of them now
generates an audit-log row regardless of outcome.

`artisan tinker`-style `Model::find($id)->forceDelete()` on any of these eight, once
posted/GL-linked, now throws `PostedDocumentDeletionException` instead of silently
orphaning the linked `JournalEntry`. Verified by
`tests/Feature/Accounting/PostedDocumentDeletionGuardExtendedTest.php`.

### 3f. Finding #6 — posted entries editable in place, no visible correction trail — re-reviewed 2026-09-05, unchanged
Both `JournalEntryController::updatePosted()` and the Livewire journal form's
`saveChanges()` allow a posted `JournalEntry` to be overwritten in place (UI shows an
explicit warning), rather than requiring a void + new entry. Same "repost-in-place"
pattern applies to `Invoice` via `InvoicePoster::repost()`. This is fully captured in
the audit chain (old `JournalLine` rows are hard-deleted and new ones created, each
firing `AuditableObserver`), so the old values are forensically recoverable — but a
normal GL report after the edit shows only the new numbers, with nothing indicating the
entry was ever different. Only a deliberate query against `accounting_audit_logs`
surfaces it. This is available to any user with ordinary bookkeeper write access, not
just admins.

This is a design tradeoff (matches how much off-the-shelf accounting software behaves),
not a bug — noted for awareness, not necessarily something to "fix." Re-reviewed
alongside Finding #5 (§3b): `SaveJournalEntry::handle()`'s repost-in-place path is one
of the reviewed exemptions wrapped in `PostedMutationGate`, since the new DB triggers
would otherwise block this same by-design behavior at the database layer. No change to
the underlying tradeoff itself.

### 3g. Period locking — sound, single global cutoff, reversible by design
`Company.lock_date` + `isLockedFor()` (`app/Models/Company.php:293-298`), enforced at
every posting/void/repost chokepoint. Changing the lock date requires
`Gate::authorize('update', $company)`, password re-entry, and is itself audit-logged
with `{from, to}`. Not a true one-way ratchet (an authorized user can roll it back), and
it's a single date rather than discrete closeable periods — both are reasonable,
common-in-the-industry tradeoffs rather than gaps.

### 3h. Multi-tenant isolation for ledger data — sound
`BelongsToCompany` trait + `CompanyScope` force `company_id` from the bound
`current_company` on create, overriding any client-supplied value. Documented,
deliberate opt-outs exist for console/checkpoint contexts. See §4 for the one place
this trust boundary has a real gap (Livewire AJAX).

### 3i. Audit-trail gap found during Finding #5 verification — Fixed
While auditing every code path that needed a `PostedMutationGate` exemption (§3b),
`resources/views/pages/reports/⚡unattributed-ar.blade.php`'s `assign()` action was
found to perform a raw `DB::table('journal_lines')->update(...)` (attributing an
unattributed AR line to a customer) with **no audit-log entry at all** — a genuine gap
against the standing requirement that every user-initiated data change be captured in
the audit trail (`app/Observers/Audit/AuditableObserver.php` only instruments Eloquent
model mutations, not raw query-builder updates). Fixed by adding
`AuditAction::JournalLinesArAttributed` (`app/Enums/AuditAction.php`) and calling
`AccountingAuditRecorder::record()` after a successful attribution, alongside the
`PostedMutationGate` wrap the trigger now requires. This was outside the original 9
findings — found and fixed as a byproduct of this pass, not a pre-existing tracked item.

## 4. Multi-tenancy & authorization

### 4a. Tenant isolation / IDOR — solid
`CompanyScope` + `BelongsToCompany` cover the general case. The dangerous ordering bug
(Laravel resolving route-bound models *before* `current_company` is bound, which would
make the scope a no-op) is explicitly closed in `bootstrap/app.php:83-92` via
`prependToPriorityList(SubstituteBindings::class, EnsureCompanyMembership::class)`. All
17 `*Print*Controller.php` files plus the attachment-download route additionally do
explicit `abort_unless($model->company_id === $company->id, 404)` checks. No missing
check found among the controllers/pages spot-checked (invoices, journal entries,
bills, attachments, backups, member management, support tickets).

### 4b. Finding #7 — Livewire AJAX doesn't re-check access after mount — Fixed
`app/Livewire/Hooks/BindCurrentCompanyHook.php`'s `hydrate()`/`call()`/`update()`/
`render()` hooks now re-check `$user->belongsToCompany($component->company)` on every
Livewire AJAX request, not just the initial full-page GET (`mount()` still only binds,
deliberately not enforcing — see the class docblock for why: it would preempt the
admin-portal's own site-admin guard, which relies on Livewire hydrating `$company`
before the component's `mount()` body runs). A revoked/downgraded user's already-open
tab now gets a 403 (`"You no longer have access to this company."`) on its very next
interaction, rather than continuing to read/write that company's data until the tab
refreshes or the session is killed server-side.

Scoped to the `web` guard only (portal Livewire components authenticate under the
separate `customer` guard and must not be affected) and exempts site admins (the
`/admin/*` portal's authorization model is "is site admin," not "belongs to this
company," and enforces that itself). This closes the gap independent of session driver
— previously mitigated only because this deployment happens to run
`SESSION_DRIVER=database`; that mitigation is no longer load-bearing, though it remains
good practice regardless. Verified by
`tests/Feature/Security/LivewireCompanyRevocationTest.php`.

### 4c. Authorization policies — coarse but consistent
Only 3 Policy classes exist (`CompanyPolicy`, `DocumentFolderPolicy`,
`ReportGroupPolicy`); financial documents use section/role-based middleware instead of
per-model policies. No missing check found. Consequence: no separation of duties within
a granted section — e.g. anyone with "Banking" access can create, post, *and* void a
payment in one step. Acceptable for a small-business tool; worth knowing if that's not
the intended posture.

### 4d. Mass assignment, OAuth scopes — clean
No unsafe mass-assignable fields found (`User` fillable is tightly `name/email/password/
calculator_mode`). `company_id` appears in a few models' fillable lists but is always
server-overridden by `BelongsToCompany::creating()`. Passport/MCP OAuth tokens delegate
authorization per-tool to the user's actual in-app section grants — no over-broad scope
grant found.

### 4e. Finding #4 — MCP write-proposal "confirm" is not a real human-in-the-loop gate — re-reviewed 2026-09-05, unchanged
`app/Mcp/Concerns/ProposesWrites.php` + `ConfirmProposalTool.php`: writes are staged as
`McpWriteProposal` rows, gated by `MCP_WRITE_ENABLED` (default `false`), a per-company
opt-in, control-account rejection, tenant scoping, and re-validated abilities at confirm
time. However, there is **no human web-UI approval screen** — the only way to confirm a
proposal is calling `ConfirmProposalTool` over the same MCP session/credential that
created it. A fully autonomous or compromised MCP agent holding that credential can
propose then immediately confirm, back-to-back. The "propose → confirm" flow guards
against single-shot LLM mistakes (preview, second call, guardrails, idempotency) — it is
not an enforced human-approval workflow, despite the naming suggesting one.

This install currently has `MCP_WRITE_ENABLED` unset (defaults to `false`), so the
feature is off. Re-reviewed this pass and confirmed nothing has changed since the
original finding — no code was touched here, since building a real human-approval
web-UI is a feature addition, not a bug fix, and the feature being off by default means
there's no live exposure to close urgently. Worth deciding deliberately before turning
`MCP_WRITE_ENABLED` on for any company.

### 4f. Finding #3 — 2FA enforcement doesn't cover API keys / MCP tokens — Fixed
The shared enforcement logic was extracted to
`app/Support/Security/TwoFactorRequirement::isUnmet(Company $company, ?User $user)` —
`$user !== null && $company->require_two_factor && ! $user->hasEnabledTwoFactorAuthentication()
&& ($user->companyRole($company)?->isAtLeast(CompanyRole::Admin) ?? false)` — and is now
used by both `EnforceTwoFactor` (web) and the new
`app/Http/Middleware/EnforceTwoFactorForApi.php` (API/MCP). The new middleware is
registered as `enforce.2fa_api` (`bootstrap/app.php`) and applied to all four MCP route
groups in `routes/ai.php` (both the Q&A and agentic-write servers, both the API-key and
OAuth `{company}` connection methods) and the versioned REST API in `routes/api.php`
(`Route::middleware(['throttle:api', 'auth.api_key', 'enforce.2fa_api'])`). A company
that mandates 2FA for admins now gets that protection uniformly across the web UI, API
keys, and MCP tokens — closing the gap flagged as concrete in §4e (the MCP path can
carry the agentic write tools once `MCP_WRITE_ENABLED` is turned on). Verified by
`tests/Feature/Security/TwoFactorApiEnforcementTest.php`.

## 5. Verification of the 2026-09-05 fixes

Each fix above was covered by a dedicated new or extended test (cited in its own
section) and run in isolation first. The full suite was then run twice against a
scratch database (`lineledger_test_isolated`, via `phpunit.isolated.xml`) to catch any
regression the fixes introduced elsewhere in the codebase:

- **First full run** surfaced 32 regressions (30 errors + 2 failures), all traced to two
  root causes rather than flaws in the findings' own fixes:
  1. **13 tests** — the new Finding #5 DB triggers correctly blocking several
     legitimate production code paths that mutate posted `journal_lines`/
     `journal_entries` outside the normal poster flow (bank-register reconciliation
     metadata, account/contact merges, AR attribution, `SaveJournalEntry`'s
     repost-in-place — all now wrapped in `PostedMutationGate`, reasoned through
     individually in §3b/§3f), plus test fixtures that deliberately fake posted-row
     tampering as setup for their own assertions (`CheckLedgerIntegrityTest`,
     `JournalEntryLifecycleTest`, `JournalEntrySourceLockTest` — also wrapped in
     `PostedMutationGate`, since the tampering being blocked is exactly what those
     tests exist to fake).
  2. **19 tests** — pre-existing test-fixture gaps unmasked by the Finding #7 fix now
     correctly enforcing membership on every Livewire AJAX request, not a defect in
     the fix itself: 17 tests across `CashFlowStatementTest`, `ColumnTogglesTest`,
     `ManagementReportPackageTest`, `ReportEmailTest`, `ReportNotesTest`, and
     `ReportNumberFormatTest` whose fixtures created a Livewire-testing user without
     attaching real company membership, plus 2 tests in `InvoiceSecondaryTaxTest`
     that omitted the `company` prop from `Livewire::test()` entirely (every other
     caller of that same page component supplies it).
- **Second full run** (post-fix, 2026-09-05 22:18 UTC): **3259 tests, 3257 passed, 13214
  assertions, 2 skipped, 0 failures** — zero regressions. All 32 failures from the first
  run are confirmed fixed, and no new failures were introduced. Final runtime: 55 min
  20 sec against an isolated, clean database.

No test assertions were weakened or removed to make the suite pass — every fix was
either a `PostedMutationGate::within()` wrap around a reviewed, legitimate exemption, or
a test-fixture correction (real company membership attached, or the `company` prop
supplied) that brings the fixture in line with how every other test in the suite
already exercises these components.

---

## Not independently verified
- Livewire AJAX finding (§4b) is based on static code/comment evidence, not a live
  proof-of-concept.
- Did not exhaustively audit all Volt/Livewire pages individually — spot-checked the
  highest-risk financial and admin surfaces.
- Did not trace every request path that binds `current_company` to rule out a
  wrong-tenant binding bug outside the areas reviewed.
