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
- **One release at a time.** Go 1.0.0 → 1.1.0 → 1.2.0 → 1.3.0, running each release's
  steps in turn. Skipping a release skips its backfills and its behaviour notes.
- **Docker tags.** The image is `ghcr.io/lineledger/lineledger`. `1.3.0` is one exact
  release; `1.3` follows the newest 1.3.x patch; `latest` follows the newest tagged
  release; `edge` follows `main` between releases. Release tags are built for
  linux/amd64 and linux/arm64; `edge` and other `main` builds are amd64 only. Pin
  `LINELEDGER_VERSION` in `.env` to the exact release, so an upgrade happens when you
  change that line and not whenever a `docker compose pull` happens to run.
- **`php artisan app:upgrade` is the post-deploy step, everywhere.** It runs the pending
  migrations, then every backfill the release needs (since 1.1.0:
  `banking:backfill-line-memos` and `banking:backfill-reconciliation-stamps`; 1.2.0 through
  1.3.0 add none). Each
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

## 1.2.2 → 1.3.0

Released 2026-10-22. A minor release that adds a one-click install on a DigitalOcean
Droplet (`docker/digitalocean/droplet/install.sh` and a Marketplace image; see the
README) and lets site admins set up outgoing email in the app (Admin → Email). It also
changes what happens when mail isn't set up yet, and where alerts with a blank address
go. PHP `^8.5`, Laravel 13 and the dependencies are unchanged. **No migrations, no
backfills, no config or env changes**, and `docker-compose.yml`, `backup.sh` and
`restore.sh` are unchanged from 1.2.2. Coming from 1.2.1 or earlier, do the earlier
steps below first.

### Before you start

Take a backup as usual. Docker: `./backup.sh`. Forge / bare metal: the `mysqldump` and
`storage/` commands under 1.1.0 → 1.2.0, with `1.3.0` in the filenames.

### Docker

```bash
./backup.sh
# In .env, set LINELEDGER_VERSION=1.3.0 (or 1.3).
docker compose pull && docker compose up -d
docker compose exec app php artisan app:upgrade --verify
```

**The `1.2` tag stays on 1.2.2.** If `.env` pins `LINELEDGER_VERSION=1.2`, change it to
`1.3.0` (or `1.3`, which follows the 1.3.x patches). `latest` moves to 1.3.0 by itself.

### DigitalOcean App Platform

Run `./deploy.sh` again. The app follows the `latest` image tag, so the redeploy picks up
1.3.0 and the pre-deploy job runs `app:upgrade`.

### Forge / bare metal

Deploy with the unchanged deploy script, then run `php artisan app:upgrade --verify` and
`php artisan queue:restart`.

### Behaviour to know about

- **Mail settings saved in Admin → Email override `MAIL_*`.** Nothing changes until a
  site admin saves settings there. Once saved, the app sends through that SMTP server
  whatever `.env` says, and saving restarts the queue workers so they pick it up. Remove
  the saved settings on the same page to go back to `MAIL_*`.
- **SMTP with no host now writes mail to the log instead of failing.** With
  `MAIL_MAILER=smtp` and `MAIL_HOST` blank, every email used to fail the request or job
  that sent it. Now each one is written to the log, and site admins see a banner until
  mail is set up. Installs with a working mailer, or with `MAIL_MAILER=log`, behave as
  before. On a brand-new install in this state, the first account, which becomes the
  site admin, signs in without verifying its address; every later account still
  verifies.
- **Alerts whose address is set but blank now go to the site admins.** This covers
  `SECURITY_ALERT_EMAIL`, `LEDGER_INTEGRITY_ALERT_EMAIL`, `OPS_ALERT_EMAIL` and
  `EXCHANGE_RATE_HEALTH_ALERT_EMAIL`: the security, ledger-integrity, failed-job,
  scheduled-task and exchange-rate alerts. They used to be skipped; now each one is
  emailed to every active site admin. If you blanked an address to silence those
  alerts, they will start arriving. Set it to an address that should get them instead.
  An unset variable still falls back as before.

### Rolling back

1.3.0 has no migrations, so going back to 1.2.2 is a matter of deploying it again: set
`LINELEDGER_VERSION=1.2.2`, or check out `v1.2.2`. Settings saved in Admin → Email stay
in the database, where 1.2.2 ignores them, and mail goes back to the `MAIL_*` values.

