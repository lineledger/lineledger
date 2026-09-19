<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Deposit;
use App\Services\Posting\DepositPoster;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * app:upgrade is the one command an operator runs after pulling a release:
 * migrations, then each data backfill, then (with --verify) the integrity
 * check, stopping at the first failure. It must be safe to re-run and cheap
 * when there is nothing left to do.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
});

afterEach(fn () => app()->forgetInstance('current_company'));

/**
 * A deposit posted before the poster carried the operator memo onto the bank
 * leg, so its register row still reads a bare "Deposit" — exactly what the
 * memo backfill exists to rewrite. Returns the bank-leg journal line id.
 * Leaves no company bound, so the command runs the way it does from a shell.
 */
function upgradeTestBareDeposit(Company $company, string $no = 'DEP-UP-1'): int
{
    app()->instance('current_company', $company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $deposit = Deposit::create([
        'bank_account_id' => $bank->id,
        'deposit_no' => $no,
        'deposit_date' => now()->toDateString(),
        'memo' => 'August 2026 Interac Deposits',
    ]);
    $deposit->lines()->create([
        'account_id' => $income->id,
        'description' => 'Interac',
        'amount_cents' => 161815,
        'line_order' => 0,
    ]);
    app(DepositPoster::class)->post($deposit->fresh('lines'));

    $lineId = (int) DB::table('journal_lines')
        ->where('journal_entry_id', $deposit->fresh()->journal_entry_id)
        ->where('account_id', $bank->id)
        ->value('id');

    // Rewind to what the old poster wrote.
    DB::table('journal_lines')->where('id', $lineId)->update(['memo' => 'Deposit']);

    app()->forgetInstance('current_company');

    return $lineId;
}

function upgradeTestMemo(int $lineId): ?string
{
    return DB::table('journal_lines')->where('id', $lineId)->value('memo');
}

/**
 * Swap a step's command for a stand-in on this test's console kernel only.
 */
function upgradeTestFakeCommand(Command $command): void
{
    /** @var Illuminate\Foundation\Console\Kernel $kernel */
    $kernel = app(Kernel::class);
    $kernel->registerCommand($command);
}

it('dry run lists pending migrations, counts what the backfills would change, and writes nothing', function () {
    $lineId = upgradeTestBareDeposit($this->company);

    // A dry run must never reach `migrate` itself.
    upgradeTestFakeCommand(new class extends Command
    {
        protected $signature = 'migrate {--force}';

        public function handle(): int
        {
            throw new RuntimeException('migrate ran during a dry run');
        }
    });

    $this->artisan('app:upgrade', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run — nothing will be written.')
        ->expectsOutputToContain('No pending migrations')
        ->expectsOutputToContain('would rewrite 1 bank line memo(s)')
        ->expectsOutputToContain('would un-clear 0 line(s)')
        ->expectsOutputToContain('Counted above; nothing written.')
        ->assertSuccessful();

    expect(upgradeTestMemo($lineId))->toBe('Deposit');
});

it('migrates and runs both backfills, and a second run has nothing left to do', function () {
    $lineId = upgradeTestBareDeposit($this->company);

    $this->artisan('app:upgrade')
        ->expectsOutputToContain('1. Migrations')
        ->expectsOutputToContain('Nothing to migrate')
        ->expectsOutputToContain('2. Bank line memos')
        ->expectsOutputToContain('rewrote 1 bank line memo(s)')
        ->expectsOutputToContain('3. Reconciliation stamps')
        ->expectsOutputToContain('un-cleared 0 line(s)')
        ->doesntExpectOutputToContain('Integrity check')
        ->assertSuccessful();

    expect(upgradeTestMemo($lineId))->toBe('Deposit: August 2026 Interac Deposits');

    // Idempotent: the second pass matches nothing and still exits clean.
    $this->artisan('app:upgrade')
        ->expectsOutputToContain('rewrote 0 bank line memo(s)')
        ->expectsOutputToContain('un-cleared 0 line(s)')
        ->assertSuccessful();

    expect(upgradeTestMemo($lineId))->toBe('Deposit: August 2026 Interac Deposits');
});

it('runs integrity:check with --verify and never emails ops', function () {
    Notification::fake();
    upgradeTestBareDeposit($this->company);

    $this->artisan('app:upgrade', ['--verify' => true])
        ->expectsOutputToContain('4. Integrity check')
        ->expectsOutputToContain('Ledger integrity OK.')
        ->expectsOutputToContain('Passed.')
        ->assertSuccessful();

    // A failing check becomes this command's exit code, not an alert email.
    $lineId = DB::table('journal_lines')->orderBy('id')->value('id');
    DB::table('journal_lines')->where('id', $lineId)->update(['debit_cents' => DB::raw('debit_cents + 100')]);

    $this->artisan('app:upgrade', ['--verify' => true])
        ->expectsOutputToContain('Ledger integrity check FAILED.')
        ->expectsOutputToContain('Failed (exit 1); see the issues above.')
        ->assertExitCode(1);

    Notification::assertNothingSent();
});

it('stops at the first failing step, skips the rest, and returns its exit code', function () {
    $lineId = upgradeTestBareDeposit($this->company);

    upgradeTestFakeCommand(new class extends Command
    {
        protected $signature = 'banking:backfill-reconciliation-stamps {company?} {--dry-run}';

        public function handle(): int
        {
            $this->error('Simulated stamp backfill failure.');

            return 3;
        }
    });

    $this->artisan('app:upgrade', ['--verify' => true])
        ->expectsOutputToContain('rewrote 1 bank line memo(s)')
        ->expectsOutputToContain('Simulated stamp backfill failure.')
        ->expectsOutputToContain('banking:backfill-reconciliation-stamps failed (exit 3).')
        ->expectsOutputToContain('Skipped')
        ->doesntExpectOutputToContain('4. Integrity check')
        ->doesntExpectOutputToContain('Ledger integrity')
        ->assertExitCode(3);

    // The steps before the failure did their work and stay done.
    expect(upgradeTestMemo($lineId))->toBe('Deposit: August 2026 Interac Deposits');
});

it('limits the backfills to one company with --company, by slug or id', function () {
    $mine = upgradeTestBareDeposit($this->company);
    $other = Company::factory()->create();
    $theirs = upgradeTestBareDeposit($other, 'DEP-UP-2');

    $this->artisan('app:upgrade', ['--company' => $this->company->slug])
        ->expectsOutputToContain(sprintf('Limiting the backfills to company %s (#%d).', $this->company->slug, $this->company->id))
        ->expectsOutputToContain("Company {$this->company->slug} — rewrote 1 bank line memo(s)")
        ->doesntExpectOutputToContain("Company {$other->slug}")
        ->assertSuccessful();

    expect(upgradeTestMemo($mine))->toBe('Deposit: August 2026 Interac Deposits')
        ->and(upgradeTestMemo($theirs))->toBe('Deposit');

    $this->artisan('app:upgrade', ['--company' => (string) $other->id])
        ->expectsOutputToContain("Company {$other->slug} — rewrote 1 bank line memo(s)")
        ->doesntExpectOutputToContain("Company {$this->company->slug}")
        ->assertSuccessful();

    expect(upgradeTestMemo($theirs))->toBe('Deposit: August 2026 Interac Deposits');
});

it('resolves a slug that starts with another company\'s id to the slug\'s company', function () {
    // MySQL coerces `id = '<n>st-street-bakery'` to `id = <n>`, so an
    // `id OR slug` lookup would match both tenants and hand the run the
    // lowest id — the wrong one. SQLite does not coerce, so this documents the
    // intent; the resolution must be by id or by slug, never both.
    $mine = upgradeTestBareDeposit($this->company);
    $decoy = Company::factory()->create(['slug' => $this->company->id.'st-street-bakery']);
    $theirs = upgradeTestBareDeposit($decoy, 'DEP-UP-3');

    $this->artisan('app:upgrade', ['--company' => $decoy->slug])
        ->expectsOutputToContain(sprintf('Limiting the backfills to company %s (#%d).', $decoy->slug, $decoy->id))
        ->expectsOutputToContain("Company {$decoy->slug} — rewrote 1 bank line memo(s)")
        ->doesntExpectOutputToContain("Company {$this->company->slug}")
        ->assertSuccessful();

    expect(upgradeTestMemo($theirs))->toBe('Deposit: August 2026 Interac Deposits')
        ->and(upgradeTestMemo($mine))->toBe('Deposit');

    // And a bare id never falls through to a slug that merely starts with it.
    $this->artisan('app:upgrade', ['--company' => (string) $this->company->id])
        ->expectsOutputToContain(sprintf('Limiting the backfills to company %s (#%d).', $this->company->slug, $this->company->id))
        ->expectsOutputToContain("Company {$this->company->slug} — rewrote 1 bank line memo(s)")
        ->doesntExpectOutputToContain("Company {$decoy->slug}")
        ->assertSuccessful();

    expect(upgradeTestMemo($mine))->toBe('Deposit: August 2026 Interac Deposits');
});

it('refuses an unknown --company before running any step', function () {
    $lineId = upgradeTestBareDeposit($this->company);

    $this->artisan('app:upgrade', ['--company' => 'no-such-company'])
        ->expectsOutputToContain('No company matches "no-such-company".')
        ->doesntExpectOutputToContain('1. Migrations')
        ->assertFailed();

    expect(upgradeTestMemo($lineId))->toBe('Deposit');
});
