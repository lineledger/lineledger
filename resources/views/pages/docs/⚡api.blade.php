<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Documentation — API')] class extends Component {}; ?>

<section class="w-full p-6">
    <x-pages::docs.layout
        :heading="__('API')"
        :subheading="__('Programmatic access to your company over REST.')"
    >
        <flux:text>
            {{ __('The API lets external systems read and write a company\'s data without using the web app — useful for connecting an e-commerce platform, a POS, a billing service, or your own scripts. All endpoints are JSON over HTTPS. If you are not writing code yourself, you can hand this page to whoever is building the integration.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Create an API key') }}</flux:heading>
        <flux:text>
            {{ __('Every request needs a valid company API key. Each key belongs to a single company and can be limited to specific abilities.') }}
        </flux:text>

        <p><strong>{{ __('To create an API key:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Security and scroll to the API keys section.') }}</li>
            <li>{{ __('Select Create API key and give it a label that says what it is for (for example "Website checkout").') }}</li>
            <li>{{ __('Choose the scopes the integration needs, or leave them empty for full access.') }}</li>
            <li>{{ __('Copy the key shown on screen and store it securely — it is displayed only once.') }}</li>
            <li>{{ __('Select Edit later to rename a key or change its scopes. The key value stays the same, so the integration keeps working.') }}</li>
            <li>{{ __('Revoke the key from the same page the moment it is no longer needed or you suspect it leaked.') }}</li>
        </ol>

        <x-docs.callout type="warning">
            {{ __('The full key is shown once, at creation. If you lose it you cannot recover it — you generate a new key and revoke the old one. Treat keys like passwords and never commit them to source control.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Authentication') }}</flux:heading>
        <flux:text>
            {{ __('Pass the key in either header on every request:') }}
        </flux:text>
        <pre class="bg-muted rounded p-4 overflow-x-auto text-sm"><code>Authorization: Bearer YOUR_API_KEY
