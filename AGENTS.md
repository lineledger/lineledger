# AGENTS.md

Coding-agent guide for LineLedger. Human contribution process (CLA, PR steps) lives in [CONTRIBUTING.md](CONTRIBUTING.md). Product, env, deploy, Docker, and ops live in [README.md](README.md). Read those when you need them; this file is the in-tree working contract.

LineLedger is **source-available double-entry accounting** (Laravel 13) for Canadian small businesses and non-profits. The UI says **organization**; the model, `{company}` route param, and container binding are still named `company`. The general ledger is the source of truth — posting documents write balanced journal entries, and reports read the GL.

- **Stack:** PHP 8.5+, Laravel 13, Livewire 4 single-file components, Flux UI Free, Tailwind CSS v4, Pest 4, Larastan level 5.
- **License:** AGPL-3.0-or-later. Opening a PR accepts [CLA.md](CLA.md).
- **Security:** do not open a public issue. Follow [SECURITY.md](SECURITY.md) or email hello@lineledger.ca.
- **Status:** 1.1.0. This repository is not soliciting drive-by contributions; this file is for agents working in-tree.

---

## Commands

Create both MySQL databases before migrating (`lineledger` and `lineledger_test`). `.env.example` defaults to MySQL.

| Command | When |
| --- | --- |
| `composer install && npm install` | PHP + JS deps |
| `cp .env.example .env && php artisan key:generate && php artisan migrate` | First run |
| `php artisan migrate --seed` | Dev/QA only. Seeds `test@example.com` / `password` as a **site admin**. `DatabaseSeeder` **throws in production** |
| `php artisan db:seed --class=DemoCompanySeeder` | Demo books: `demo` (for-profit) and `demo-society` (charity). Also refuses production |
| `php artisan storage:link` | Logos / attachments |
| `composer run dev` | `serve` + **`queue:listen`** + pail + Vite. Queue is required: backups, restores, recurring docs, and mail sit in `jobs` without a worker |
| `vendor/bin/pint --dirty` | Format changed PHP. **Never pass a `.blade.php` path** — the Laravel preset excludes Volt `⚡*.blade.php`, but an explicit path bypasses that and mangles the file |
| `./vendor/bin/pest` | Full suite against **MySQL** `lineledger_test` (`phpunit.xml`) |
| `php artisan test --compact --filter=Invoice` | One filter |
| `DB_CONNECTION=sqlite DB_DATABASE=':memory:' ./vendor/bin/pest` | **CI-exact** database |
| `composer run test` | `config:clear` → Pint `--test` → `npm test` → Pest. **Not** full CI: no PHPStan, MySQL not SQLite, no `npm run build` |
| `vendor/bin/phpstan analyse --memory-limit=1G` | Larastan level 5 on `app/`, baselined in `phpstan-baseline.neon` (fails on **new** findings only) |
| `npm test` | `tests/js/**/*.test.js` (amount-expression parser, date-picker, edit-lock, escape-back) |
| `php artisan app:upgrade` | Post-deploy: migrate, then release data steps (`banking:backfill-line-memos`, `banking:backfill-reconciliation-stamps`). `--verify` finishes with `integrity:check`. Docker/Forge call this instead of `migrate --force`. See [`UPGRADING.md`](UPGRADING.md) |
| `php artisan proof:generate` | Public `/verification` artifacts |
| `php artisan payroll:verify-constants` / `payroll:verify-calculations` | After editing payroll constant tables |

