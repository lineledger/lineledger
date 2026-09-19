# Upgrading LineLedger

The operator's guide to moving a self-hosted LineLedger from one release to the next.
`README.md` covers installing and running it, `CHANGELOG.md` says what changed in each
release, and this file says what you have to *do* about it. One section per release,
newest first.

## How upgrades work

- **Read two things before you start:** the release's entry in `CHANGELOG.md`, and its
  section in this file. The section lists every step that is not automatic.
- **Back up first, every time.** A release can carry a migration that cannot be undone,
  and restoring a backup is the only supported way back. How to take one is under each
  release's *Before you start*.
- **One release at a time.** Go 1.0.0 → 1.1.0 → 1.2.0, running each release's steps in
  turn. Skipping a release skips its backfills and its behaviour notes.
- **Docker tags.** The image is `ghcr.io/lineledger/lineledger`. `1.1.0` is one exact
  release; `1.1` follows the newest 1.1.x patch; `latest` follows the newest tagged
  release; `edge` follows `main` between releases. Release tags are built for
  linux/amd64 and linux/arm64; `edge` and other `main` builds are amd64 only. Pin
  `LINELEDGER_VERSION` in `.env` to the exact release, so an upgrade happens when you
  change that line and not whenever a `docker compose pull` happens to run.
- **`php artisan app:upgrade` is the post-deploy step, everywhere.** It runs the pending
  migrations, then every backfill the release needs (for 1.1.0:
  `banking:backfill-line-memos` and `banking:backfill-reconciliation-stamps`). Each
  backfill is idempotent, so a second run changes nothing. `--dry-run` runs the same
  steps without writing anything; `--verify` ends with an `integrity:check` that reports
  but never emails; `--company=<id-or-slug>` limits the backfills to one organization.
  The Docker entrypoint runs it on every boot of the `app` container unless
  `MIGRATE_ON_BOOT=false`; the Forge deploy script calls it where `migrate --force`
  used to be. Run it by hand with `--verify` after every upgrade.
- **Never rotate `APP_KEY` or `PASSKEYS_USER_HANDLE_SECRET`** as part of an upgrade.
  `APP_KEY` decrypts every stored secret and session; `PASSKEYS_USER_HANDLE_SECRET`
  anchors every registered passkey. Both stay exactly as they were, and both are in
  every backup you take.

## 1.0.0 → 1.1.0

Released 2026-09-18. 52 commits since 1.0.0; PHP `^8.5` and Laravel 13 are unchanged
(`laravel/framework` moves to 13.32 with a dependency refresh). 21 migrations, two
backfills, one rewritten config file, and a handful of behaviours that are on by default.
No env var was renamed, removed or made required.

### Before you start

Take a full backup: a database dump **with triggers**, the storage volume or directory,
and `.env`. Two reasons this release insists on it:

- One migration is one-way. `allow_duplicate_cheque_numbers` drops the unique index on
  cheque numbers; once anyone reuses a number ("EFT", "DD"), that index can never be
  recreated, so the dump is the only route back to 1.0.0.
- The audit log's immutability triggers are part of the schema. A dump taken without
  `--triggers` restores a ledger whose history can be edited.

**Docker** — `docker/backup.sh` writes three timestamped files into `./backups` (or
`BACKUP_DIR`): the dump (`--single-transaction --triggers --routines`, gzipped), a tar of
the storage volume, and a copy of `.env`. The Docker path below fetches and runs it.

**Forge / bare metal** — from the site directory:

```bash
mysqldump --single-transaction --triggers --routines -u root -p lineledger \
    | gzip > ~/lineledger-pre-1.1.0.sql.gz
tar czf ~/lineledger-storage-pre-1.1.0.tar.gz storage/
cp .env ~/lineledger-env-pre-1.1.0 && chmod 600 ~/lineledger-env-pre-1.1.0
```

Dump as MySQL `root`: the triggers carry a definer, and restoring them needs a privileged
user. S3 buckets (attachments, logos, backups) sit outside `storage/` and are untouched.

