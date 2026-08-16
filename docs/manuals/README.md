# Manuals

PDF user manuals built from live LineLedger sessions: real organization, real postings, unretouched screenshots.

> **Screenshot age.** The captures and the built PDF below date from **2026-06-10**,
> before the 1.0.0 release and before the corporate entity was renamed from
> "Line Ledger" to **Local Foundry Inc.** Consequences to know about when reading or
> reusing them: every screenshot's footer still reads "© 2026 Line Ledger"; the auth
> pages now also carry a guest country-switcher banner that isn't in the captures.
> The prose has been updated for these; the **images have not**. Recapture per
> *Regenerating* below when you next revise a manual.

## getting-started/

**"LineLedger: Your First Month"** — a club treasurer's walkthrough (Edgemont Photo Club, an unincorporated association in BC): create the organization, membership level + member, dues invoice → cash receipt → bank deposit → January 31 reconciliation with a $2 service charge, then the month-end reports. Source `getting-started.md`, screenshots in `images/`, deliverable `lineledger-getting-started.pdf`.

## Regenerating

1. **Isolated DB** (keeps your dev data intact):
   `mysql -u root -e "CREATE DATABASE IF NOT EXISTS lineledger_manual"`
   `php artisan config:clear && DB_DATABASE=lineledger_manual php artisan migrate`
   (No `--seed` — zero users keeps `/register` open and the walkthrough authentic.)
2. **Serve built assets**: `npm run build && DB_DATABASE=lineledger_manual php artisan serve --port=8080`
   No queue worker needed — every flow in the manual posts synchronously.
3. **Re-capture** with a headless browser at 1440×900 @2x, light mode, following the scenario values in the manual itself (all dates January 2026; statement ending balance $8.00; service charge $2.00 → 6010 Bank Charges). Number screenshots `01-…` to `34-…` as in `images/`.
4. **Render** with gstack make-pdf from `docs/manuals/getting-started/`:
   `pdf generate --cover --toc --no-confidential --title "LineLedger: Your First Month" --author "LineLedger" --date "January 2026" getting-started.md lineledger-getting-started.pdf`
5. **Verify** before shipping: reconciliation difference $0.00; balance sheet $8 / income statement $10 − $2 = $8 / cash flow +$8; `DB_DATABASE=lineledger_manual php artisan integrity:check` reports OK.

Gotchas learned the hard way: every date field defaults to today, so backdate explicitly (wizard start date, invoice date *and* due date, receipt, deposit, statement date); screenshot modals viewport-only (full-page makes them tiny); the contact "Country" field wants a 2-letter code (`CA`).