# or
X-Api-Key: YOUR_API_KEY</code></pre>

        <flux:heading size="lg" class="mt-8">{{ __('Scopes') }}</flux:heading>
        <flux:text>
            {{ __('A key may be limited to specific abilities written as domain:action (e.g. sales:read, accounting:write). A write scope also grants read. A key with no scopes selected has full access. Read endpoints require the :read ability for their domain; writes require :write — otherwise the request returns 403.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Document lifecycle') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('POST creates and posts a document by default; send "post": false to create a draft.') }}</li>
            <li>{{ __('PATCH edits a draft, or reposts a posted document in place where supported (invoices, credit memos, receipts, bills, bill payments, journal entries, deposits).') }}</li>
            <li>{{ __('Documents without in-place repost (cheques, stock adjustments, tax-return payments) return 409 if edited after posting — void and recreate.') }}</li>
            <li>{{ __('DELETE hard-deletes a draft, or voids a posted document with a reversing journal entry.') }}</li>
            <li>{{ __('Amounts are integer cents; dates are YYYY-MM-DD.') }}</li>
        </ul>

        <flux:heading size="lg" class="mt-8">{{ __('Base URL & spec') }}</flux:heading>
        <pre class="bg-muted rounded p-4 overflow-x-auto text-sm"><code>{{ url('/api/v1') }}</code></pre>
        <flux:text>
            {{ __('The full machine-readable OpenAPI spec is available at') }}
            <a href="{{ url('/api/v1/openapi.json') }}" class="underline" target="_blank" rel="noopener">{{ url('/api/v1/openapi.json') }}</a>.
            {{ __('Import it into Postman, Insomnia, or your code generator. The interactive reference below is generated from it.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Errors') }}</flux:heading>
        <flux:text>
            {{ __('Every error response is a bare JSON envelope with a single message field — and a field-keyed errors object on 422. A 500 looks the same shape, so your client only has to parse one format.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li><code>401</code> — {{ __('missing or invalid API key.') }}</li>
            <li><code>403</code> — {{ __('the key lacks the required scope.') }}</li>
            <li><code>404</code> — {{ __('the resource was not found in this company.') }}</li>
            <li><code>409</code> — {{ __('the operation conflicts with the document\'s lifecycle (e.g. editing a posted, non-repostable document).') }}</li>
            <li><code>422</code> — {{ __('validation failed, period is locked, or the entry is unbalanced. The body carries a message and a field-keyed errors object.') }}</li>
            <li><code>429</code> — {{ __('rate limit exceeded. Requests are throttled per IP and per key — slow down and retry.') }}</li>
            <li><code>500</code> — {{ __('an internal error. The body is still a {"message": "..."} envelope so the same parser handles it.') }}</li>
        </ul>

        <x-docs.callout type="note">
            {{ __('Requests are rate-limited per IP and per API key to keep one noisy integration from starving the others. If you hit 429, back off and retry. All error responses — including 500s — share the same bare {"message": "..."} envelope (422 adds an errors object), so client code only needs one parser.') }}
        </x-docs.callout>

        <flux:heading size="lg" class="mt-8">{{ __('Auditing & key lifecycle') }}</flux:heading>
        <flux:text>
            {{ __('Every write made through the API is recorded in the company audit log and attributed to the key that made it, not to a user — so automated activity is easy to tell apart from manual work. Creating, editing, rotating, and revoking keys are logged as security events. Edit a key to change its scopes without changing its secret, rotate it to replace the secret without losing the integration, and revoke it the instant it is no longer needed.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Best practices') }}</flux:heading>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('Treat API keys like passwords — never check them into source control.') }}</li>
            <li>{{ __('Generate a separate, narrowly-scoped key per integration so you can revoke one without breaking the others.') }}</li>
            <li>{{ __('Handle 422 by surfacing the message to the operator; do not auto-retry.') }}</li>
            <li>{{ __('Idempotency: posting the same invoice twice creates two invoices — deduplicate in your caller if upstream might retry.') }}</li>
        </ul>

        {{-- ───────────────────── Business Q&A MCP server ───────────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Business Q&A MCP server') }}</flux:heading>
        <flux:text>
            {{ __('The Business Q&A server is a read-only MCP endpoint that lets an AI assistant — Claude, ChatGPT desktop, Cursor, or anything else that speaks Model Context Protocol — query Demo Company Inc.\'s books in plain English. The assistant calls one of the tools below, the server runs the same reporting services the web app uses, and the answer comes back with the numbers already crunched. No data is written and no journal entries are created.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Tools') }}</flux:heading>
        <flux:text>
            {{ __('The server exposes sixteen read-only tools. Each one runs the same reporting service that powers the matching page in the web app, so the numbers always agree. The assistant picks whichever fits the question.') }}
        </flux:text>

        <table>
            <thead>
                <tr>
                    <th>{{ __('Tool') }}</th>
                    <th>{{ __('What it returns') }}</th>
                    <th>{{ __('Notes') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><code>Financial summary</code></td>
                    <td>{{ __('Income and expenses for the period, year-to-date net income, cash on hand, and money owed to and by the company.') }}</td>
                    <td>{{ __('The plain-language "how are we doing?" snapshot.') }}</td>
                </tr>
                <tr>
                    <td><code>Profit and loss</code></td>
                    <td>{{ __('Income and expenses broken down by account, with the net profit or loss for a period.') }}</td>
                    <td>{{ __('Accepts a friendly period (last_quarter) or explicit start/end dates.') }}</td>
                </tr>
                <tr>
                    <td><code>Balance sheet</code></td>
                    <td>{{ __('Assets, liabilities, and equity as of a date, with the accounting equation.') }}</td>
                    <td>{{ __('As-of date defaults to today.') }}</td>
                </tr>
                <tr>
                    <td><code>Cash flow</code></td>
                    <td>{{ __('Operating, investing, and financing sections plus the net change in cash for a period.') }}</td>
                    <td>{{ __('Derived from the GL — always reconciles.') }}</td>
                </tr>
                <tr>
                    <td><code>Trial balance</code></td>
                    <td>{{ __('Every account with a non-zero balance in its debit or credit column, with totals that tie out.') }}</td>
                    <td>{{ __('Good for spot-checking before period close.') }}</td>
                </tr>
                <tr>
                    <td><code>Accounts receivable</code></td>
                    <td>{{ __('Customers with an outstanding balance, largest first, and the total owed to you.') }}</td>
                    <td>{{ __('Answers "who owes me money?"') }}</td>
                </tr>
                <tr>
                    <td><code>Accounts payable</code></td>
                    <td>{{ __('Vendors the company still owes, largest first, and the total payable.') }}</td>
                    <td>{{ __('Answers "who do I owe?"') }}</td>
                </tr>
                <tr>
                    <td><code>Account balance</code></td>
                    <td>{{ __('Balance and recent ledger activity for a single account by name or code.') }}</td>
                    <td>{{ __('Useful for "what\'s in chequing right now?" questions.') }}</td>
                </tr>
                <tr>
                    <td><code>Sales report</code></td>
                    <td>{{ __('Sales grouped by customer or item, ranked by revenue.') }}</td>
                    <td>{{ __('Mirrors the Sales report in the web app.') }}</td>
                </tr>
                <tr>
                    <td><code>Inventory status</code></td>
                    <td>{{ __('On-hand quantity, reorder point, and low-stock flags per tracked item.') }}</td>
                    <td>{{ __('Set low-only to see just the items needing a restock.') }}</td>
                </tr>
                <tr>
                    <td><code>Find contact</code></td>
                    <td>{{ __('One customer or vendor with their AR and AP balances and a recent-activity summary.') }}</td>
                    <td>{{ __('Looks up by partial name.') }}</td>
                </tr>
                <tr>
                    <td><code>List invoices</code></td>
                    <td>{{ __('Invoices filtered by status, customer, or issue-date range.') }}</td>
                    <td>{{ __('Handy for "what\'s overdue?" questions.') }}</td>
                </tr>
                <tr>
                    <td><code>Sales tax</code></td>
                    <td>{{ __('Tax collected, paid, and net owed per agency for a period.') }}</td>
                    <td>{{ __('Lines up with the Sales tax report.') }}</td>
                </tr>
                <tr>
                    <td><code>Form 1099</code></td>
                    <td>{{ __('1099-NEC vendor payment totals for a calendar year.') }}</td>
                    <td>{{ __('US companies only; notes the $600 reporting threshold.') }}</td>
                </tr>
                <tr>
                    <td><code>Company profile</code></td>
                    <td>{{ __('Organization type, jurisdiction, home currency, fiscal year, tax numbers, and CRA filing profile.') }}</td>
                    <td>{{ __('Frames reporting periods correctly.') }}</td>
                </tr>
                <tr>
                    <td><code>Chart of accounts</code></td>
                    <td>{{ __('Every account with its code, name, subtype, GIFI code, and current balance.') }}</td>
                    <td>{{ __('The full account list, grouped by type.') }}</td>
                </tr>
            </tbody>
        </table>

        <flux:text>
            {{ __('Alongside the tools, the server publishes reference resources the assistant can read on its own — the company profile, the chart of accounts, the tax codes, the items catalog, the contacts directory, and the CRA GIFI catalog — and four guided prompts: month-end close review, AR collections, sales-tax filing prep, and an entity-aware year-end tax-prep checklist.') }}
        </flux:text>

        <flux:heading size="md" class="mt-6">{{ __('Connection methods') }}</flux:heading>
        <flux:text>
            {{ __('You can connect an MCP client two ways: with an API key you create yourself, or with OAuth — sign in once and let the client manage tokens for you. Pick whichever your client supports best.') }}
        </flux:text>

        <p><strong>{{ __('To connect with an API key:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('Open Settings → Security, scroll to the API keys section, and select Create API key. Name it after the assistant ("Claude desktop") so you can revoke it cleanly later.') }}</li>
            <li>{{ __('Copy the key — it is shown only once.') }}</li>
            <li>{{ __('In your MCP client, add a new server pointing at the URL below and paste the key into the Authorization header as Bearer YOUR_API_KEY.') }}</li>
        </ol>

        <pre class="bg-muted rounded p-4 overflow-x-auto text-sm"><code>{{ url('/mcp/business') }}</code></pre>

        <x-docs.figure
            src="{{ asset('docs/screenshots/api/api-key.png') }}"
            alt="{{ __('The API keys section on Settings → Security with a new key named Claude desktop, shown once at creation') }}"
            caption="{{ __('The API keys section on Settings → Security. Generate one key per assistant and revoke it the moment it is no longer needed.') }}"
        />

        <p><strong>{{ __('To connect with OAuth:') }}</strong></p>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('In your MCP client, add a server pointing at the URL below, substituting your company slug for {company-slug} (for example demo-company-inc).') }}</li>
            <li>{{ __('Select Authorize. The client opens a browser window on the LineLedger sign-in page.') }}</li>
            <li>{{ __('Sign in with your normal LineLedger account.') }}</li>
            <li>{{ __('Approve the consent screen — it lists Demo Company Inc. and the read-only tools the assistant will be allowed to call.') }}</li>
        </ol>

        <pre class="bg-muted rounded p-4 overflow-x-auto text-sm"><code>{{ url('/mcp/business/{company-slug}') }}</code></pre>

        <x-docs.figure
            src="{{ asset('docs/screenshots/api/mcp-consent.png') }}"
            alt="{{ __('The OAuth consent screen showing the assistant name, the company, and the read-only tools being requested') }}"
            caption="{{ __('The OAuth consent screen. Approve once and the client manages the tokens from then on.') }}"
        />

        <flux:text>
            {{ __('OAuth issues a 15-day access token and a 30-day refresh token, so a well-behaved client keeps working for weeks without prompting you to sign in again. Behind the scenes the {company-slug} in the URL is resolved by the BindMcpCompany middleware after Passport authenticates the request, scoping every tool call to the right tenant. Every call is re-checked against your live company membership, so removing someone from the company cuts off their MCP connections at once; unused tokens also lapse on their own after the windows above. To cut off a connection yourself at any time, open Settings → Security → Authorized applications and revoke it — its tokens stop working immediately.') }}
        </flux:text>

        <x-docs.callout type="note">
            {{ __('The Business Q&A server is strictly read-only — no tool on it creates, edits, posts, or voids anything. To let an assistant draft documents for you, use the separate write-enabled server described next, or give an integration an API key with the right :write scope and point it at the REST API above.') }}
        </x-docs.callout>

        {{-- ───────────────── Agentic writes (propose → confirm) ───────────────── --}}
        <flux:heading size="lg" class="mt-8">{{ __('Agentic writes: let an assistant draft documents') }}</flux:heading>
        <flux:text>
            {{ __('A second, write-enabled MCP server lets an assistant go beyond answering questions and actually draft an invoice, a vendor bill, a paid expense, or a manual journal entry for Demo Company Inc. It is deliberately kept apart from the Q&A server and is off by default. Because an MCP client has no screen on which a human can eyeball a draft before it posts, every change runs through a mandatory two-step propose-then-confirm handshake — the assistant can never write to your books in a single call.') }}
        </flux:text>

        <pre class="bg-muted rounded p-4 overflow-x-auto text-sm"><code>{{ url('/mcp/business-actions') }}
# or, for the one-click OAuth connector:
{{ url('/mcp/business-actions/{company-slug}') }}</code></pre>

        <flux:heading size="md" class="mt-6">{{ __('The propose → confirm handshake') }}</flux:heading>
        <ol class="list-decimal ps-6 space-y-1">
            <li>{{ __('The assistant calls a propose tool — propose invoice, propose bill, propose expense, or propose journal entry. The server validates the customer or vendor, every line account, and the amounts, computes the exact totals, and stages the draft. It returns a token and a plain-language preview. Nothing is written to the ledger at this step.') }}</li>
            <li>{{ __('The assistant shows you the preview and waits for your explicit go-ahead.') }}</li>
            <li>{{ __('Once you say yes, the assistant calls the confirm-proposal tool with that token. Only this step writes: it replays the staged payload through the very same Save action and poster the web app uses, so the lock-date rule and the audit trail both apply automatically.') }}</li>
        </ol>

        <x-docs.callout type="note" heading="{{ __('Amounts are in dollars here') }}">
            {{ __('Unlike the REST API, the propose tools take amounts in dollars (for example a unit price of 19.99) — the server converts to exact integer cents for you. A proposal posts to the ledger by default; the assistant can pass post=false to stage a draft document instead.') }}
        </x-docs.callout>

        <flux:heading size="md" class="mt-6">{{ __('Turning it on') }}</flux:heading>
        <flux:text>
            {{ __('Agentic writes are protected by two independent switches, and both must be on. If either is off, every propose and confirm call is politely refused.') }}
        </flux:text>
        <ul class="list-disc ps-6 space-y-1">
            <li>{{ __('An operator-level server flag — the MCP_WRITE_ENABLED environment variable, set by whoever runs your LineLedger instance. It is off by default and fails closed.') }}</li>
            <li>{{ __('A per-organization opt-in — your company must separately enable agentic writes. Also off by default, so a self-hosted operator flipping the server flag does not silently switch writing on for every tenant.') }}</li>
        </ul>

        <x-docs.callout type="warning">
            {{ __('Treat agentic writes as a power tool. Even with both switches on, the assistant must show you each proposal and you must approve it before anything posts — never confirm a draft you have not read. Line items must use ordinary income, expense, asset, or liability accounts; control accounts (Accounts Receivable, Accounts Payable, Undeposited Funds, Retained Earnings) are rejected because the ledger resolves those itself.') }}
        </x-docs.callout>

        <x-docs.figure
            src="{{ asset('docs/screenshots/api/mcp-agentic-consent.png') }}"
            alt="{{ __('The OAuth consent screen for the write-enabled server, listing the propose and confirm tools and warning that the assistant can create documents') }}"
            caption="{{ __('Connecting the write-enabled server. The consent screen spells out that the assistant can draft invoices, bills, expenses, and journal entries for this organization.') }}"
        />

        <flux:heading size="md" class="mt-6">{{ __('The audit trail') }}</flux:heading>
        <flux:text>
            {{ __('Every proposal is recorded as a durable row — who proposed it (the API key or the signed-in staff member), the exact payload, the preview, and its status (pending, confirmed, expired, or rejected). The row never touches the general ledger on its own; only a confirm posts, and the posted journal entry is linked back to the proposal that produced it. Confirming the same token twice is a safe no-op that returns the original result instead of double-posting, and an unconfirmed token expires after 24 hours. If a confirm fails — say the period is locked or the entry will not balance — nothing is written at all, and the assistant is told to fix the issue and propose again.') }}
        </flux:text>

        <flux:heading size="lg" class="mt-8">{{ __('Interactive reference') }}</flux:heading>
        <flux:text>
            {{ __('Every endpoint, request body, and response, generated from the OpenAPI spec.') }}
        </flux:text>
    </x-pages::docs.layout>

    {{-- Redoc renders the spec into its own self-contained UI. Loaded from a CDN
         (no app dependency); it fetches the public /api/v1/openapi.json. --}}
    <div class="mt-6 rounded-lg border border-border overflow-hidden bg-white" wire:ignore>
        <redoc spec-url="{{ url('/api/v1/openapi.json') }}" hide-download-button></redoc>
    </div>
    <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js" nonce="{{ Vite::cspNonce() }}" defer></script>
</section>