### Docker

```bash
cd lineledger                   # the directory holding docker-compose.yml and .env
BASE=https://raw.githubusercontent.com/lineledger/lineledger/v1.1.0/docker
curl -fsSLO $BASE/docker-compose.yml          # 1.1.0 compose file: MIGRATE_ON_BOOT, backup profile
curl -fsSLO $BASE/backup.sh && curl -fsSLO $BASE/restore.sh && chmod +x backup.sh restore.sh
./backup.sh                     # dump + storage volume + .env into ./backups

# In .env, set LINELEDGER_VERSION=1.1.0 (it ships commented out: uncomment it).
docker compose pull && docker compose up -d
docker compose logs -f app      # app:upgrade runs on boot; wait for "LineLedger ready (role: app)"
docker compose exec app php artisan app:upgrade --verify
```

The `app` container's entrypoint runs `app:upgrade` (migrations, then both backfills)
before it starts serving, and the `queue` and `scheduler` containers wait for it to be
healthy, so the workers never run 1.1.0 code against a 1.0.0 schema. Recreating the
containers is the queue restart. The closing `app:upgrade --verify` finds nothing left
to migrate or backfill; it is there for the integrity check (see *Scheduler*).

To run the upgrade step yourself — to see `--dry-run` first, or to keep the boot short —
set `MIGRATE_ON_BOOT=false` in `.env` before `up -d`. The container then boots without
touching the database and prints the command to run:

```bash
docker compose exec app php artisan app:upgrade --dry-run
docker compose exec app php artisan app:upgrade --verify
```

On a 1.0.0 database the dry run lists the 21 pending migrations and reports the two
backfills as running after them: it can only preview them once the schema is current.

Do it straight away: with `MIGRATE_ON_BOOT=false` the site serves 1.1.0 code against the
old schema until you do.

Building locally instead of pulling: `git fetch --tags && git checkout v1.1.0`, then from
`docker/`: `./backup.sh && docker compose build && docker compose up -d`. arm64 hosts
that built locally because the image was amd64-only can go back to pulling.

### Forge / bare metal

1. **Back up** (above).
2. **Resolve any local edit to `config/cheque.php`.** The file is rewritten in 1.1.0 and
   `git pull` is the deploy script's second line; a conflict there aborts the deploy with
   the old code still live. `git stash` your copy (or `git checkout -- config/cheque.php`),
   deploy, then re-measure from the new file — see *Cheque layout*.
3. **Optional, large installs: maintenance mode.** Seven migrations add foreign keys or
   indexes: three ALTERs on `bank_statement_lines`, three on `cheques`, one on
   `cheque_lines`. On MySQL each index build holds a metadata lock, and on a table with
   hundreds of thousands of rows the migrate step can stall writes for minutes. From the
   site directory: `php artisan down --render=errors::503`, then `php artisan up` after
   step 6. Small installs finish in seconds and can skip this.
4. **Update the deploy script** (Forge → Site → Deployment). One line changes:
   `migrate --force` becomes `app:upgrade`.

   ```bash
   cd $FORGE_SITE_PATH
   git pull origin $FORGE_SITE_BRANCH
   $FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader
   npm ci && npm run build
   $FORGE_PHP artisan app:upgrade
   $FORGE_PHP artisan storage:link
   $FORGE_PHP artisan config:cache
   $FORGE_PHP artisan route:cache
   $FORGE_PHP artisan view:cache
   $FORGE_PHP artisan queue:restart
   ```

   Every line is load-bearing this release. `npm ci && npm run build`: four new
   JavaScript modules (edit-lock heartbeat, Escape-back, in-cell calculator, date picker)
   are imported by `app.js`, and a 1.0.0 `public/build` breaks all four. `config:cache`:
   `config/edit_locks.php` is new, and a cached 1.0.0 config has no `edit_locks` key.
   `queue:restart`: five queued job classes changed, and a long-running `queue:work`
   keeps the old ones in memory.
