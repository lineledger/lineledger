# Contributing to LineLedger

LineLedger is **source-available** and open source under the **GNU Affero General
Public License v3 (AGPL-3.0)**. We are **not actively soliciting external
contributions or issues** — you're welcome to read, learn from, and fork the
project under the terms of the license.

That said, if you do decide to send a fix our way, this guide and our
[Code of Conduct](CODE_OF_CONDUCT.md) explain what's required before you start.

<br>

## Licensing & the CLA — read this first

- The project is licensed under **AGPL-3.0**. Your contributions are released
  under the same license.
- **By opening a pull request, you agree to our [Contributor License Agreement](CLA.md).**
  This is required for every contribution — there is no separate form to sign;
  submitting a PR is your acceptance. A maintainer may not merge a PR until this
  is satisfied.

If you can't agree to the CLA, please don't submit a PR.

<br>

## Development setup

LineLedger is a **Laravel** application (PHP 8.5+, Livewire, Tailwind).

1. **Fork** the repository (the "Fork" button, top right), then **clone** your fork:

   ```shell
   git clone https://github.com/YOUR_USERNAME/lineledger.git
   cd lineledger
   ```

2. **Install dependencies:**

   ```shell
   composer install
   npm install
   ```

3. **Create the databases.** `.env.example` defaults to MySQL, and the test suite
   uses a **separate** database from the app. Create both before migrating:

   ```shell
   mysql -u root -e "CREATE DATABASE lineledger; CREATE DATABASE lineledger_test;"
   ```

4. **Set up your environment:**

   ```shell
   cp .env.example .env
   php artisan key:generate
   php artisan migrate
   ```

5. **Run the app:**

   ```shell
   composer run dev
   ```

   This starts the PHP server, the **queue worker**, the log tailer, and Vite
   together, and is the recommended loop. The queue worker matters: backups,
   restores, recurring-document generation, and all outgoing mail are queued jobs,
   and without a worker they just accumulate in the `jobs` table. If you'd rather
   run things separately, remember to start `php artisan queue:listen` alongside
   `php artisan serve` and `npm run dev`.

<br>

## Making changes

1. **Create a branch** off `main` — never commit directly to `main`:

   ```shell
   git checkout -b your-branch-name
   ```

2. **Make your changes.** Keep them focused — one logical change per pull request
   is much easier to review than a large mixed one.

3. **Test locally.** All tests must pass:

   ```shell
   ./vendor/bin/pest
   ```

   Please add or update tests for any behavior you change.

   **Run the suite on both databases.** Locally the suite runs against **MySQL**
   (`lineledger_test`, from `phpunit.xml`), but CI runs it against **SQLite
   `:memory:`**. The two genuinely diverge on date handling, so a change can pass
   locally and fail in CI. Reproduce CI exactly with:

   ```shell
   DB_CONNECTION=sqlite DB_DATABASE=':memory:' ./vendor/bin/pest
   ```

4. **Run the full CI gate.** Three separate workflows can fail your PR, and
   `composer run test` covers the first two locally:

   ```shell
   composer run test                                  # Pint check + the full suite
   vendor/bin/phpstan analyse --memory-limit=1G       # static analysis
   ```

   - **Pint** — `vendor/bin/pint` fixes formatting; CI runs it in `--test` mode.
     Never pass a `.blade.php` path to Pint: the Volt single-file components
     (`⚡*.blade.php`) are excluded by the preset, but an explicit path bypasses
     that exclusion and mangles the file. Use `vendor/bin/pint --dirty`.
   - **PHPStan / Larastan** — level 5, baselined in `phpstan-baseline.neon`, so it
     fails only on **new** findings. This gate is easy to miss because it lives in a
     separate `linter` workflow from the tests.
   - **Dependency audit** — `composer audit --locked` and `npm audit` also run on
     every PR.

5. **Commit** with a clear, concise message:

   ```shell
   git commit -m "Add a short, descriptive summary of the change"
   ```

6. **Push** to your fork:

   ```shell
   git push origin your-branch-name
   ```

7. **Open a pull request** against the LineLedger repository. Describe what the
   change does and why. Opening the PR confirms you agree to the
   [CLA](CLA.md).

<br>

## Coding standards

- PHP code follows the Laravel/Pint default style — run `./vendor/bin/pint`
  before committing.
- Write tests with [Pest](https://pestphp.com/). New features and bug fixes
  should come with coverage.
- Prefer small, reviewable PRs and clear commit messages.
- Don't include unrelated formatting churn — it makes review harder.

<br>

## Security

For anything you believe is a **security vulnerability**, please do **not** open
a public issue. Email **hello@lineledger.ca** and read [SECURITY.md](SECURITY.md),
which sets out the disclosure process and the safe-harbour terms.
