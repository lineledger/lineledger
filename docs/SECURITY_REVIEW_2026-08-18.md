# LineLedger Security & Accounting-Integrity Review — 2026-08-18

Scope: fresh clone of `main` (`0725eb5`), reviewed for known-CVE exposure, common web
vulnerability patterns, accounting-integrity/audit-trail soundness, and multi-tenant
authorization. This is a point-in-time review of a self-hosted install at
`/srv/lineledger`; it has not been re-verified dynamically (no live exploitation
attempted) except where noted.

Local-only document — this branch (`local/security-review`) is not pushed to GitHub.

## Risk-ranked summary

| # | Finding | Area | Severity | Status |
|---|---|---|---|---|
| 1 | Posted-record deletion guard inconsistent across models | Accounting integrity | Medium | Open |
| 2 | `integrity:check --fix` silently heals balance-cache drift | Accounting integrity | Medium | Open |
| 3 | 2FA enforcement doesn't cover API keys / MCP OAuth tokens | Authorization | Medium | Open |
| 4 | MCP write-proposal "confirm" is not a real human-in-the-loop gate | Authorization | Low–Medium | Open (feature is off by default) |
| 5 | Live ledger tables have no DB-level immutability (app-layer only) | Accounting integrity | Low (defense-in-depth) | Open |
| 6 | Posted entries editable in place with no visible correction trail in reports | Accounting integrity | Low (by-design tradeoff) | Noted |
| 7 | Livewire AJAX doesn't re-check access after initial page load | Authorization | Low (mitigated by `SESSION_DRIVER=database`) | Mitigated in this deployment |
| 8 | Portal magic-link *request* endpoint rate limiting unconfirmed | Web vuln | Low | Unconfirmed |
| 9 | `mysql:8.4` / `frankenphp` base images carry upstream CVEs | Dependency/image | Low–Medium | Track upstream |

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

**Open item (#8)**: could not confirm whether
`app/Actions/Portal/RequestPortalLoginLink.php` (the endpoint that *requests* a
passwordless magic link, as opposed to consuming one) is rate-limited. The
token-consume endpoint is adequately protected by 48-char token entropy regardless.
Worst case if unconfirmed: an attacker mass-triggers login-link emails to arbitrary
addresses (spam/relay-abuse), not a data-exposure risk.

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

### 3b. Finding #5 — live ledger tables lack DB-level immutability
The hash chain and triggers protect the **audit log table**, not `journal_entries` /
`journal_lines` themselves. `grep -rl "CREATE TRIGGER" database/migrations` returns
only `accounting_audit_logs` and `security_logs`. Enforcement of "don't edit a posted
line" is 100% application-layer (Eloquent guards, controller checks, `JournalPoster`).

**Scenario**: the app's DB user has full DML rights (required to create triggers during
migration — see `docker/docker-compose.yml:72-75` comment on `--skip-log-bin`). Direct
DB access (compromised app/queue container, an operator with DB credentials, or a
future SQL-injection primitive) can `UPDATE journal_lines SET debit_cents = ...`
directly. This bypasses the Eloquent observer (no audit row written) and the hash chain
has nothing to say about it (it only captured a snapshot at posting time, not a live
hash of the current row). Detection is left to the nightly `integrity:check` balance
heuristics, which a balance-preserving tamper (swap which line carries the debit) would
evade.

**Recommendation**: add DB-level triggers on `journal_lines`/`journal_entries` (and any
other posted-financial-data table) that reject `UPDATE`/`DELETE` once a row's parent
entry is posted, mirroring the pattern already used for `accounting_audit_logs`. This
is defense-in-depth against anything below the Eloquent layer.

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

**Finding #2**: `--fix` (lines 159-207) silently rewrites the balance cache via
`saveQuietly()` when drift is found, **without raising an issue for the drift it just
healed**. If `--fix` is part of the routine cron invocation, a real tampering signal
(deliberate or accidental corruption of `balance_cents`) gets silently absorbed into a
routine run with no alert.

**Recommendation**: always log/alert on a drift found, even when `--fix` is enabled
and successfully heals it. Confirm the scheduler does not default to `--fix`
unconditionally; if it does, split "detect and alert" from "heal" into separate
scheduled invocations.