5. **Deploy Now.**
6. **Verify**, from the site directory:

   ```bash
   php artisan up                    # if you took it down
   php artisan app:upgrade --verify  # nothing left to migrate or backfill; runs the check
   php artisan schedule:list         # edit-locks:prune should be listed, daily
   ```

### What the migrations do

All 21 add columns or tables; none moves or deletes data. **Slow** marks the index and
foreign-key builds that hold locks on large tables; **irreversible** is why the backup is
not optional.

| Migration | Change |
| --- | --- |
| `2026_09_01_000001_create_opening_balance_states_table` | New table: one row per organization for the Opening balances workspace |
| `2026_09_01_000002_create_opening_balance_rows_table` | New table: the workspace's draft trial-balance targets, one per account |
| `2026_09_01_000003_add_is_opening_balance_to_credit_memos_table` | `credit_memos.is_opening_balance` (default false) + index |
| `2026_09_01_000004_add_is_opening_balance_to_vendor_credits_table` | `vendor_credits.is_opening_balance` (default false) + index |
| `2026_09_01_000005_add_is_opening_balance_to_cheques_table` | `cheques.is_opening_balance` (default false) + index; first of three ALTERs on `cheques` |
| `2026_09_01_000006_add_is_opening_balance_to_deposits_table` | `deposits.is_opening_balance` (default false) + index |
| `2026_09_01_000010_add_hide_zero_qty_lines_to_invoice_settings` | `invoice_settings.hide_zero_qty_lines`, default off, so printed invoices are unchanged |
| `2026_09_01_000011_add_comparison_basis_to_report_packages_table` | `report_packages.comparison_basis`, default `off`, so existing packages render as before |
| `2026_09_01_000012_add_cash_flow_activity_to_report_group_lines_table` | Nullable `report_group_lines.cash_flow_activity` override for combined cash flow statements |
| `2026_09_03_000001_add_show_daily_insights_to_users_table` | `users.show_daily_insights`, **default ON** — every user starts seeing the daily insight card |
| `2026_09_03_000010_add_payee_suggestions_to_bank_statement_lines_table` | **Slow.** Two FKs (`suggested_contact_id`, `suggested_bill_id`) + `suggestion_source` + index on `bank_statement_lines` |
| `2026_09_03_000011_add_action_contact_id_to_bank_rules_table` | `bank_rules.action_contact_id` FK (rules can set the payee) |
| `2026_09_04_000001_add_suggested_tax_codes_to_bank_statement_lines_table` | **Slow.** Two more FKs on `bank_statement_lines` (`suggested_tax_code_id`, `suggested_secondary_tax_code_id`) |
| `2026_09_04_000002_add_suggested_bill_allocations_to_bank_statement_lines_table` | **Slow.** Nullable JSON `suggested_bill_allocations` on `bank_statement_lines`; third touch of that table |
| `2026_09_04_000020_add_is_other_name_to_contacts_table` | `contacts.is_other_name` (default false) + index; no existing contact changes role |
| `2026_09_04_000030_add_day_anchor_to_recurring_schedules` | `day_anchor` on `recurring_documents`, `recurring_journal_entries`, `report_email_schedules`; default keeps every schedule as it was |
| `2026_09_10_000001_allow_duplicate_cheque_numbers` | **Irreversible.** Adds a plain index on `cheques (company_id, bank_account_id, cheque_no)`, then drops the unique one; `payroll_cheques` keeps its unique index |
| `2026_09_12_000001_add_contact_id_to_cheque_lines_table` | `cheque_lines.contact_id` FK; existing AR/AP cheque lines stay unattributed until the cheque is re-saved |
| `2026_09_12_000002_add_payee_address_to_cheques_table` | Six nullable `payee_*` address columns on `cheques`; old cheques print the payee's current address |
| `2026_09_12_000003_create_edit_locks_table` | New table: edit leases; excluded from organization backups; swept by `edit-locks:prune` |
| `2026_09_17_000001_add_escape_goes_back_to_users_table` | `users.escape_goes_back`, **default ON** — Escape navigates back for every user |

