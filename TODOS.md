# TODOS

## Deployment

### Stand up the US app deployment at books.lineledger.com

**What:** A second Forge site with its own database, queue worker, and scheduler.

**Why:** The app ships with a two-region model, and the country-switcher banner links
guests to `APP_URL_US`. Until that host exists the banner is a dead link.

**Context:** The application side is already done — `APP_REGION`, `APP_URL_CA`, and
`APP_URL_US` all ship in `.env.example` and the banner reads them. What's left is
purely the Forge site. Set `APP_URL=https://books.lineledger.com` and leave `APP_REGION` unset so
the region derives from the host (a `.ca` host resolves to Canada, anything else to the
US). It needs its own `DB_*`, its own Stripe webhook endpoint, and its own SSL. Canada
is the primary market, so this is optional-but-linked: if you'd rather defer it, point
`APP_URL_US` at the Canadian host instead so the banner degrades gracefully. Full env
table is in README → Environment.

**Effort:** M
**Priority:** P1
**Depends on:** None

### Point both marketing builds at one feature-requests database

**What:** Build the Astro site twice (`PUBLIC_REGION=ca` and `PUBLIC_REGION=us`) with
both deployments reading the same `DB_NAME`.

**Why:** The public feature-request board carries votes and IDs. Two regional databases
would let IDs collide and split votes across the two sites.

**Context:** One shared database keeps IDs unique and votes unified. This lives in the
`lineledger-site` repo, which already has CI covering both region builds.

**Effort:** S
**Priority:** P1
**Depends on:** None

### Move uploads and backups to object storage on the hosted deployments

**What:** Create the two S3 buckets, then set `ATTACHMENT_DISK=s3`, `LOGO_DISK=s3_public`,
and `BACKUP_DISK=s3` on each hosted site.

**Why:** The capability shipped but isn't switched on. Every hosted deployment still
writes attachments, logos, and backups to the release directory's `storage/app`, so a
deploy that swaps the release directory loses them, and a second app server would never
see the first one's uploads. This is the failure mode the feature was built to prevent.

**Context:** Two buckets, because logos must be publicly readable and attachments and
backups must not: `AWS_BUCKET` (private) and `AWS_PUBLIC_BUCKET` + `AWS_PUBLIC_URL`
(public-read). Keep each region's bucket in the country it serves — `ca-central-1` for
the Canadian site. The three disk roles are independent, so you can move logos first and
leave backups local if you want to stage it. **Run `php artisan storage:check` after
switching** — it round-trips an object through each disk and then fetches it
unauthenticated to prove the public/private split is actually right. Existing files keep
resolving from their recorded per-row disk, so no backfill is needed; only new uploads
land on S3.

**Effort:** S
**Priority:** P1
**Depends on:** None

## Testing

### End-to-end test for the guest country-switcher banner

**What:** A browser test that loads a page on a `.ca` host as a guest, asserts the
banner offers the US site, and follows the link to `APP_URL_US`.

**Why:** The banner is the only cross-region navigation and it is currently covered
only at the unit level (`Country::fromHost`). The rendering, the `ll_region` cookie, the
dismiss action, and the cross-host link are untested.

**Context:** Flagged during the pre-launch audit as the region split's untested path.
Needs the second deployment, or a host-header stub, to assert the
cross-origin link meaningfully. The banner and its Alpine block are
`resources/views/components/geo-banner.blade.php` and the `geoBanner` component in
`resources/js/app.js`.

**Effort:** M
**Priority:** P2
**Depends on:** Stand up the US app deployment at books.lineledger.com

## Completed
