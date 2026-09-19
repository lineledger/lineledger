# Manuals

PDF user manuals built from live LineLedger sessions: real organization, real postings, unretouched screenshots.

> **Captures, prose and the PDF were regenerated 2026-09-19 for LineLedger 1.1.0.** Recapture
> per *Regenerating* below whenever you next revise a manual, so the images keep pace with the
> prose.

## getting-started/

**"LineLedger: Your First Month"** — a club treasurer's walkthrough (Edgemont Photo Club, an unincorporated association in BC): create the organization, membership level + member, dues invoice → cash receipt → bank deposit → January 31 reconciliation with a $2 service charge, then the month-end reports. Source `getting-started.md`, screenshots in `images/`, deliverable `lineledger-getting-started.pdf`.

## Regenerating

1. **Isolated DB** (keeps your dev data intact):
   `mysql -u root -e "CREATE DATABASE IF NOT EXISTS lineledger_manual"`
   `php artisan config:clear && DB_DATABASE=lineledger_manual php artisan migrate`
   (No `--seed` — zero users keeps `/register` open and the walkthrough authentic.) A throwaway
   SQLite file works too — `DB_CONNECTION=sqlite DB_DATABASE=/abs/path/manual.sqlite` on every
   command below; the 1.1.0 captures used one.
2. **Serve built assets**: `npm run build && DB_DATABASE=lineledger_manual php artisan serve --port=8080`
   No queue worker needed — every flow in the manual posts synchronously.
3. **Re-capture** with a headless browser at 1440×900 @2x, light mode, following the scenario
   values in the manual itself (all dates January 2026; statement ending balance $8.00; service
   charge $2.00 → 6010 Bank Charges). Number screenshots `01-…` to `34-…` as in `images/`.
   File numbers follow capture order, not figure numbers: `34-reports-hub.png` is Figure 29,
   and `29-…` through `33-…` are Figures 30–34. Figures 6, 9, 11, 12 and 17 are full-page
   captures, 18 is a clip of the member page header, and everything else is viewport-only.
4. **Render** with the gstack make-pdf binary (it is not on `PATH`), from
   `docs/manuals/getting-started/`:

   ```bash
   ~/.claude/skills/gstack/make-pdf/dist/pdf generate --cover --toc --no-confidential \
     --title "LineLedger: Your First Month" --author "LineLedger" --date "September 2026" \
     getting-started.md lineledger-getting-started.pdf
   ```

   If gstack lives somewhere else, point `$MAKE_PDF_BIN` at the binary or run the `/make-pdf`
   skill, which resolves it for you. Add `--strict` to fail the build on a missing or misnamed
   screenshot instead of rendering a placeholder. Eyeball the two raw
   `<img style="max-height: 7.6in">` figures (9 and 12) in the output — they are the only
   hand-sized images.
5. **Verify** before shipping: reconciliation difference $0.00; balance sheet $8 / income statement $10 − $2 = $8 / cash flow +$8; `DB_DATABASE=lineledger_manual php artisan integrity:check` reports OK.

Gotchas learned the hard way:

- **Email verification comes before the wizard.** After *Create account* the app stops at
  `/email/verify`. With `MAIL_MAILER=log` the signed link lands in `storage/logs/laravel.log`
  — open it in the capture browser — or mark the user verified, as the 1.1.0 capture did:
  `DB_DATABASE=lineledger_manual php artisan tinker --execute='App\Models\User::first()->markEmailAsVerified();'`.
  Then open `/`, not `/dashboard` (there is no such route — the dashboard is
  `{company}/dashboard`), to land in the wizard. The manual's *Verify your email address*
  section has no figure.
- **Country banner on the auth pages.** A direct visit shows a banner along the bottom offering
  the other country's site ("Go to Canada / Stay here"; a local host counts as the US site
  unless `APP_REGION` is set). Click **Stay here** before capturing figure 1 — the choice is
  kept in that browser's localStorage (`ll_region`), so it won't return.
- **Wizard defaults to undo.** Step 2 pre-selects *Non-profit corporation* (and shows the
  restricted-contributions question) — pick *Unincorporated association*. Step 4 pre-selects
  Employees, Fixed assets, Membership and Donations & grants — leave only Membership on.
  Step 5 for a BC organization has **two** switches, *I charge GST/HST* and *I charge PST*,
  both on by default — switch both off, or the chart gains sales-tax payable accounts and the
  28-account preview (figure 9) and Step 8 count change.
- Most date fields default to today, so backdate explicitly (wizard start date, the member's
  Joined and Term start, invoice date, receipt date, deposit date, and each report's As of or
  period dates). Two don't. The invoice **due date** starts at today + 30 days on
  a blank invoice, or at today + the level's terms on the *Bill dues now* draft (Due on receipt
  → today), and is recomputed from the payment terms only when you pick the customer or change
  the Terms field — changing the invoice date does **not** move it, so set the due date to
  2026-01-05 explicitly, as the manual does. The reconciliation's **statement date** defaults
  to the current month-end (or the month after the last completed statement), with the
  service-charge and interest dates following it — so set it to 2026-01-31 before touching
  anything else in the Begin dialog.
- **Expires must be typed.** The new-member form doesn't derive *Expires* from an annual level
  (Joined and Term start default to today; Expires stays blank) — type 2027-01-04. The contact
  "Country" field wants a 2-letter code (`CA`) — the field now says so itself.
- A new deposit's receipt picker starts **unticked**. Tick REC-000001 before capturing figure 23,
  or the deposit total reads $0.00. The picker sorts and has an Export menu; leave the default
  sort for the capture.
- The bank register is **read-only**: no Clear all / Unclear all, no statement-balance or
  difference tiles, rows can't be ticked. Figure 24 is the two tiles (Ledger balance, Cleared
  balance) plus the deposit row, and a green tick only appears once the reconciliation clears it.
- **Reconcile flow.** Bank register → *Actions → Reconcile* opens the Reconcile page; its
  **Reconcile** button opens *Begin reconciliation* (the service-charge account pre-selects
  6010 Bank Charges, interest pre-selects 4900). *Reconcile now* returns to the Reconcile list
  ("Last reconciled on Jan 31, 2026" plus a toast), not to the record — open figure 28's
  *Reconciliation #1* from the 2026-01-31 row. Its *Completed* stamp, like the dashboard
  chart's months in figure 11, shows the real capture date; the manual says so rather than
  pretending otherwise.
- Screenshot modals viewport-only (full-page makes them tiny).
- The gstack browse session can drop its viewport and login after a long pause; re-set
  1440×900 @2x and log in again before carrying on.
