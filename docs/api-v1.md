# LineLedger API v1 — Integration Guide

A company-scoped REST API over a single organization's books: **full CRUD on 25
resources**, plus the lifecycle actions (post, void, fulfill, file, complete) that
drive documents through the general ledger.

> **Two documents, one API.** This guide is the narrative — auth, conventions,
> lifecycle, and worked examples. The exhaustive per-endpoint reference is the
> machine-readable OpenAPI 3.1 spec, served **unauthenticated** at
> **`GET /api/v1/openapi.json`** (source: `resources/api/openapi.yaml`). Point your
> client generator at that; read this to understand how the API behaves.

---

## 1. Authentication

All requests authenticate with a **company-scoped API key**.

- A key belongs to exactly one company. The key implies the company on
  every request — there is **no** `{company}` in the URL.
- A user with the **Owner** or **Admin** role on a company can mint, rotate,
  and revoke keys from **Settings → Security → API keys** in the web UI.
- Keys are shown **once** at creation. We store only a SHA-256 hash, plus
  the prefix and last 4 characters for display. Lost keys must be rotated.

Send the key on every request as a bearer token:

```http
Authorization: Bearer ll_live_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
```

`X-Api-Key: <token>` is accepted as a fallback if you can't set a bearer
header.

**Auth failures** return `401`:

```json
{ "message": "Invalid API key" }
```

Reasons: missing header, unknown token, revoked key.

**Rotation:** rotating a key in the UI creates a new key and immediately
revokes the old one. Plan for short-lived overlap by deploying the new
key first, then rotating (or accept brief 401s during rollout).

### 1.1 Scopes

A key can be restricted to a set of **abilities**. There are two grains:

| Grain | Example | Meaning |
|---|---|---|
| Domain | `sales:write` | Everything under sales — customers, sales orders, invoices, receipts, credit memos. |
| Resource | `invoices:read` | Just that one resource. |

The seven domains are `sales`, `purchases`, `banking`, `accounting`, `inventory`,
`tax`, and `settings`. Resolution rules, most to least specific:

- **A key with no abilities has full access.** This is the default for keys minted
  before scopes existed, and for keys created without selecting any.
- An exact grant matches.
- A `:write` grant satisfies the matching `:read` requirement.
- A **domain** grant is a superset of every resource under it — `sales:write`
  satisfies `invoices:write`, `customers:read`, and so on.

Every read endpoint requires `{resource}:read`; every write and every action
endpoint requires `{resource}:write`. A key without the scope gets `403`:

```json
{ "message": "This API key is not permitted to perform the \"invoices:write\" action." }
```

### 1.2 Rate limits

Two limits apply, whichever is hit first:

| Limit | Bucket |
|---|---|
| **120 / minute** | per client IP |
| **60 / minute** | per API key |

Exceeding either returns `429` with `Retry-After` and `X-RateLimit-*` headers. The
throttle runs *before* key authentication, so requests with bad keys are limited too
— which is the point: it caps brute-forcing the key lookup.

---

## 2. Conventions