PDF bank-statement import needs [poppler](https://poppler.freedesktop.org/) (`brew install poppler` / `apt-get install poppler-utils`). CSV / Excel / OFX do not.

### Docker-only loop

Root `docker-compose.yml` is the **dev** stack (bind-mounted source). Self-host GHCR compose is `docker/docker-compose.yml` — do not mix them.

```bash
docker compose up --build
```

App http://localhost:8000, Vite :5173, Mailpit :8025. Prefix artisan / Pest / Pint / PHPStan with `docker compose exec app`. CI-parity:

```bash
docker compose exec -e DB_CONNECTION=sqlite -e DB_DATABASE=':memory:' app ./vendor/bin/pest
```

Compose sets `DB_HOST=mysql` and Mailpit SMTP; leave `.env.example` host-native (`127.0.0.1`) for people who still run PHP on the host.

---

## Non-negotiables

1. Bind `app()->instance('current_company', $company)` before querying or creating tenant models. **Unbound = no `CompanyScope`** (intentional for console, seeders, and some tests).
2. Do **not** scope queries with `User.current_company_id`. That column is an MRU hint for redirects, not isolation.
3. Volt pages that touch tenant data **must** declare `public Company $company`. Livewire AJAX (`/livewire-…/update`) does **not** run `EnsureCompanyMembership`. `BindCurrentCompanyHook` rebinds from that property.
4. Persist money as **integer cents**. No floats in money math. UI and MCP propose-tools speak dollars; convert with `Money::fromString()` / `tryFromString()` at the edge. Actions, API, and the GL take cents.
5. Persist calendar dates as `Y-m-d` (`CarbonImmutable` + `->toDateString()` + `'date:Y-m-d'` casts). Local Pest is MySQL; CI is SQLite `:memory:`. A datetime in a date column passes locally and fails CI.
6. `app/Actions/` save **drafts only**. Posting, period lock, tax-period lock, bank-rec lock, inventory, and audit live in `app/Services/Posting/`. The UI, API, MCP, recurring generator, and jobs call the **same** Action + Poster.
7. Posted documents are not deleted. Void (reversing journal entry) or, where implemented, repost. `GuardsPostedDeletion` enforces this on Invoice, Bill, CustomerReceipt, BillPayment, and SalesReceipt. Do not edit source-linked journal entries from the journal (`LinkedJournalEntryException`) except `JournalEntry::JOURNAL_EDITABLE_SOURCES`.
8. Journals must balance in **home** cents (`JournalEntry::isBalanced()` requires debits === credits **and** debits > 0). FX ±1¢ rounding is plugged onto a credit leg (`PlugsForeignRounding`).
9. New tenant UI is a Livewire 4 SFC: `resources/views/pages/{feature}/⚡{index,form,show}.blade.php` + `Route::livewire(..., 'pages::...')`. Do not add class-based pages under `app/Livewire/` (that tree is Hooks / Concerns / `Logout` only). Do not use `Volt::route`.
10. New `{company}` routes: the first segment of the route **name** must map in `Section::forRouteName()` or the route is **ungated**.
11. `EnsureCompanyMembership` and `AuthenticateApiKey` must stay **before** `SubstituteBindings` in `bootstrap/app.php`. Otherwise `{invoice}` / `{bill}` resolve with `CompanyScope` off (cross-tenant IDOR).
12. Every `withoutGlobalScopes()` must re-filter `where('company_id', $company->id)`.
13. Never pass a `.blade.php` path to Pint.
14. JSON API: only `ClientSafeException`, validation, and HTTP exception messages reach the client. Internal `RuntimeException` from posters → generic 422 + `report()`. Renderers live in `bootstrap/app.php`.
15. Payroll constant files: **append** a new effective-date table. Never edit prior years — old pay runs must recompute identically. A missing year **blocks** payroll rather than withholding wrong amounts.
16. Sidebar links go through `App\Support\Navigation\SidebarNavCatalog`, not only Blade.
17. UI copy uses `__('English source')` keys (JSON catalogs in `lang/{locale}.json`). Staff UI language is `users.locale`; invoice PDFs, invoice emails, and the customer portal use `contacts.locale` then `companies.locale` (`App\Support\Locales`). Do not translate user-entered line descriptions. Wrap document renders in `Locales::using()` and restore (queued jobs have no HTTP middleware). Storage dates stay `Y-m-d`. Preference keys are persisted and must stay stable.

---

## Layout

Standard Laravel tree. Accounting-specific code:

| Path | Role |
| --- | --- |
| `resources/views/pages/` | ~280 Livewire 4 SFCs (`⚡name.blade.php`). Name: `pages/invoices/⚡form.blade.php` → `pages::invoices.form` |
| `resources/views/components/` | Blade + 8 Livewire islands (`⚡company-switcher`, `⚡sidebar-nav`, `⚡global-search`, …) |
| `app/Actions/` | Shared writes (`SaveInvoice`, `SaveBill`, `CreateCompany`, …) |
| `app/Services/` | Domain engines: `Posting/`, `Reporting/`, `Payroll/`, `Banking/`, `Inventory/`, `Migration/`, `Backup/`, `Restore/`, `Inbox/`, `Insights/`, `Audit/`, `Currency/`, `Tax/`, `Recurring/`, `Stripe/`, … |
| `app/Services/Posting/JournalPoster.php` | Single chokepoint that commits journal entries |
| `app/Mcp/` | `BusinessQaServer` (read) + `BusinessActionsServer` (propose → confirm writes) |
| `app/Models/` | GL core is `JournalEntry` / `JournalLine`. `is_posted` and `entry_date` are denormalized onto lines in `JournalEntry::booted` |
| `app/Enums/` | `AccountType`, `InvoiceStatus`, `CompanyRole`, `Section`, `ApiAbility`, `Country`, … |
| `app/Http/Middleware/` | Tenancy, sections, API keys, portals, 2FA, Turnstile |
| `routes/web.php` | Tenant UI under `{company}`; also onboarding, portals, verification |
| `routes/api.php` | `/api/v1` |
| `routes/ai.php` | MCP HTTP routes |
| `routes/admin.php` | Site-admin portal — **not** tenant-scoped |
| `routes/settings.php` | Profile, security, companies, report groups |
| `routes/docs.php` | In-app manuals |
| `routes/console.php` | Scheduler |
| `tests/Feature/` | ~561 Pest feature tests |
| `tests/Unit/` | ~36 unit tests (no `RefreshDatabase`) |
| `tests/js/` | Node tests |

`config/livewire.php` maps namespace `pages` → `resources/views/pages` and enables the ⚡ filename emoji.

---

## Tenancy

Shared database, row-level isolation keyed off a container singleton — **not** `User.current_company_id`.

### How `current_company` gets bound

1. **Web.** `{company}` slug → `EnsureCompanyMembership` (403 if the user is not a member) → `app()->instance('current_company', $company)` + `URL::defaults(['company' => $slug])`. Optional `minimumRole` uses `CompanyRole::isAtLeast`.
2. **Livewire AJAX.** `BindCurrentCompanyHook` (`app/Livewire/Hooks/BindCurrentCompanyHook.php`) reads `public Company $company` on mount / hydrate / call / update / render. Registered in `AppServiceProvider::register` **before** Livewire boots — putting it in `boot()` is too late.
3. **API.** `AuthenticateApiKey` hashes the bearer token or `X-Api-Key`, loads `CompanyApiKey`, binds `current_company` + `current_api_key`. There is **no** `{company}` in API URLs; the key implies the tenant.
4. **MCP OAuth.** `BindMcpCompany` after `auth:api` (Passport). The API-key MCP path reuses `auth.api_key`.
5. **Customer / employee portals.** `ResolvePortalCompany` binds the company **without** staff membership so `CompanyScope` still applies to `Contact` queries.
6. **Jobs / commands.** Bind around the work and restore or `forgetInstance` afterwards. `CompanyObserver::created` rebinds while seeding the chart of accounts so a second organization is not stamped onto the previously bound tenant.

### Isolation layers

- **Membership** — `EnsureCompanyMembership`.
- **Read** — `CompanyScope` on every `BelongsToCompany` model: `where company_id = current` **only if** bound.
- **Write** — `BelongsToCompany` `creating` hook **overwrites** `company_id` from the bound company (blocks mass-assignment injection). Console / seeders / tests that do not bind keep an explicit `company_id`.
- **Validation** — Volt and API FormRequests use `Rule::exists(...)->where('company_id', $company->id)`.
- **Lookups in Actions** — tax codes and similar use `withoutGlobalScopes()->where('company_id', $company->id)` so a foreign id is ignored, not leaked. See `tests/Feature/Security/TenantIsolationTest.php`.

`Company::getRouteKeyName()` is `slug`.

Copy: `app/Concerns/BelongsToCompany.php`, `app/Scopes/CompanyScope.php`, `tests/Feature/Companies/CompanyScopeTest.php`.

---

## Write path

| Layer | Speaks | Does |
| --- | --- | --- |
| Volt SFC | Dollar strings, `MoneyString`, `<x-amount-input>` | Validate, convert to cents, call Action, then Poster |
| API FormRequest | Integer cents | Same Action + Poster. `"post": false` keeps a draft (`ApiController::wantsDraft`). **Default is post-on-create** |
| MCP propose tools | Dollars in | Stage a `McpWriteProposal` token. **No ledger write** |
| MCP `ConfirmProposalTool` | Stored cents payload | Same Action + Poster, one transaction, idempotent confirm |
| **Action** (`app/Actions/…`) | Cents, framework-agnostic array | `handle(array $data, ?Model $existing)` inside `DB::transaction`. Persist draft. **Do not post** |
| **Document poster** (`InvoicePoster`, `BillPoster`, …) | Eloquent document | Build JE lines, lock FX rate, inventory, tax-period check, then `JournalPoster::post` |
| **`JournalPoster`** | `JournalEntry` | Already-posted / unbalanced / period-locked / completed bank rec → exceptions; then `is_posted`, denorm onto lines, recompute account balances, audit |

`SaveInvoice` (`app/Actions/Sales/SaveInvoice.php`) is the template: dates via `CarbonImmutable::parse(...)->toDateString()`, status `Draft` on create, currency frozen at create, lines deleted + rebuilt, tax via `TaxCalculator::line()`, `recalculateTotals()`. Shared by the invoice Volt form, `InvoiceController`, `ConfirmProposalTool`, and the recurring generator.

`JournalPoster::post` is wrapped in `AuditMute::silence` so `AuditableObserver` does not double-log. Posting records its own richer events through `AccountingAuditRecorder`.

`InvoicePoster::post` (template for document posters): refuse if `journal_entry_id` set (`AlreadyPostedException`); `Company::isLockedFor(invoice_date)` (`lock_date` inclusive); `TaxPeriodLockGuard`; empty/zero total → internal `RuntimeException`; inventory preflight; AR debit + income/tax credits (do **not** point invoice lines at AR — posters resolve control accounts); FX rate locked at post; then `journalPoster->post`. `repost` mutates existing JE lines in place (still respects lock/tax on old **and** new dates). `void` writes a reversing entry.

Copy: `app/Actions/Sales/SaveInvoice.php`, `app/Services/Posting/InvoicePoster.php`, `app/Services/Posting/JournalPoster.php`, `resources/views/pages/invoices/⚡form.blade.php` (`persist` / `postInvoice`), `app/Http/Controllers/Api/V1/InvoiceController.php`.

### Posting exceptions (`app/Exceptions/Posting/`)

All implement `App\Contracts\ClientSafeException`. API mapping in `bootstrap/app.php`:

| Exception | Typical cause | API |
| --- | --- | --- |
| `AlreadyPostedException` | Double post | **409** |
| `UnbalancedJournalException` | Debits ≠ credits or zero | 422 |
| `PeriodLockedException` | Date ≤ `lock_date` | 422 |
| `PostedDocumentDeletionException` | Delete posted document | 422 |
| `LinkedJournalEntryException` | Edit a source-linked JE in the journal | 422 |
| `ReconciliationLockedException` | Date in a completed bank rec | 422 |
| `ReconciliationOutOfBalanceException` | Rec difference ≠ 0 | 422 |
| `TaxPeriodFiledException` | Filed tax return covers the date | 422 |
| `PostingValidationException` | Caller-fixable authored message | 422 |

Internal `RuntimeException` (missing control account, zero-total invoice, …) is **not** client-safe.

---

## Money and dates

- `App\Support\Money` — immutable cents, two-decimal currencies only. `fromString` throws; `tryFromString` returns null (use for `wire:model.live` half-typed values like `"6."`).
- `App\Casts\MoneyCast` exists but is **unused** on models. Core documents store integer `*_cents` columns. Match the document you are editing; do not introduce the cast on Invoice/Bill/JE unless you are converting the whole type.
- `Date::use(CarbonImmutable::class)` in `AppServiceProvider`.
- Organization “today” is `Company::currentDateTime()` (org timezone), not UTC `now()`. Chain `->toDateString()`.
- `phpunit.xml` pins `APP_URL=http://localhost` (HSTS tests), Turnstile off, bank-import AI off, array mail/session/cache, sync queue.

---

## Auth and RBAC

Staff auth is Laravel Fortify (passkeys + 2FA). Register creates a **user only** — no organization. `EnsureUserHasCompany` sends them to the setup wizard. The first registered user becomes `site_admin` (`CreateNewUser`).

**Roles** (`app/Enums/CompanyRole.php`): Owner / Admin / Accountant / Custom.

- Owner — all `CompanyPermission` + all `Section`.
- Admin — update company + invitations; all sections.
- Accountant — no company-management perms; all sections except Settings.
- Custom — sections from `Membership.sections` JSON.

`CompanyPermission` is **company / member / invitation only**, not document CRUD.

**Document access** = `EnsureSectionAccess` + `Section::forRouteName()` + scoped queries. There are almost no per-model policies (`CompanyPolicy`, `DocumentFolderPolicy`, `ReportGroupPolicy` only). Do not add a Policy per document.

`Section::forRouteName()` maps the first segment of the route name (`invoices.*` → Customers). Ungated on purpose: dashboard, insights, and anything that falls through to `default => []`. **A new `{company}` route whose name is not in that match is publicly reachable to every member.**

`EnsureSectionEnabled` is the site-admin kill switch (404) with a per-company override via `Company::sectionEnabled()`.

Company-wide 2FA (`require_two_factor`) is enforced by `EnforceTwoFactor` for Admin+.

`/admin` is platform-level (`EnsureSiteAdmin` + password confirm), not `{company}`.

API keys: empty abilities = **full access**. Domain grant (`sales:write`) covers resource grants (`invoices:write`). The API has no Section concept. Removing a member or downgrading their role revokes sessions, company API keys, and OAuth tokens (`AccessRevoker`).

---

## UI (Livewire 4 + Flux + Tailwind v4)

New screen:

1. `resources/views/pages/{feature}/⚡index.blade.php` (list), `⚡form.blade.php` (create **and** edit), `⚡show.blade.php` (detail).
2. `Route::livewire` in `routes/web.php` (tenant group), or `settings.php` / `admin.php` / `docs.php`.
3. Sidebar entry in `SidebarNavCatalog` if it belongs in nav.
4. `Section::forRouteName()` arm if it is a new route-name prefix.

Tenant group middleware (`routes/web.php`): `auth`, `verified`, `EnsureCompanyMembership`, `EnsureSectionEnabled`, `EnsureSectionAccess`, `EnforceTwoFactor`. Always pass the slug: `route('invoices.create', ['company' => $company->slug])`.

Print / PDF / downloads are **controllers** (`PrintInvoiceController`, `PrintReportController`, …) plus `resources/views/pdf/`, not Livewire.

### SFC skeleton

```php
<?php

use App\Models\Company;
use App\Models\Invoice;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Invoices')] class extends Component
{
    use WithPagination;

    public Company $company;

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::query()->paginate(25);
    }
}; ?>

<section class="w-full">
    {{-- Flux markup --}}
</section>
```

- Anonymous class, `}; ?>`, then Blade.
- Toasts: `Flux::toast(variant: 'success', text: __('…'))`.
- Lists: `flux:heading` + primary `flux:button icon="plus"` with `wire:navigate`; mobile cards (`lg:hidden`) + desktop table (`hidden lg:block`); `data-test` on interactive controls; `$this->invoices->links()`.
- Money cells: `<x-amount-input>`. **Do not** put Blade directives or huge attribute stacks on `<flux:input>` — Blade’s component-tag compiler can catastrophic-backtrack. Merge `wire:model*` via `$attributes` (see `resources/views/components/amount-input.blade.php`).
- Document create/edit forms that mutate a posted-or-draft record take an edit lock: `HoldsEditLock` / `GuardsEditLockedForm` / `ShowsEditLock` in `app/Livewire/Concerns/`, plus `<x-edit-lock.*>`. Do not add a second locking scheme.
- Reports: `HasReportDateRange`, `HasReportChart`, `HasReportComparison`, `HasReportBasis`, `HasReportNotes`, `HasReportNumberFormat`, `HasCustomReportHeader`, `HasReportDimensions`, `Memorizable`, `EmailsReport` + `<x-reports.control-bar>` + `<x-reports.chart-panel>`.
- Settings chrome: `<x-pages::settings.layout :heading="__('…')" :subheading="__('…')">`.
- Portals use `#[Layout('layouts.portal')]` / `layouts.employee-portal`; the wizard uses `layouts.onboarding`.

Budgets (`pages/budgets/*.blade.php`) are SFCs **without** the ⚡ prefix — do not copy that. Auth pages under `pages/auth/` are Fortify views, not Volt.

### Copy these

| Kind | Path |
| --- | --- |
| List | `resources/views/pages/invoices/⚡index.blade.php` |
| Form | `resources/views/pages/invoices/⚡form.blade.php` |
| Report | `resources/views/pages/reports/⚡income-statement.blade.php` |
| Combos | `resources/views/components/{contact,line-item,line-contact,payee,journal-contact}-combo.blade.php` |
| Amount | `resources/views/components/amount-input.blade.php` |
| Nav catalog | `app/Support/Navigation/SidebarNavCatalog.php` |
| Company switcher | `resources/views/components/⚡company-switcher.blade.php` |

### CSS / JS

- Tailwind v4 CSS-first: `resources/css/app.css`. **No** `tailwind.config.js`.
- Prefer tokens: `bg-background`, `text-foreground`, `text-muted-foreground`, `border-border`, `bg-muted`, `bg-card`. Palette is Tidewater (teal primary). Font is Instrument Sans (Bunny, via `vite.config.js`).
- Prefer logical spacing (`ms-`, `me-`, `ps-`, `pe-`, `start-`, `end-`) for new layout.
- JS entries: `resources/js/app.js` (Alpine: `geoBanner`, calculators, charts, date-picker, edit-lock, escape-back) and **separate** `resources/js/passkeys.js`. Parser: `resources/js/amount-expression.js`. `npm test` covers `tests/js/**/*.test.js`.
- Do not add Alpine `x-on:*` piles on Flux tags; bind in `alpine:init` / `init()`.
- `layouts/app/header.blade.php` is an unused starter leftover — do not wire new UI through it.

---

## Copy / i18n

Supported codes live in `config('app.supported_locales')` (`en`, `fr`). English is the source language (the `__()` key). Catalogs are JSON files at `lang/{locale}.json` — do **not** introduce `trans('pages.invoices.title')` PHP files.

| Surface | Locale |
| --- | --- |
| Staff UI | `users.locale`, else guest cookie `ll_locale`, else `APP_LOCALE` |
| Invoice PDF, invoice email, `/pay/{company}` | `contacts.locale` → `companies.locale` → `en` via `Locales::forDocument()` |

`SetLocale` middleware applies the UI locale. Document renders must call `Locales::using()` / `Locales::forContactDocument()` so a queued mailable does not leak the customer language into the next job.

`lang/fr.json` must contain every static `__()` key and no leftover keys. `tests/Feature/I18n/TranslationCatalogTest.php` fails if a new string is added without a French entry (acronyms/symbols may be omitted and stay English), or if the catalog keeps a key that is not referenced by `__()` or `#[Title]`.

User-authored text (line descriptions, memos, `customer_message`, account names) is **not** translated. New chrome goes through `__()`. `#[Title('Invoice')]` values are passed through `__()` in `partials/head.blade.php`.

---

## Testing

Pest 4 + Laravel plugin. Feature tests bind `Tests\TestCase` + `RefreshDatabase` (`tests/Pest.php`). Unit tests do not refresh the database.

`TestCase` generates Passport keys once per process if `storage/oauth-private.key` is missing (MCP / `api` guard).

### Patterns

**Posting** — `tests/Feature/Accounting/InvoicePostingTest.php`:

```php
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});
```

`CompanyObserver` seeds the chart of accounts and GST. Drive the **Poster**, assert integer cents, `journal_entry_id`, and account `balance_cents`.

**Gotcha:** `User::factory()` `afterCreating` always makes a **personal company**, attaches Owner, `switchCompany`, and records legal acceptance. A test that also `Company::factory()` has **two** organizations. Password is `'password'`.

**Livewire:**

```php
Livewire::test('pages::invoices.form', ['company' => $this->company])
    ->assertHasNoErrors();
```

**API** — mint `CompanyApiKey`, send `Authorization: Bearer {$plaintext}` (or `X-Api-Key`). Create related models **with** `current_company` bound, then **forget** it so `AuthenticateApiKey` binds from the key. Cross-tenant FKs → 422. Use `withoutGlobalScopes()` when asserting across tenants.

**MCP** — `bindMcpTenant($company, $abilities = [])` in `tests/Pest.php`. Empty abilities = full access. Write tests also need `config(['mcp.write_enabled' => true])` **and** the company’s `settings.mcp.agentic_writes`.

New behavior needs a test. For date-sensitive changes, run the filter on SQLite before pushing.

---

## CI

Three workflows on push/PR to `main` can fail the PR. PHP 8.5, Node 22.

1. **`tests.yml`** — `npm test`, `npm run build`, Pest with `DB_CONNECTION=sqlite` `DB_DATABASE=':memory:'`.
2. **`lint.yml`** — Pint `--test` **and** PHPStan. Easy to miss; `composer run test` does not run PHPStan.
3. **`security.yml`** — `composer audit --locked --abandoned=report`, `npm audit --audit-level=high`, gitleaks (blocking, full history), Semgrep (non-blocking SARIF), dependency-review on PRs (`fail-on-severity: high`). Weekly Monday cron.

Docker image workflow builds **linux/amd64** only; `latest` tracks release tags, `edge` tracks `main`.

---

## API and MCP

Narrative: [`docs/api-v1.md`](docs/api-v1.md). Machine spec: `GET /api/v1/openapi.json` (source `resources/api/openapi.yaml`).

Same Actions and Posters as the UI. JSON envelope `{ data }`. Default-deny error messages. CSRF is skipped for `stripe/webhook`, `inbound-email/*`, and `csp-report` (those verify by signature).

MCP (`routes/ai.php`):

| Server | Class | Routes |
| --- | --- | --- |
| Read-only Q&A | `app/Mcp/Servers/BusinessQaServer.php` | `mcp/business` (API key), `mcp/business/{company}` (Passport + `mcp.company`) |
| Writes | `app/Mcp/Servers/BusinessActionsServer.php` | `mcp/business-actions` and `…/{company}` |

Writes are **off by default**. Both `MCP_WRITE_ENABLED=true` (`config('mcp.write_enabled')`) **and** per-organization `Company::agenticWritesEnabled()` must be on. Propose stages a token; `ConfirmProposalTool` is the only tool that writes. OAuth discovery routes are registered in `AppServiceProvider::configurePassport` (`Mcp::oauthRoutes()`), not in `ai.php`.

---

## Jobs, seeders, Docker

Scheduler is `routes/console.php`. Every task has `onFailure(SchedulerFailureAlert::…)`. Per-organization commands take `{company?}` (id or slug) and often `--sync` so you can run one org in-process.

| Signature | Cadence |
| --- | --- |
| `payroll:accrue-time-off {company?} {--date=}` | daily 01:00 |
| `recurring:generate {company?} {--sync}` | 02:00 |
| `depreciation:generate {company?} {--sync}` | 02:30 |
| `integrity:check {company?} {--fix} {--no-alert}` | 04:00 |
| `insights:generate {company?} {--sync}` | 05:00 |
| `rates:fetch {--date=}` | 06:00 |
| `reports:send-scheduled {company?} {--sync}` | 07:00 |
| `reminders:send {company?} {--sync}` | 07:30 |
| `rates:health {--no-alert}` | 08:30 |
| `backups:prune-expired` | daily |
| `security:monitor {--window=} {--no-alert}` | hourly |
| `ops:monitor-failed-jobs {--window=} {--no-alert}` | hourly |

On-demand (not scheduled): `app:upgrade`, `audit:verify`, `backup:export` / `backup:import`, `storage:check`, `proof:generate`, `payroll:verify-*`. Payroll year-end procedure is in README — append tables in `app/Support/Payroll/Constants/{Federal,Provincial}Constants.php`, never rewrite history.

`composer run setup` installs and migrates **without** `--seed`. Demo seeder is **not** part of `DatabaseSeeder`.

Docker: two compose files — do not mix them. Root `docker-compose.yml` is the **dev** bind-mount (`docker/Dockerfile.dev`; PHP/Node/MySQL/Vite/Mailpit). `docker/docker-compose.yml` is the **self-host** GHCR image (FrankenPHP PHP 8.5, no bind mount, `config:cache`). Do **not** scale the `app` service (it runs `app:upgrade` / migrations). Scale `queue` instead. MySQL is started with `--skip-log-bin` so audit-log triggers can be created. Self-host container start **exits** if `APP_KEY` is unset. Health: `GET /up`.

---

## Anti-patterns

- Posting logic in a Volt SFC, or a second Save* in a controller / MCP tool.
- Querying tenant models in a job or command without binding `current_company`.
- `NavPreference::create()` (or any `BelongsToCompany` create) for a **new** company while another tenant is bound — the creating hook will stamp the wrong id. `CreateCompany::hideNonProfitSalesNav` uses `NavPreference::insert` with an explicit `company_id` for this reason.
- Floats, or `number_format($cents / 100, 2)`, for new money math. Use `Money`.
- Deleting a posted document; pointing document lines at AR/AP control accounts.
- Adding nav only in Blade; new SFCs without `⚡`; `Volt::route`; class-based Livewire pages.
- Wiring UI through `layouts/app/header.blade.php`.
- Surfacing raw `RuntimeException` messages on the API (the Volt UI sometimes shows `$e->getMessage()` — do not copy that to JSON).
- A Policy per Invoice/Bill/… — this app uses section middleware + scoped queries.
- Editing historical payroll constant rows.
- `withoutGlobalScopes()` without a `company_id` filter.
- Assuming `CompanyScope` is always on.
- Storing a datetime in a date column.
- Passing `.blade.php` to Pint.

---

## Where to look next

| Need | File |
| --- | --- |
| CLA, PR steps, Pint/PHPStan local gate | [CONTRIBUTING.md](CONTRIBUTING.md) |
| Env table, deploy, Docker, scheduler detail, payroll T4127 | [README.md](README.md) |
| API narrative | [docs/api-v1.md](docs/api-v1.md) |
| OpenAPI | `resources/api/openapi.yaml` |
| User-manual capture notes | [docs/manuals/README.md](docs/manuals/README.md) |
| Ops backlog (Forge/S3), not in-tree coding tasks | [TODOS.md](TODOS.md) |
| Tenant isolation regressions | `tests/Feature/Security/TenantIsolationTest.php` |