## 1.2.1 → 1.2.2

Released 2026-10-22. A patch release that adds a one-command deploy to DigitalOcean App
Platform (`docker/digitalocean/deploy.sh`; see the README), makes backup restores and
QuickBooks ledger imports work when the queue worker runs on a different machine from the
web server, and brings five usability fixes (AR/AP aging and the bank register remember
their filters, the last bank account survives signing out, suggested cheque numbers skip
labels like `e-transfer`, and Reconcile waits for a statement that is still uploading).
PHP `^8.5`, Laravel 13 and the dependencies are unchanged. **Two migrations, no
backfills, no config or env changes**, and `docker-compose.yml`, `backup.sh` and
`restore.sh` are unchanged from 1.2.1. Coming from 1.2.0 or 1.1.0, do the earlier steps
below first.

### Before you start

Take a backup as usual. Docker: `./backup.sh`. Forge / bare metal: the `mysqldump` and
`storage/` commands under 1.1.0 → 1.2.0, with `1.2.2` in the filenames.

### Docker

```bash
./backup.sh
# In .env, set LINELEDGER_VERSION=1.2.2 (or stay on 1.2, which moves to 1.2.2 by itself).
docker compose pull && docker compose up -d
docker compose exec app php artisan app:upgrade --verify
```

The entrypoint runs the two migrations on boot unless `MIGRATE_ON_BOOT=false`.

### Forge / bare metal

Deploy with the unchanged deploy script, then run `php artisan app:upgrade --verify`
(it applies the two migrations and runs the integrity check) and
`php artisan queue:restart`.

### What the migrations do

- **`user_preferences`** (new table): the last bank account each user picked, per
  organization. It used to live in the session, so each user picks their account once
  more after the upgrade.
- **`company_restores.disk`** (new column, default `local`): which disk an uploaded
  restore bundle is waiting on. Existing rows are on the local disk, which is what the
  default says.

### Behaviour to know about

- **Restore bundles and QuickBooks ledger uploads now wait on the backups disk.** If
  `BACKUP_DISK` is `local` (the default), nothing changes. If it points at a bucket,
  these uploads now land in that bucket under `restores/` and `migrations/` instead of in
  `storage/app/private/`, so a queue worker on another machine can read them. Ledger
  uploads are deleted once the import finishes; restore bundles are kept, as before.

### Rolling back

Both migrations only add, so going back to 1.2.1 is a matter of deploying it again: set
`LINELEDGER_VERSION=1.2.1`, or check out `v1.2.1`. No restore is needed. Finish any
restore or QuickBooks ledger import in progress first: 1.2.1 looks for those uploads on
the local disk.

## 1.2.0 → 1.2.1

Released 2026-10-19. A patch release: it fixes OFX, QFX and QBO bank-statement uploads
(Banking → Import rejected real bank files as the wrong type) and takes the October
dependency updates, including `laravel/framework` 13.37 and `smalot/pdfparser` 2.13.1,
which hardens PDF statement parsing against crafted files. PHP `^8.5` and Laravel 13 are
unchanged. **No migrations, no backfills, no config or env changes**, and
`docker-compose.yml`, `backup.sh` and `restore.sh` are unchanged from 1.2.0. Coming from
1.1.0, do the 1.1.0 → 1.2.0 steps below first.

### Before you start

Take a backup as usual. Docker: `./backup.sh`. Forge / bare metal: the `mysqldump` and
`storage/` commands under 1.1.0 → 1.2.0, with `1.2.1` in the filenames.

### Docker

```bash
./backup.sh
# In .env, set LINELEDGER_VERSION=1.2.1 (or stay on 1.2, which moves to 1.2.1 by itself).
docker compose pull && docker compose up -d
docker compose exec app php artisan app:upgrade --verify
```

### Forge / bare metal

Deploy with the unchanged deploy script. `composer install` and
`npm ci && npm run build` both pick up updated dependencies, so keep those lines. Then run
`php artisan app:upgrade --verify` (nothing to migrate; it runs the integrity check) and
`php artisan queue:restart`.

### Rolling back

Nothing in the database changed, so going back to 1.2.0 is just a matter of deploying it
again: set `LINELEDGER_VERSION=1.2.0`, or check out `v1.2.0`. No restore is needed.

