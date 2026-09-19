# LineLedger API v1 — Integration Guide

A company-scoped REST API over a single organization's books: **full CRUD on 25
resources**, plus the lifecycle actions (post, void, fulfill, file, complete) that
drive documents through the general ledger.

> **Two documents, one API.** This guide is the narrative — auth, conventions,
> lifecycle, and worked examples. The complete per-endpoint reference is the
> machine-readable OpenAPI 3.1 spec (version `1.1.0`), served **unauthenticated** at
> **`GET /api/v1/openapi.json`** (source: `resources/api/openapi.yaml`). It covers
> every resource in [§3](#3-the-resource-map). Point your client generator at that;
> read this to understand how the API behaves.

## What's new in 1.1.0 for integrators

No endpoint or field was removed or renamed. One requirement was added: a cheque line
coded to Accounts Receivable or Payable now needs `lines.*.contact_id` to post (see
[Cheques](#cheques)); everything else is additive. Each item is checked against the
1.1.0 controllers, requests and resources.

- **`423 Locked` on a record someone is editing.** `PATCH`/`PUT`, `DELETE` and every
  `POST /{resource}/{id}/{action}` return `423` with a `Retry-After` header while a
  member has that record open in the web app. Never for `GET` or for creates; never
  for `bank-reconciliations`, `stock-adjustments` or `tax-return-payments`, which
  take no edit lock; and a wrong-role contact (`/vendors/{id}` on a customer) still
  answers `404`, not `423`. See [Being edited (423)](#being-edited-423).
- **Negative invoice lines.** `unit_price_cents` may be negative on an invoice line
  (a discount or credit). The invoice must still net **above zero**, or the request
  fails with `422` — a net credit is a credit memo. Credit memos and sales orders
  keep `unit_price_cents >= 0`. See [§2](#2-conventions).
- **`balance_cents` on invoices, `unapplied_cents` on receipts.** The server's own
  figures for what is still owed and what a receipt has not yet applied — stop
  computing `total_cents - amount_paid_cents`. See
  [§5.3](#53-invoice--payment-end-to-end).
- **`sales_rep_id` on invoices and credit memos.** Accepted on create and update and
  echoed in the response. The contact must be an employee (`is_employee = true`) in
  the calling key's company. See [§8](#8-reference-foreign-key-tables-per-company).
- **`PATCH /cheques/{id}` reposts a posted cheque** instead of returning `409`; only a
  voided cheque is frozen. Cheques also take `payee_address`, carry no tax on Accounts
  Receivable / Payable lines, and accept a `cheque_no` that may repeat. The one new
  requirement: `lines.*.contact_id` on an AR/AP line when the cheque posts. See
  [Cheques](#cheques).
- **Document numbers are now validated everywhere.** A duplicate `bill_no`,
  `deposit_no` or bill-payment `payment_no` is a `422` naming the field, as
  `invoice_no`, `credit_memo_no` and `receipt_no` already were, and
  `PATCH /deposits/{id}` checks the same way. Server-assigned numbers skip any that
  already exist. See [§2.2](#22-document-numbers).
- **The OpenAPI spec is version `1.1.0`** and now documents `/transfers`, which was
  missing, so a generated client covers every resource in [§3](#3-the-resource-map).

Rate limits are unchanged: 120 requests/minute per IP and 60 per key
([§1.2](#12-rate-limits)).

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
| Negative lines | `unit_price_cents` may be **negative** on an invoice line to record a discount or credit against the invoice (e.g. a member discount posted to a contra-revenue account). `quantity` stays positive — the sign lives on the price. The invoice **total must stay above zero**; a net credit is a credit memo, not an invoice. Credit memos and sales orders reject a negative price. |
| Document numbers | Optional on create for every numbered document except sales orders (`invoice_no`, `bill_no`, `deposit_no`, …); omit the field and the server assigns the next free number. **Unique per organization** — a duplicate is a `422` naming the field. See [§2.2](#22-document-numbers). |
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

### 2.2 Document numbers

Every numbered document except sales orders takes its number on create. Omit the
field and the server assigns the next free number in the organization's own format
(`INV-000124`, `DEP-000012`, …), skipping any that already exist.

| Resource | Field | On `PATCH`/`PUT` |
|---|---|---|
| `invoices` | `invoice_no` | ignored |
| `bills` | `bill_no` | ignored |
| `credit-memos` | `credit_memo_no` | ignored |
| `receipts`, `POST /credit-memos/{id}/refund` | `receipt_no` | ignored |
| `sales-orders` | `order_no` | not accepted on create or update; always server-assigned |
| `bill-payments`, `tax-return-payments` | `payment_no` | ignored |
| `deposits` | `deposit_no` | accepted |
| `journal-entries` | `entry_no` | accepted |
| `transfers` | `transfer_no` | ignored |
| `cheques` | `cheque_no` (**required**) | accepted |
| `assets` | `asset_no` | ignored |
| `stock-adjustments` | `adjustment_no` | ignored |
| `tax-returns` | `tax_return_no` | ignored |

Numbers are **unique per organization**. Sending one that already exists fails
validation with a `422` naming the field — on `PATCH /deposits/{id}` too, which
checks against every deposit but the one being edited. Two exceptions:

- **`cheque_no` may repeat.** "DD", "EFT" or "e-transfer" are ordinary labels for a
  payment made without a physical cheque, and the API accepts a reused number
  silently (the web form only warns).
- **`entry_no` and `transfer_no` are not checked by validation.** The database's own
  unique index refuses a duplicate instead, so it does not come back as a field
  error. Supply these only from a source you know is unique.

Where the table says *ignored*, the number is fixed once the document exists —
sending the field on an update neither errors nor changes anything. Invoices, credit
memos, receipts, bills, bill payments and deposits can be renumbered in the web app;
transfers, assets, stock adjustments, tax returns and tax-return payments keep the
number they were created with. Because a number you supply is unique, it also works
as a retry guard: create with your own number, and a retried request that would
duplicate it gets a `422` instead of a second document (see
[§5.4](#54-reconcile-what-youve-sent)).

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
| `POST /{resource}/{id}/post` | Posts it. | Reposts invoices, bills, receipts, credit memos and bill payments. `409` for cheques, deposits and journal entries (edit those with `PATCH`) and for the types that never repost. |
| `DELETE /{resource}/{id}` | **Hard-deletes**, returns `204`. | **Voids** with a reversing journal entry, returns `200` and the voided document. |

`PATCH`/`PUT`, `DELETE` and `POST /{resource}/{id}/{action}` on an existing record
return **`423`** while someone has that record open for editing in the web app
(bank reconciliations, stock adjustments and tax-return payments excepted) — see
[Being edited (423)](#being-edited-423).

### Cheques

`POST`/`PATCH /api/v1/cheques` accept an optional `payee_address` object —
`line1`, `line2`, `city`, `region`, `postal_code`, `country` (a two-letter code):

```json
"payee_address": { "line1": "500 New Avenue", "city": "Winnipeg", "region": "MB", "postal_code": "R3C 1A1", "country": "CA" }
```

Omit it and the address defaults from `payee_contact_id`'s billing address.
Either way it is **snapshotted onto the cheque**, so editing the contact later
never changes a cheque already written, and it is what prints on the cheque. The
response echoes it back under `data.payee_address`. Updating the contact's own
record is a separate call to `/api/v1/vendors/{id}` or `/customers/{id}`.

Four more cheque rules, enforced by the same code path the web form uses:

- **`bank_account_id` must be a bank account** — an account whose subtype is `bank`.
  Any other account of yours is a `422`.
- **A line coded to Accounts Receivable or Accounts Payable names its contact.**
  `lines.*.contact_id` is the customer (AR) or vendor (AP) whose balance the line
  settles — a refund cheque, say — and it may differ from the header payee. It is
  required when the cheque is posted: on `POST /cheques` unless `"post": false`, and
  on `PATCH` of a cheque that is already posted. The response echoes it per line.
- **Those lines carry no tax.** A receivable or payable already includes the tax its
  invoice or bill recorded, so `tax_code_id` and `secondary_tax_code_id` on an AR/AP
  line pass validation (the id must still be one of yours) but are dropped: the line
  is saved with no tax code, `tax_cents` and `secondary_tax_cents` come back `0`, and
  the cheque's `amount_cents` includes no tax for that line. Nothing warns you — read
  the response back if you rely on the tax.
- **`cheque_no` is required and may repeat.** See [§2.2](#22-document-numbers).

Three consequences worth designing around:

- **`DELETE` is not idempotent in the usual sense.** On a posted document it writes
  a reversing entry and returns the resource; calling it again returns `409`
  (`"Invoice is already voided."`). Check the status code, not just success.
- **Repost support is per document type.** Invoices, credit memos, receipts, bills,
  bill payments, journal entries, deposits and — since 1.1.0 — cheques repost in
  place. Stock adjustments, transfers and tax-return payments do **not** — editing
  one after posting returns `409` (`"This posted document cannot be edited; void and
  recreate."`). Two more `409`s to expect: a voided document of any type can't be
  edited, and a journal entry another document owns (an invoice's, a bill's, …)
  can't be edited from `/journal-entries` — edit the source document instead.
- **A record open in the web app is off-limits.** While a member is editing an
  invoice, contact, account, … in LineLedger, writes to that record through the API
  return `423` with a `Retry-After` header. Reads and creates are never affected.

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

Or supply the document number yourself (`invoice_no`, `bill_no`, `deposit_no`, … —
[§2.2](#22-document-numbers)). Numbers are unique per organization, so a retry
that would create the document a second time fails with `422` on that field, and
that `422` tells you the first attempt went through.

---

## 6. Error reference

| Status | Meaning |
|---|---|
| `401` | Missing, unknown, or revoked API key. |
| `403` | The key is valid but lacks the required scope. |
| `404` | No such record **in this company** (also returned for a wrong-role contact). |
| `409` | The operation conflicts with the document's state — already voided, an edit to a posted document that can't repost, or a journal entry another document owns. |
| `422` | Validation failed, or the post was rejected (locked period, unbalanced, zero total, filed tax period). |
| `423` | Someone is editing the record in the web app right now. Wait `Retry-After` seconds and try again. |
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
- `lines.*.contact_id` missing or wrong-role on a line coded to the Accounts
  Receivable / Accounts Payable control account (journal entries and cheques).
  Those lines move a customer's or vendor's sub-ledger, so AR requires a
  customer and AP a vendor. Checked only when the document is being posted —
  a cheque sent with `"post": false` may leave it out.
- `sales_rep_id` points at a contact that isn't an employee (`is_employee = false`).
- A document number that already exists in the organization (`invoice_no`,
  `bill_no`, `deposit_no`, … — [§2.2](#22-document-numbers)).
- `bank_account_id` on a cheque that isn't a `bank`-subtype account.
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

### Being edited (`423`)

LineLedger lets one person edit a record at a time. While a member has a record
open in an edit form or edit dialog in the web app, the API refuses to change it:

```http
HTTP/1.1 423 Locked
Retry-After: 87
Content-Type: application/json

{"message": "This invoice is being edited by someone else in LineLedger. Try again shortly."}
```

- **`Retry-After`** is the number of seconds left on the web user's current lease
  (at least 1, at most 120 with the default `EDIT_LOCKS_TTL_SECONDS`). Their open
  page renews the lease while they work, so the record may still be busy when it
  runs out.
- **The message names the kind of record** (invoice, customer, vendor, employee,
  account, …) and never the person editing it.
- **Affected:** `PATCH`/`PUT` (update), `DELETE`, and every `POST /{resource}/{id}/{action}`
  (`post`, `fulfill`, `cancel`, `refund`, `file`, `void`) on every resource in
  [§3](#3-the-resource-map) **except** `bank-reconciliations`, `stock-adjustments` and
  `tax-return-payments`, which edit locks don't cover and which never return `423`.
  (A member can be working on a bank reconciliation in the web app at the same time
  as your request — there the last write wins.)
- **Never affected:** `GET` requests and creates (`POST /{resource}`). A new receipt
  applied to an invoice that someone is editing still succeeds.
- **A wrong-role contact is a `404`, not a `423`.** `PATCH /vendors/{id}` on a contact
  that is only a customer answers `404` even while someone is editing it — the lock
  never reveals that the record exists.
- **Nothing is written** when you get a `423` — the request can be sent again unchanged.
- **Your write invalidates a stale web page.** Once a request gets past the lock
  check, the record's edit version is replaced whether or not the write succeeds, so
  a member whose page loaded the record earlier can no longer save over it — they
  have to reopen it.
- **The operator can turn edit locks off** (`EDIT_LOCKS_ENABLED=false` on the server).
  Then nothing ever returns `423`, and the last write wins everywhere.

**What to do:** wait at least `Retry-After` seconds, then retry the same request a
limited number of times. Don't loop tightly on it — a person may keep the record
open for a while. If it's still locked after a few attempts, queue the change and
come back later, or surface it to an operator.

---

## 7. Things v1 does **not** do

Intentional omissions — call them out if a coder asks.

- **No bulk endpoints.** One document per request.
- **No idempotency keys.** Retrying after a network error may create duplicates —
  see [§5.4](#54-reconcile-what-youve-sent) for the search-then-create pattern, or
  supply your own document number, which is unique per organization and turns the
  duplicate into a `422`.
- **No webhooks.** Payment state changes won't push to you; poll the relevant
  index endpoint with a `from` filter.
- **No cross-company access.** One key, one company, always. To integrate with
  several organizations, mint a key per organization.
- **No key expiry.** Keys live until revoked.
- **No way to override a web edit.** An API key can't take over a record someone is
  editing in the web app, and it can't see who is editing — only an Owner or Admin
  in the web app can take over. Retry after the `423`'s `Retry-After`.
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
| `sales_rep_id` | `contacts` | `/api/v1/employees`, or the **Employees** page in the web UI — edit an employee and read **Contact ID (API)**. Only a contact with `is_employee` qualifies; a customer id here is a validation error. |

All of these are scoped per company; an ID is only valid for the
company that owns the API key making the call.