| Aspect | Rule |
|---|---|
| Base URL | `/api/v1` |
| Content type | `application/json` for both request and response |
| Money | All money fields are **integer cents** (e.g. `total_cents: 10500` = $105.00). Never floats. |
| Quantities | Strings, up to 4 decimal places (e.g. `"quantity": "1.5"`). |
| Negative lines | `unit_price_cents` may be **negative** on an invoice line to record a discount or credit against the invoice (e.g. a member discount posted to a contra-revenue account). `quantity` stays positive — the sign lives on the price. The invoice **total must stay above zero**; a net credit is a credit memo, not an invoice. |
| Dates | ISO `YYYY-MM-DD`. |
| Timestamps | ISO 8601 in the response. |
| Single resource | `{ "data": { … } }`. |
| Collection | `{ "data": [ … ], "meta": { … }, "links": { … } }`. |
| Created | `201` with the resource. |
| Updated / action | `200` with the resource. |
| Deleted (draft) | `204` with an empty body. |
| Voided (posted) | `200` with the voided resource — **not** 204. See [§4](#4-document-lifecycle). |

> **Why cents and string quantities?** The whole accounting engine is
> integer-cents end-to-end to avoid float drift. Quantities are strings
> so `"0.0001"` round-trips correctly.

### 2.1 Listing, filtering, sorting, pagination

Every `index` endpoint accepts the same query parameters. Which columns are
searchable and sortable varies per resource — the OpenAPI spec lists them.

| Param | Effect |
|---|---|
| `page` | Page number (1-based). |
| `per_page` | Page size. Default **25**, capped at **100** — larger values are clamped, not rejected. |
| `search` | Case-insensitive `LIKE` across that resource's text columns (e.g. `invoice_no`, `memo`). |
| `status` | Exact match on the status column, where the resource has one. |
| `from` / `to` | Inclusive date range on the resource's document date, where it has one. |
| `sort` + `direction` | Sort by a **whitelisted** column, `asc` or `desc`. An unrecognized `sort` is silently ignored and the resource's default ordering applies. |

```http
GET /api/v1/invoices?status=posted&from=2026-01-01&to=2026-03-31&sort=invoice_date&direction=desc&per_page=50
```

```json
{
  "data": [ { "id": 901, "invoice_no": "INV-000123", "…": "…" } ],
  "meta": { "current_page": 1, "per_page": 50, "total": 214, "last_page": 5 },
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" }
}
```

---

## 3. The resource map

Every resource follows the **same five-route shape**:

```
GET    /api/v1/{resource}             index    → {resource}:read
GET    /api/v1/{resource}/{id}        show     → {resource}:read
POST   /api/v1/{resource}             store    → {resource}:write
PUT    /api/v1/{resource}/{id}        update   → {resource}:write
PATCH  /api/v1/{resource}/{id}        update   → {resource}:write
DELETE /api/v1/{resource}/{id}        destroy  → {resource}:write
```

Some resources add **action** endpoints, always `POST {resource}/{id}/{action}` and
always requiring `{resource}:write`:

| Domain | Resource | Extra actions |
|---|---|---|
| `sales` | `customers` | — |
| `sales` | `sales-orders` | `fulfill`, `cancel` |
| `sales` | `invoices` | `post` |
| `sales` | `receipts` | `post` |
| `sales` | `credit-memos` | `post`, `refund` |
| `purchases` | `vendors` | — |
| `purchases` | `employees` | — |
| `purchases` | `bills` | `post` |
| `purchases` | `bill-payments` | `post` |
| `banking` | `cheques` | `post` |
| `banking` | `deposits` | `post` |
| `banking` | `transfers` | `post` |
| `banking` | `bank-reconciliations` | `complete` |
| `accounting` | `accounts` | — |
| `accounting` | `journal-entries` | `post` |
| `accounting` | `assets` | — |
| `accounting` | `asset-categories` | — |
| `inventory` | `items` | — |
| `inventory` | `stock-adjustments` | `post` |
| `tax` | `tax-codes` | — |
| `tax` | `tax-agencies` | — |
| `tax` | `tax-returns` | `file`, `void` |
| `tax` | `tax-return-payments` | `post` |
| `settings` | `payment-terms` | — |
| `settings` | `payment-methods` | — |

**Contacts share one table.** `customers`, `vendors`, and `employees` are the same
`Contact` model filtered by role flag. A contact can hold more than one role, but a
`GET /api/v1/vendors/{id}` for a contact that isn't a vendor returns `404` — each
endpoint only sees its own role.

---

## 4. Document lifecycle

This is the part that differs most from a plain CRUD API. Documents that hit the
general ledger (invoices, bills, receipts, cheques, deposits, journal entries, …)
have a posted state, and the HTTP verbs map onto it:

| Verb | Draft document | Posted document |
|---|---|---|
| `POST /{resource}` | Creates **and posts** by default. Send `"post": false` to leave a draft. | — |
| `PATCH /{resource}/{id}` | Edits the draft. | **Reposts in place** where supported; `409` where not. |
| `POST /{resource}/{id}/post` | Posts it. | Reposts it. |
| `DELETE /{resource}/{id}` | **Hard-deletes**, returns `204`. | **Voids** with a reversing journal entry, returns `200` and the voided document. |

Two consequences worth designing around:

- **`DELETE` is not idempotent in the usual sense.** On a posted document it writes
  a reversing entry and returns the resource; calling it again returns `409`
  (`"Invoice is already voided."`). Check the status code, not just success.
- **Repost support is per document type.** Invoices, credit memos, receipts, bills,
  bill payments, journal entries, and deposits repost in place. Cheques, stock
  adjustments, and tax-return payments do **not** — editing one after posting
  returns `409`, and you should void and recreate.

Posting is atomic with creation: if the post fails (locked period, unbalanced
entry, zero total), the document the request just created is rolled back too.
Nothing partial persists.

### 4.1 Drafts

`"post": false` on any create leaves the document unposted:

```bash
curl -X POST https://your-host/api/v1/invoices \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{
    "post": false,
    "contact_id": 42,
    "invoice_date": "2026-05-20",
    "lines": [{"quantity":"1","unit_price_cents":50000,"account_id":14}]
  }'
```

The response has `status: "draft"` and a null `journal_entry_id`. Post it later with
`POST /api/v1/invoices/{id}/post`.

---

## 5. Worked examples

### 5.1 Mint a key

In the web UI: **Settings → Security → API keys → Create API key**. Choose the
abilities the integration needs — an invoicing integration wants `sales:write` and
nothing else. Copy the plaintext value (`ll_live_…`); you won't see it again.

### 5.2 Create a customer

```bash
curl -X POST https://your-host/api/v1/customers \
  -H "Authorization: Bearer $KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "display_name": "Acme Corp",
    "email": "billing@acme.com",
    "invoice_emails_enabled": true,
    "reminder_emails_enabled": true
  }'
```

> **Customer emails are opt-in.** `invoice_emails_enabled` and
> `reminder_emails_enabled` both default to **false**, and LineLedger will not email
> a customer an invoice or a payment reminder until one is turned on. If you're
> migrating customers in and expect the dunning run to reach them, set these
> explicitly.

### 5.3 Invoice → payment, end to end

```bash
# 1. create a customer
CUSTOMER_ID=$(curl -sX POST https://your-host/api/v1/customers \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d '{"display_name":"Acme"}' | jq '.data.id')

# 2. invoice them (income account 14 is from your chart of accounts)
INVOICE_ID=$(curl -sX POST https://your-host/api/v1/invoices \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d "{
    \"contact_id\": $CUSTOMER_ID,
    \"invoice_date\": \"2026-05-20\",
    \"lines\": [
      {\"quantity\":\"1\",\"unit_price_cents\":50000,\"account_id\":14}
    ]
  }" | jq '.data.id')

# 3. record a payment that fully applies to that invoice
curl -X POST https://your-host/api/v1/receipts \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" \
  -d "{
    \"contact_id\": $CUSTOMER_ID,
    \"receipt_date\": \"2026-05-21\",
    \"deposit_to_account_id\": 5,
    \"amount_cents\": 50000,
    \"applications\": [
      {\"invoice_id\": $INVOICE_ID, \"amount_cents\": 50000}
    ]
  }"

# 4. read it back
curl -s "https://your-host/api/v1/invoices/$INVOICE_ID" \
  -H "Authorization: Bearer $KEY" | jq '.data.status'   # → "paid"
```

An invoice reports `balance_cents` — what is still owed by the server's own
arithmetic (total, less receipts applied, less anything reconciled away by a
journal entry) — so clients need not compute `total_cents - amount_paid_cents`.
A receipt reports `unapplied_cents`, the part not yet applied to any invoice.
To apply that credit later, `PUT`/`PATCH` the receipt with its full header and
the **complete** `applications` list (the existing rows plus the new one): the
list is replaced wholesale, and only the invoices in it are recomputed.

### 5.4 Reconcile what you've sent

Because v1 has no idempotency keys, the safe retry pattern is search-then-create:

```bash
curl -s "https://your-host/api/v1/invoices?search=YOUR-REF-123" \
  -H "Authorization: Bearer $KEY" | jq '.meta.total'
```

Put your own reference in `memo` on create, then search it before retrying.

---

## 6. Error reference

| Status | Meaning |
|---|---|
| `401` | Missing, unknown, or revoked API key. |
| `403` | The key is valid but lacks the required scope. |
| `404` | No such record **in this company** (also returned for a wrong-role contact). |
| `409` | The operation conflicts with the document's state — already voided, or an edit to a posted document that can't repost. |
| `422` | Validation failed, or the post was rejected (locked period, unbalanced, zero total, filed tax period). |
| `429` | Rate limit exceeded. |
| `500` | Unexpected server error. The message is deliberately generic. |

### Validation (`422`)

Laravel's standard shape:

```json
{
  "message": "The display name field is required.",
  "errors": {
    "display_name": ["The display name field is required."]
  }
}
```

Common causes:

- Missing required field.
- A foreign key from a **different company** (`contact_id`, `account_id`,
  `tax_code_id`, `terms_id`, `item_id`, `payment_method_id`,
  `deposit_to_account_id`). Every `*_id` is validated against the calling key's
  company — an id from another company is a validation error, not a 404.
- `contact_id` exists but doesn't hold the required role (`is_customer = false`).
- `applications.*.invoice_id` doesn't belong to the same `contact_id`, or is in
  `draft` / `void` / `paid`.
- Sum of `applications[].amount_cents` exceeds `amount_cents`.
- Invoice lines that net to zero or to a credit (`lines`: `"The invoice total must
  be greater than zero. Use a credit memo for a net credit."`).

### Posting (`422`, no `errors` key)

```json
{ "message": "Entry date 2026-04-15 is on or before the company lock date 2026-04-30; posting is blocked." }
```

Triggers:

- Document date on or before the company's lock date.
- A tax return covering this date for a referenced tax code has already been filed.
- The document total is 0 (every line is `quantity * unit_price = 0`).
- The entry doesn't balance (journal entries).

> **Error messages are deliberately conservative.** Only messages explicitly marked
> client-safe reach you. An internal failure — a missing control account, a
> misconfigured item — is logged server-side and returned as a generic
> `"This document could not be posted."` rather than leaking account names,
> balances, or ids.

### Not found (`404`)

```json
{ "message": "Resource not found." }
```

The model class is never named, by design.

---

## 7. Things v1 does **not** do

Intentional omissions — call them out if a coder asks.

- **No bulk endpoints.** One document per request.
- **No idempotency keys.** Retrying after a network error may create duplicates —
  see [§5.4](#54-reconcile-what-youve-sent) for the search-then-create pattern.
- **No webhooks.** Payment state changes won't push to you; poll the relevant
  index endpoint with a `from` filter.
- **No cross-company access.** One key, one company, always. To integrate with
  several organizations, mint a key per organization.
- **No key expiry.** Keys live until revoked.
- **No true partial updates.** `PATCH` and `PUT` are the same operation: send the
  document's full payload. Required fields stay required on update, and for line
  documents `lines` is **required and replaces the entire set** — the existing lines
  are deleted and rebuilt from what you send. Read the record first, modify, send it
  back whole.

---

## 8. Reference: foreign-key tables per company

When validating `*_id` fields you'll see referenced in payloads:

| Field | Table | Where it comes from |
|---|---|---|
| `account_id`, `default_income_account_id`, `deposit_to_account_id` | `accounts` | Chart of Accounts. Seeded per-company at creation. Manageable under **Accounts**, or via `/api/v1/accounts`. To read the ids off the web UI, enable **Columns → Account ID (API)** on the Accounts page; the MCP `chart-of-accounts-tool` reports the same id as `API id`. |
| `tax_code_id`, `default_tax_code_id` | `tax_codes` | Seeded per-company. **Settings → Lists → Tax codes**, or `/api/v1/tax-codes`. The MCP `tax-codes-tool` reports the id as `API id`. |
| `terms_id`, `default_terms_id` | `payment_terms` | **Settings → Lists → Payment terms**, or `/api/v1/payment-terms`. |
| `item_id` | `items` | **Settings → Lists → Items**, or `/api/v1/items`. The MCP `items-catalog-tool` reports the id as `API id`. |
| `payment_method_id` | `payment_methods` | **Settings → Lists → Payment methods**, or `/api/v1/payment-methods`. The MCP `payment-methods-tool` reports the id as `API id`. |
| `contact_id` | `contacts` | `/api/v1/customers`, `/vendors`, `/employees`, or the web UI. The MCP `contacts-directory-tool` reports the id as `API id`. |

All of these are scoped per company; an ID is only valid for the
company that owns the API key making the call.