## 1.1.0 → 1.2.0

Released 2026-10-18. 31 commits since 1.1.0; PHP `^8.5` and Laravel 13 are unchanged
(`laravel/framework` moves to 13.35 with a dependency refresh), and `ext-intl` is now a
declared requirement. 10 migrations, one of which rewrites data and cannot be undone; no
new backfills; no config file rewritten. No env var was renamed, removed or made
required. The headline changes — French, United Kingdom organizations, tax-return
adjustments and reconciliation, overpayment refunds, and the warn-or-block lock date —
are in `CHANGELOG.md`.

### Before you start

Take a full backup exactly as for 1.1.0 (below): a database dump **with triggers**, the
storage volume or directory, and `.env`. Docker: `./backup.sh`. Forge / bare metal:

```bash
mysqldump --single-transaction --triggers --routines -u root -p lineledger \
    | gzip > ~/lineledger-pre-1.2.0.sql.gz
tar czf ~/lineledger-storage-pre-1.2.0.tar.gz storage/
cp .env ~/lineledger-env-pre-1.2.0 && chmod 600 ~/lineledger-env-pre-1.2.0
```

This release insists on it because `move_bc_and_yukon_companies_off_us_pacific_time`
changes existing organizations' time zones and its `down()` does nothing: the dump is
the only route back to 1.1.0.

Two checks on the host, bare metal only (the Docker image already passes both):

- **Time zone data.** British Columbia stays on UTC−7 from November 1, 2026. This must
  print `-07:00`; if it prints `-08:00`, update the system's tzdata package (and restart
  PHP-FPM) before upgrading, or BC organizations' "today" runs an hour behind all winter.

  ```bash
  php -r 'echo (new DateTime("2026-12-01", new DateTimeZone("America/Vancouver")))->format("P"), PHP_EOL;'
  ```

- **`ext-intl`.** `php -m | grep intl` must print `intl`. 1.1.0 already used it to format
  money; `composer install` now refuses to run without it.

### Docker

```bash
cd lineledger                   # the directory holding docker-compose.yml and .env
./backup.sh                     # dump + storage volume + .env into ./backups

# In .env, set LINELEDGER_VERSION=1.2.0.
docker compose pull && docker compose up -d
docker compose logs -f app      # app:upgrade runs on boot; wait for "LineLedger ready (role: app)"
docker compose exec app php artisan app:upgrade --verify
```

`docker-compose.yml`, `backup.sh` and `restore.sh` are unchanged from 1.1.0, so there is
nothing to download. `docker/.env.example` gains commented-out `APP_LOCALE`, `BRAND_*` and
`SUPPORT_ENABLED` lines; copy any you want into your `.env` (see *New settings*). With
`MIGRATE_ON_BOOT=false`, run `app:upgrade --dry-run` and then `app:upgrade --verify`
yourself, straight away, as for 1.1.0.

### Forge / bare metal

1. **Back up** and run the two host checks (above).
2. **Optional, large installs: maintenance mode.** Two migrations add two indexes each,
   to `customer_receipts` and `cheques`. On MySQL each index build holds a metadata lock;
   with hundreds of thousands of rows, writes can stall for a minute or more.
   `php artisan down --render=errors::503`, then `php artisan up` after step 4.
