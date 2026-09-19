<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Deposit;
use App\Services\Posting\DepositPoster;
use Illuminate\Support\Facades\DB;

/**
 * banking:backfill-line-memos rewrites the bare "Deposit" a pre-memo poster
 * left on a bank leg. --dry-run reports the same count without writing, so an
 * operator (or app:upgrade --dry-run) can see what a live run would touch.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();

    $deposit = Deposit::create([
        'bank_account_id' => $bank->id,
        'deposit_no' => 'DEP-DRY-1',
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

    $this->lineId = (int) DB::table('journal_lines')
        ->where('journal_entry_id', $deposit->fresh()->journal_entry_id)
        ->where('account_id', $bank->id)
        ->value('id');

    // Rewind to what the old poster wrote.
    DB::table('journal_lines')->where('id', $this->lineId)->update(['memo' => 'Deposit']);

    app()->forgetInstance('current_company');
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('counts the memos it would rewrite on --dry-run and writes nothing', function () {
    $this->artisan('banking:backfill-line-memos', ['company' => $this->company->slug, '--dry-run' => true])
        ->expectsOutputToContain("Company {$this->company->slug} — would rewrite 1 bank line memo(s).")
        ->assertSuccessful();

    expect(DB::table('journal_lines')->where('id', $this->lineId)->value('memo'))->toBe('Deposit');

    $this->artisan('banking:backfill-line-memos', ['company' => $this->company->slug])
        ->expectsOutputToContain("Company {$this->company->slug} — rewrote 1 bank line memo(s).")
        ->assertSuccessful();

    expect(DB::table('journal_lines')->where('id', $this->lineId)->value('memo'))->toBe('Deposit: August 2026 Interac Deposits');

    // Nothing left for either mode to match.
    $this->artisan('banking:backfill-line-memos', ['company' => $this->company->slug, '--dry-run' => true])
        ->expectsOutputToContain('would rewrite 0 bank line memo(s)')
        ->assertSuccessful();
});

it('resolves the company argument by id or by slug, never both', function () {
    // MySQL coerces `id = '<n>st-street-bakery'` to `id = <n>`, so an
    // `id OR slug` lookup would also pull in the company with that id. SQLite
    // does not coerce, so this documents the intent rather than reproducing it.
    $decoy = Company::factory()->create(['slug' => $this->company->id.'st-street-bakery']);

    $this->artisan('banking:backfill-line-memos', ['company' => $decoy->slug, '--dry-run' => true])
        ->expectsOutputToContain("Company {$decoy->slug} — would rewrite 0 bank line memo(s).")
        ->doesntExpectOutputToContain("Company {$this->company->slug}")
        ->assertSuccessful();

    $this->artisan('banking:backfill-line-memos', ['company' => (string) $this->company->id, '--dry-run' => true])
        ->expectsOutputToContain("Company {$this->company->slug} — would rewrite 1 bank line memo(s).")
        ->doesntExpectOutputToContain("Company {$decoy->slug}")
        ->assertSuccessful();

    expect(DB::table('journal_lines')->where('id', $this->lineId)->value('memo'))->toBe('Deposit');
});