**On failure generally**: this command is purely detective — it emails
`LEDGER_INTEGRITY_ALERT_EMAIL` and exits non-zero, but never blocks posting, locks a
company, or quarantines data.

### 3e. Finding #1 — inconsistent deletion guards on posted documents
`app/Concerns/GuardsPostedDeletion.php` is a model-level `static::deleting()` guard
(fires for soft *and* force deletes) that throws `PostedDocumentDeletionException` if
`journal_entry_id !== null`. Applied to: `Invoice`, `Bill`, `BillPayment`,
`CustomerReceipt`, `SalesReceipt`.

**Not applied to**: `CreditMemo`, `Cheque`, `Deposit`, `TaxReturnPayment`, `Transfer`,
`StockAdjustment`, `PayRun`, `JournalEntry` — all of which also carry a
`journal_entry_id`. For these, the equivalent check exists only inside each API
controller's `destroy()` method. Additionally, `Cheque`/`Deposit`/`Transfer`/
`StockAdjustment`/`PayRun` are **absent from `AuditableObserver::ACTIONS`**
(`app/Observers/Audit/AuditableObserver.php:34-105`), so a deletion of one of these
wouldn't even generate an audit-log row.

**Scenario**: a future maintenance script or an operator in `artisan tinker` running
`Cheque::find($id)->forceDelete()` on a posted, GL-linked cheque succeeds silently — no
exception, orphans the linked `JournalEntry`, and leaves no audit trail.

**Recommendation**: apply `GuardsPostedDeletion` to all eight missing models, and add
`Cheque`/`Deposit`/`Transfer`/`StockAdjustment`/`PayRun` to `AuditableObserver::ACTIONS`.

### 3f. Finding #6 — posted entries editable in place, no visible correction trail
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
not a bug — noted for awareness, not necessarily something to "fix."

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

### 4b. Finding #7 — Livewire AJAX doesn't re-check access after mount (mitigated here)
`app/Livewire/Hooks/BindCurrentCompanyHook.php` documents, in its own comment, that
Livewire's `/livewire-*/update` AJAX endpoint doesn't route through
`EnsureCompanyMembership` — it re-binds `current_company` from the component snapshot
without re-checking `belongsToCompany()`. `EnsureSectionAccess`, `EnsureSectionEnabled`,
and `EnforceTwoFactor` only run on the initial full-page GET.

**Scenario**: an owner revokes/downgrades a user's access while that user has a
form open; the open tab can keep submitting AJAX writes until it's refreshed —
**unless the session is killed server-side**, which `app/Services/Security/AccessRevoker.php:81-99`
does via `forgetSessions()`, but **only when `SESSION_DRIVER=database`**.

**This deployment's `.env` already sets `SESSION_DRIVER=database`** (the shipped
default), so this gap is currently mitigated. Flagging so it stays that way — don't
switch to `redis`/`file`/`cookie` session drivers without also hardening this path.

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

### 4e. Finding #4 — MCP write-proposal "confirm" is not a real human-in-the-loop gate
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
feature is off. Worth deciding deliberately before turning it on.

### 4f. Finding #3 — 2FA enforcement doesn't cover API keys / MCP tokens
`app/Http/Middleware/EnforceTwoFactor.php` is registered only on the `{company}`-prefixed
web route group (`routes/web.php:96`) — not on `routes/api.php` or the MCP OAuth routes
(`routes/ai.php:34-49`). A company that mandates 2FA for admins gets that protection
only for the interactive web UI; an admin's `CompanyApiKey` or MCP OAuth token works
with no 2FA check at all. Concrete because the MCP path can include the agentic write
tools once enabled (§4e).

**Recommendation**: extend `EnforceTwoFactor`'s check (or an equivalent) to the API-key
and MCP-token auth paths for companies that have opted into `require_two_factor`, or at
minimum document clearly that 2FA is a web-UI-only control today.

---

## Not independently verified
- Livewire AJAX finding (§4b) is based on static code/comment evidence, not a live
  proof-of-concept.
- Did not exhaustively audit all Volt/Livewire pages individually — spot-checked the
  highest-risk financial and admin surfaces.
- Did not trace every request path that binds `current_company` to rule out a
  wrong-tenant binding bug outside the areas reviewed.