3. **Deploy Now.** The 1.1.0 deploy script is unchanged and every line still matters:
   `npm ci && npm run build` (the line-item column resizing is a new JavaScript module),
   `config:cache` (`config/brand.php` and `config/support.php` are new), and
   `queue:restart` (queued mail now renders in each recipient's language).
4. **Verify**, from the site directory:

   ```bash
   php artisan up                    # if you took it down
   php artisan app:upgrade --verify  # nothing left to migrate; runs the integrity check
   ```

### What the migrations do

**Slow** marks the index builds that hold locks on large tables; **irreversible** is why
the backup is not optional.

| Migration | Change |
| --- | --- |
| `2026_09_07_120000_add_locale_to_users_companies_contacts` | Nullable `locale` on `users`, `companies` and `contacts`. **Sets every existing organization's document language to `en`**; users stay unset (the staff app uses `APP_LOCALE` until they pick a language) and so do contacts (their documents use the organization's language) |
| `2026_09_21_000001_add_company_registration_number_to_companies_table` | Nullable `companies.company_registration_number` (the UK Companies House number) |
| `2026_09_22_000001_add_use_for_expenses_to_accounts_table` | `accounts.use_for_expenses`, default off ("Use to pay expenses" on a liability) |
| `2026_09_23_000001_create_tax_return_adjustments_table` | New table for a return's adjustments; `tax_returns` gains `other_adjustments_cents`, `adjustment_journal_entry_id` (FK) and a JSON `reconciliation` snapshot |
| `2026_10_05_000001_add_statement_columns_to_invoice_settings` | Nullable JSON `invoice_settings.statement_columns`; unset keeps the default statement columns |
| `2026_10_05_000002_move_bc_and_yukon_companies_off_us_pacific_time` | **Irreversible, rewrites data.** Canadian organizations in BC on `America/Los_Angeles` move to `America/Vancouver`, and Yukon ones to `America/Whitehorse`. A zone an owner picked themselves is left alone |
| `2026_10_14_000001_add_refund_links_to_customer_receipts_table` | **Slow.** `refunded_receipt_id` and `from_invoice_id` + two indexes on `customer_receipts` |
| `2026_10_14_000002_add_refund_links_to_cheques_table` | **Slow.** The same two columns + two indexes on `cheques` |
| `2026_10_15_000001_add_lock_mode_to_companies_table` | `companies.lock_mode`, default `block`, so the lock date behaves as before |
| `2026_10_16_000001_add_vat_boxes_to_tax_returns_table` | Nullable JSON `tax_returns.vat_boxes`: a UK return's nine boxes, frozen at filing |

### The backfills

None new. `app:upgrade` still runs the two 1.1.0 backfills, which find nothing left to do
on an install that has already run them. The two data changes in this release (document
language, BC and Yukon time) happen inside their migrations. A draft tax return saved
under 1.1.0 stores its figures for the list the next time it is saved; its own page works
them out live meanwhile.

### Behaviour changes that are on by default

**Time zones in BC and Yukon.** Organizations moved by the migration keep UTC−7 through
the winter instead of falling back with US Pacific time, so between November and March
"today", report stamps, payment reminders and scheduled report emails line up with local
time.

**Lock date: Block, with a password override.** Every organization keeps the Block mode it
effectively had, but an Owner or Admin who posts into a locked period is now offered a
one-transaction override after re-entering their password, written to the audit log as
`period.lock_overridden`. Close the books also offers **Warn and confirm**, which any
member can pass. The API, MCP and scheduled jobs are never offered an override.

**Reconciliation locks protect lines, not dates.** A new entry dated inside a reconciled
period now posts (it shows as uncleared on the next reconciliation); changing or voiding
anything a completed reconciliation ticked is refused, including through invoice, bill,
receipt and other document edits that 1.1.0 let through. The journal form warns before
posting into a reconciled period or a prior fiscal year.

**Paid and refunded documents are guarded.** An invoice can't be edited below what its
receipts applied, a credit memo can't be voided or edited below its refunds, and a
refunded payment can't be voided or moved to another customer until its refund is
removed.

**Home currency must have two decimals.** An organization already on JPY or another zero-
or three-decimal currency keeps it and can still save its other settings, but card
payments and multi-currency are off for it, and a backup holding one is refused on
restore. Nothing in its books is changed.

**Language.** Nothing changes for anyone until they pick French: existing organizations
are set to English, and the staff app follows each user's own setting (default
`APP_LOCALE`). A **new** Québec organization starts with French documents, and a new
organization elsewhere takes the language of the person creating it. Customer emails,
PDFs and the portal follow the customer's language, then the organization's.

**Smaller ones.** The organization switcher opens the chosen organization's Dashboard;
the browser tab title follows `APP_NAME` (set `APP_NAME="Line Ledger"` for the spaced
form); the Sales Tax report no longer counts a remittance as an input tax credit, so a
period containing one shows a lower Paid and a higher, correct Net.

### New settings

All optional; leaving them out keeps 1.1.0's behaviour. Run `php artisan config:cache`
(Forge) or `docker compose up -d` (Docker) after changing any.