### The backfills

`app:upgrade` runs both, for every organization, after the migrations. Both work outside
a tenant context and both are idempotent.

**`banking:backfill-line-memos [--dry-run]`** — the bank leg of every posted deposit,
cheque, expense, transfer, receipt, sales receipt, bill payment, tax-return payment and
payroll remittance was written with a bare label ("Deposit", "Cheque 2001"); 1.1.0 posts
"Deposit: August rent", and the backfill rewrites the old rows to match. It only touches
a memo still exactly equal to the old label, on the document's own bank-account line, so
hand-edited memos and every other leg are left alone; amounts are untouched and nothing
reposts. **If skipped:** rows posted before 1.1.0 keep the bare label. Cosmetic.
`--dry-run` prints how many memos each organization would rewrite ("would rewrite N bank
line memo(s)") without writing them. `app:upgrade --dry-run` passes it through once the
schema is current; while migrations are still pending it lists them and reports the
backfills as running after them.

**`banking:backfill-reconciliation-stamps [--dry-run]`** — a service-charge or interest
adjustment that a reconciliation replaced mid-edit, or left unticked, kept the cleared
stamp it was posted with. The backfill un-clears those lines (`cleared_at` and the
reconciliation id only) on completed reconciliations. **If skipped:** the register's
cleared balance stays permanently off from the reconciled balance, and the replaced
charge's reversal reappears as a phantom deposit on the next reconciliation. A
reconciliation whose ticked lines no longer line up with its stamped ones (typically
after a restored backup, which renumbers lines but not the ticked list) is left alone and
printed as **`Skipped reconciliation #N`**; `app:upgrade` does not stop on it, but each
one needs a person to open that reconciliation and check it.

### Behaviour changes that are on by default

**Edit locks.** Opening a record to edit takes a short lease on it. Other members see who
is editing instead of the form; show-page actions (void, post, delete) and list row
actions wait; Owners and Admins can take over. Settings live in `config/edit_locks.php`
and are read from `.env`; the 1.0.0 `.env.example` files do not list them, so add the
ones you want by hand.

| Key | Default | Meaning |
| --- | --- | --- |
| `EDIT_LOCKS_ENABLED` | `true` | **Kill switch.** `false` makes every lock call a no-op and restores 1.0.0 behaviour, API included |
| `EDIT_LOCKS_TTL_SECONDS` | `120` | Lease length without a heartbeat; keep it above 60, since hidden tabs renew about once a minute |
| `EDIT_LOCKS_HEARTBEAT_SECONDS` | `30` | How often an open edit page renews |
| `EDIT_LOCKS_IDLE_MINUTES` | `15` | An idle page stops renewing after this, so an overnight tab does not hold a record |
| `EDIT_LOCKS_PRUNE_AFTER_DAYS` | `7` | Released rows untouched this long are deleted by `edit-locks:prune` |

Changing any of them needs `php artisan config:cache` (Forge) or `docker compose up -d`
(Docker re-caches config on boot).

**Escape goes back.** On any signed-in page, Escape does what the browser's Back button
does; an open modal, dropdown or picker closes first, and a form with unsaved typing asks
first. Per user, on by default: Settings → Appearance → "Escape goes back".

**Daily insight card.** The dashboard shows the organization's daily insight, and the
sidebar gains an Insights link. Per user, on by default: the Insights page has "Show
daily insights on my dashboard".

**Cheque date comb.** Printed cheques date the comb YYYYMMDD (Payments Canada Standard
006) with a matching Y Y Y Y M M D D legend; 1.0.0 printed MMDDYYYY. Both come from one
key, `date_comb_format` (`'Ymd'`), so the digits and legend cannot disagree. Pre-printed
stock legended the other way needs `'mdY'` — see *Cheque layout* for how to change it.

### Cheque layout

`config/cheque.php` was re-measured end to end against Intuit voucher stock. If you never
edited it, the new file is the whole point: output lands on pre-printed stock without
adjustment. If you did edit it, start again from the new file; nothing carries over.

- Every `fields` entry is now `[x, baseline]`, not `[x, top]`, and every value moved. Old
  coordinates pasted into 1.1.0 print misaligned by about a glyph height.
- Removed: `amount_words_pad_width`. Replaced by `amount_words_star_prefix` (5), which
  means something different: a fixed five stars before the amount, not a fill to the
  margin. A customised pad width is silently ignored.
- Changed: `date_digit_pitch` 14 → 11.142, `date_subscript_drop` 18 → 9.9,
  `date_label_offset` −36 → −31.98, `voucher_line_height` 14 → 18, and every font size
  (`size_body` 10.02, `size_date_comb` 10.02, `size_label` 7.98, `size_subscript` 6).
- New: `date_comb_format`, `date_label_drop`, `date_subscript_x_offset`, `size_payee`,
  the `address_*` payee block, the `cheque_payee_address` field, and a `columns` block.
  Voucher text is now hard-truncated at its column width, mid-word, with no ellipsis, so
  a long account name or memo no longer overprints the column beside it.

There is no env override and nothing to publish: the file is code. **Forge:** edit it in
the checkout after the deploy and re-measure; expect the same conflict at the next
release. **Docker:** the file is baked into the image, so build locally with your edit or
bind-mount it over `/app/config/cheque.php` on the `app` service. Print one test cheque
before a cheque run.

### Scheduler

Nothing to configure. `edit-locks:prune` is registered in `routes/console.php`, so the
existing every-minute `schedule:run` cron (Forge) or the `scheduler` container (Docker)
picks it up; `schedule:list` shows it as daily.

`integrity:check` (nightly at 04:00, unchanged) grows from three checks to five, and the
first run after the upgrade will email `LEDGER_INTEGRITY_ALERT_EMAIL` about conditions
that predate it:

- **Check 4, invoice/bill paid-cache drift.** A receipt or bill payment moved between
  documents under 1.0.0 left the old document's cached paid amount stale. Heal it with
  `php artisan integrity:check --fix`, which reposts through the payment posters and
  lists what changed.
- **Check 5, tax on an AR/AP cheque line.** Report-only. Cheque lines coded to Accounts
  Receivable or Payable could carry sales tax under 1.0.0, double-counting it. History is
  not rewritten; the repair is to open each named cheque and save it, which reposts it
  under the audit chain with the tax stripped.

Run `app:upgrade --verify` (or `integrity:check --no-alert`) by hand first, so the 04:00
email is not a surprise.

### API changes for integrators

Send integrators the updated `docs/api-v1.md` and `resources/api/openapi.yaml` (spec
`version: 1.1.0`). In order of how likely each is to break a client:

- **`423 Locked` with `Retry-After`.** `PATCH`/`PUT`, `DELETE` and every
  `POST /{resource}/{id}/{action}` (`post`, `void`, `fulfill`, `cancel`, `refund`,
  `file`) answer 423 while a member has that record open in the web app. An API key has
  no user, so any live lock refuses it. Nothing is written; retry after `Retry-After`
  seconds (1 to 120 by default). Exempt: `bank-reconciliations`, `stock-adjustments` and
  `tax-return-payments` never return 423, nor do `GET`s or creates, and a contact reached
  through the wrong role route still 404s rather than 423s. `EDIT_LOCKS_ENABLED=false`
  restores 1.0.0 behaviour.
- **Negative invoice lines.** `POST`/`PATCH /invoices` accept a negative
  `unit_price_cents` (quantity stays positive). A total that nets to zero or a credit is
  refused with a 422 pointing at credit memos. A revenue leg that nets negative posts as
  a debit rather than a negative credit.
- **New fields:** `balance_cents` on invoices and `unapplied_cents` on receipts. Clients
  computing `total_cents - amount_paid_cents` overstate the balance on a partly
  reconciled invoice and should switch.
- **`sales_rep_id`** is accepted on create and update of invoices and credit memos and
  returned on both; 1.0.0 silently dropped it. It is the contact id of an employee in
  the key's organization, now shown on the Employees page as "Contact ID (API)".
- **`PATCH /cheques/{id}` reposts a posted cheque** instead of refusing. Closed-period,
  filed-return and completed-reconciliation guards apply to both the old and the new
  date; only a voided cheque stays frozen. The 1.0.0 spec said otherwise.
- **Tax on AR/AP cheque lines is stripped.** A tax code, tax cents or override sent on a
  cheque line coded to a receivable or payable control account is dropped, no error.
- **Document numbers are saved on update** across invoices, estimates, sales orders,
  credit memos, sales receipts, customer receipts, bills, bill payments, purchase orders,
  vendor credits and deposits; 1.0.0 returned success and kept the old number. A
  duplicate within the organization is now a 422 rather than a constraint 500.
- **Cheque numbers may repeat** within a bank account. A client that relied on a
  constraint error to detect a duplicate gets a successful create; the API does not warn.
- **`/transfers` is now in the spec.** The endpoint existed; the OpenAPI file omitted it.

### Rolling back

Rollback is a restore of the pre-upgrade backup onto 1.0.0 code. Re-pinning the old tag
on its own runs 1.0.0 against a 1.1.0 schema, and **never `migrate:rollback` a live
ledger**: the `down()` methods drop columns and tables holding data written since, and
`allow_duplicate_cheque_numbers` cannot be rolled back at all once a number is repeated.
Anything entered after the backup is lost either way.

**Docker:**

```bash
docker compose stop app queue scheduler
# In .env: LINELEDGER_VERSION=1.0.0
./restore.sh backups/lineledger-db-<ts>.sql.gz backups/lineledger-storage-<ts>.tar.gz
```

`restore.sh` drops and recreates the database, loads the dump as root (the triggers need
it), replaces the storage volume, and starts the stack. Its last step, `app:upgrade
--verify`, does not exist in 1.0.0 and reports an error after the restore has already
completed; run `docker compose exec app php artisan integrity:check` instead. If `.env`
changed since the backup, copy the `lineledger-env-<ts>` file back first.

**Forge / bare metal**, from the site directory:

```bash
php artisan down
git checkout v1.0.0                 # detached; git checkout main before your next deploy
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
npm ci && npm run build
mysql -u root -p -e 'DROP DATABASE lineledger; CREATE DATABASE lineledger;'
gunzip -c ~/lineledger-pre-1.1.0.sql.gz | mysql -u root -p lineledger
tar xzf ~/lineledger-storage-pre-1.1.0.tar.gz
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan queue:restart && php artisan up
```

### After the upgrade

- **`failed_jobs`.** Under 1.0.0 the nightly per-company jobs failed for every
  soft-deleted organization and paged ops hourly; 1.1.0 stops that, but the dead rows
  are still there. Look with `php artisan queue:failed`, then clear them with
  `php artisan queue:prune-failed --hours=0` (Docker: prefix `docker compose exec app`).
- **`schedule:list`** should show `edit-locks:prune` (daily) alongside `integrity:check`
  (04:00). If the scheduler was already dead, `edit_locks` rows now accumulate too.
- **The footer shows the running version**, linking to the GitHub release. If it still
  reads 1.0.0, the deploy did not take.
- **Docker: nightly dumps.** `docker compose --profile backup up -d` starts a sidecar
  that writes `lineledger-db-<ts>.sql.gz` (triggers included) every
  `BACKUP_INTERVAL_SECONDS` (default 86400) into `BACKUP_DIR` (default `./backups`, a
  host folder that `docker compose down -v` cannot delete) and prunes dumps older than
  `BACKUP_KEEP_DAYS` (default 14). Database only; `backup.sh` covers the storage volume.
- **Decide on edit locks** before a second person opens a record the first one is
  editing, and tell integrators about the 423 before they meet it in production.
- **Organization settings** gain an "AI assistant writes (MCP)" switch. It stays off
  unless an owner turns it on, and does nothing unless the operator also sets
  `MCP_WRITE_ENABLED=true`.