| Key | Default | Meaning |
| --- | --- | --- |
| `APP_LOCALE` | `en` | Language of signed-out pages, and of documents when neither the customer nor the organization has one |
| `APP_REGION` | derived from the host | Now also accepts `GB`; a `.uk` host derives it. Picks the marketing site the legal links point at |
| `APP_URL_GB`, `MARKETING_URL_GB` | empty | The UK twin, for the project's own deployments; empty keeps it out of the country switcher |
| `BRAND_LOGO`, `BRAND_LOGO_DARK` | the LineLedger mark | Your logo on the sign-in, registration and onboarding screens; any path the browser can fetch |
| `BRAND_FOOTER_OWNER` | `Local Foundry Inc.` | The company named in the footer's copyright line |
| `BRAND_SHOW_PROJECT_LINKS` | `true` | `false` drops the upstream version, licence, source and legal links. Read the AGPL-3.0 §13 note in `config/brand.php` first |
| `SUPPORT_ENABLED` | `true` | `false` removes the Support entry and `/support`; existing tickets stay readable in the admin portal |

### Scheduler

Nothing to configure; no scheduled command was added or moved. The nightly
`integrity:check` (04:00) gains one check, **over-refunded receipts**: a payment with
more refunded than it left unapplied. Each finding names the receipt; remove a refund
or an application to clear it.

### API changes for integrators

Send integrators the updated `docs/api-v1.md` and `resources/api/openapi.yaml` (spec
`version: 1.2.0`). In order of how likely each is to break a client:

- **Filing an unreconciled tax return is refused.** `POST /tax-returns/{id}/file` answers
  422 when the return differs from its tax payable account, unless
  `accepted_difference_cents` names that exact difference. `POST /tax-returns/{id}/void`
  also reverses the adjustment entry filing posted.
- **`unapplied_cents` on receipts nets out refunds**, and a refund receipt reports 0
  rather than its negative amount. New on receipts: `refunded_cents`, `credit_memo_id`,
  `refunded_receipt_id`, `from_invoice_id`; on cheques: `refunded_receipt_id`,
  `from_invoice_id`.
- **New: `POST /invoices/{id}/refund`** pays a customer's overpayment back by card
  (a negative receipt) or as a draft refund cheque.
- **Tax returns take `adjustments`** on create and update. Omitting `adjustments` or
  `excluded_journal_line_ids` on a `PATCH` now keeps the saved values; 1.1.0 cleared the
  excluded lines.
- **Deposit lines accept a negative `amount_cents`** (a card refund or a fee); only 0 is
  refused.
- **Journal entries.** `DELETE` refuses a draft created by another document (422).
  `PATCH` on a posted entry checks both its old and new date against the lock date, and
  refuses one with a reconciled line.
- **Paid and refunded documents.** Edits that drop an invoice below its applied receipts,
  or a credit memo below its refunds, and voids of a refunded credit memo or payment,
  answer 422.
- **MCP connectors** now speak protocol revision 2026-07-28. Clients that `initialize`
  keep working; there is no `Mcp-Session-Id` header any more, and an unexpected error in
  a tool comes back as a tool error. Both servers report version 1.2.0.

The UK VAT boxes, the document language and the Companies House number are not exposed
in the API.

### Rolling back

As for 1.1.0: restore the pre-upgrade backup onto 1.1.0 code, and **never
`migrate:rollback` a live ledger**. Here it would not even get you back: the BC and Yukon
time zone move has no `down()`. Anything entered after the backup is lost.

**Docker:**

```bash
docker compose stop app queue scheduler
# In .env: LINELEDGER_VERSION=1.1.0
./restore.sh backups/lineledger-db-<ts>.sql.gz backups/lineledger-storage-<ts>.tar.gz
```

**Forge / bare metal:** the 1.1.0 steps below, with `git checkout v1.1.0` and the
`pre-1.2.0` files.

### After the upgrade

- **The footer shows 1.2.0.** If it still reads 1.1.0, the deploy did not take.
- **Check the time zone** under Settings → Organizations for any BC or Yukon
  organization: it should read America/Vancouver or America/Whitehorse.
- **Tell your users about French** if they need it: each person sets their language
  under Settings → Profile; an organization's document language is on its settings page,
  and a customer's on their record.
- **Self-hosts that rebrand** can now do it without a fork: see *New settings*.
- **Integrators** should be told about the 422 on filing before they meet it.

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
