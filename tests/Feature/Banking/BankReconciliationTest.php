<?php

use App\Enums\AccountSubtype;
use App\Enums\BankReconciliationStatus;
use App\Enums\CompanyRole;
use App\Exceptions\Posting\ReconciliationOutOfBalanceException;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use App\Services\Reconciliation\BankReconciliationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('code', '6010')->first(); // Bank Charges
    $this->income = Account::query()->where('subtype', AccountSubtype::OtherIncome->value)->orderBy('code')->first();
    $this->revenue = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->service = app(BankReconciliationService::class);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeBankEntry(Account $bank, Account $other, int $debitOnBankCents, int $creditOnBankCents, ?string $date = null): JournalEntry
{
    $entry = JournalEntry::create([
        'entry_no' => 'JE-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
        'entry_date' => $date ?? now()->toDateString(),
        'memo' => 'test',
    ]);

    if ($debitOnBankCents > 0) {
        $entry->lines()->create([
            'account_id' => $bank->id,
            'debit_cents' => $debitOnBankCents,
            'credit_cents' => 0,
            'line_order' => 0,
        ]);

        $entry->lines()->create([
            'account_id' => $other->id,
            'debit_cents' => 0,
            'credit_cents' => $debitOnBankCents,
            'line_order' => 1,
        ]);
    } else {
        $entry->lines()->create([
            'account_id' => $other->id,
            'debit_cents' => $creditOnBankCents,
            'credit_cents' => 0,
            'line_order' => 0,
        ]);

        $entry->lines()->create([
            'account_id' => $bank->id,
            'debit_cents' => 0,
            'credit_cents' => $creditOnBankCents,
            'line_order' => 1,
        ]);
    }

    app(JournalPoster::class)->post($entry->refresh());

    return $entry->fresh('lines');
}

it('defaults the statement date to one month after the last completed reconciliation', function () {
    BankReconciliation::factory()->completed()->create([
        'company_id' => $this->company->id,
        'account_id' => $this->bank->id,
        'statement_date' => '2025-08-31',
    ]);

    Livewire\Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSet('statementDate', '2025-09-30')
        ->assertSet('serviceChargeDate', '2025-09-30')
        ->assertSet('interestDate', '2025-09-30');
});

it('defaults the statement date to the current month-end when no prior reconciliation exists', function () {
    Livewire\Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id)
        ->assertSet('statementDate', $this->company->currentDateTime()->endOfMonth()->toDateString());
});

it('seeds beginning balance from the previous completed reconciliation', function () {
    BankReconciliation::factory()->completed()->create([
        'company_id' => $this->company->id,
        'account_id' => $this->bank->id,
        'ending_balance_cents' => 100000,
    ]);

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 150000);

    expect($rec->beginning_balance_cents)->toBe(100000);
    expect($rec->ending_balance_cents)->toBe(150000);
    expect($rec->status)->toBe(BankReconciliationStatus::InProgress);
});

it('posts a service charge entry on begin and auto-marks the bank line', function () {
    $rec = $this->service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: -1500,
        serviceCharge: ['cents' => 1500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
    );

    expect($rec->service_charge_entry_id)->not->toBeNull();

    $entry = JournalEntry::with('lines')->find($rec->service_charge_entry_id);
    expect($entry->source_type)->toBe(BankReconciliation::class);
    expect($entry->source_id)->toBe($rec->id);
    expect($entry->isPosted())->toBeTrue();
    expect($entry->totalDebitsCents())->toBe(1500);
    expect($entry->totalCreditsCents())->toBe(1500);

    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);
    expect($bankLine->credit_cents)->toBe(1500);
    expect($bankLine->cleared_at)->not->toBeNull();
    expect($bankLine->bank_reconciliation_id)->toBe($rec->id);

    expect($rec->markedLineIds())->toContain($bankLine->id);
});

it('posts an interest entry on begin and auto-marks the bank line', function () {
    $rec = $this->service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: 500,
        interestEarned: ['cents' => 500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->income->id],
    );

    $entry = JournalEntry::with('lines')->find($rec->interest_earned_entry_id);
    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);

    expect($bankLine->debit_cents)->toBe(500);
    expect($bankLine->cleared_at)->not->toBeNull();
    expect($bankLine->bank_reconciliation_id)->toBe($rec->id);
    expect($rec->markedLineIds())->toContain($bankLine->id);
});

it('auto-marks lines previously cleared via the legacy register', function () {
    $entry = makeBankEntry($this->bank, $this->revenue, debitOnBankCents: 0, creditOnBankCents: 2000);
    $bankLine = $entry->lines->firstWhere('account_id', $this->bank->id);
    $bankLine->update(['cleared_at' => now()]); // legacy clear, no rec id

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), -2000);

    expect($rec->markedLineIds())->toContain($bankLine->id);
});

it('refuses to complete when out of balance', function () {
    makeBankEntry($this->bank, $this->revenue, debitOnBankCents: 5000, creditOnBankCents: 0);

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 9999);

    $rec->forceFill(['marked_line_ids' => []])->save();

    expect(fn () => $this->service->complete($rec))
        ->toThrow(ReconciliationOutOfBalanceException::class);

    expect($rec->fresh()->status)->toBe(BankReconciliationStatus::InProgress);
});

it('sets cleared_at and bank_reconciliation_id on marked lines when completed', function () {
    $deposit = makeBankEntry($this->bank, $this->revenue, debitOnBankCents: 5000, creditOnBankCents: 0);
    $depositLineId = $deposit->lines->firstWhere('account_id', $this->bank->id)->id;

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 5000);
    $this->service->toggleMark($rec, $depositLineId);

    $user = User::factory()->create();
    $completed = $this->service->complete($rec, $user);

    expect($completed->status)->toBe(BankReconciliationStatus::Completed);
    expect($completed->completed_by_user_id)->toBe($user->id);

    $line = JournalLine::find($depositLineId);
    expect($line->cleared_at)->not->toBeNull();
    expect($line->bank_reconciliation_id)->toBe($rec->id);
});

it('undoes the most recent reconciliation: un-clears lines and reverses adjustment entries', function () {
    $deposit = makeBankEntry($this->bank, $this->revenue, debitOnBankCents: 10000, creditOnBankCents: 0);
    $depositLineId = $deposit->lines->firstWhere('account_id', $this->bank->id)->id;

    $bankBalanceBefore = $this->bank->fresh()->balance_cents;

    $rec = $this->service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: 10000 - 500 + 100,
        serviceCharge: ['cents' => 500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
        interestEarned: ['cents' => 100, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->income->id],
    );

    $this->service->toggleMark($rec, $depositLineId);

    $completed = $this->service->complete($rec);

    $serviceChargeEntryId = $completed->service_charge_entry_id;
    $interestEntryId = $completed->interest_earned_entry_id;

    $this->service->undo($completed);

    expect(BankReconciliation::find($completed->id))->toBeNull();

    $line = JournalLine::find($depositLineId);
    expect($line->cleared_at)->toBeNull();
    expect($line->bank_reconciliation_id)->toBeNull();

    expect(JournalEntry::find($serviceChargeEntryId)->isVoided())->toBeTrue();
    expect(JournalEntry::find($interestEntryId)->isVoided())->toBeTrue();

    expect($this->bank->fresh()->balance_cents)->toBe($bankBalanceBefore);
});

it('edits the statement figures of an in-progress reconciliation in place, keeping marked lines', function () {
    $deposit = makeBankEntry($this->bank, $this->revenue, debitOnBankCents: 5000, creditOnBankCents: 0, date: '2026-04-15');
    $depositLineId = $deposit->lines->firstWhere('account_id', $this->bank->id)->id;

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 5000);
    $this->service->toggleMark($rec, $depositLineId);

    $updated = $this->service->updateDetails(
        $rec,
        Carbon::parse('2026-05-31'),
        endingBalanceCents: 17500,
        beginningBalanceCents: 12500,
    );

    expect($updated->statement_date->toDateString())->toBe('2026-05-31');
    expect($updated->beginning_balance_cents)->toBe(12500);
    expect($updated->ending_balance_cents)->toBe(17500);
    expect($updated->markedLineIds())->toContain($depositLineId);
});

it('replaces a service charge when edited, voiding the old entry and re-marking the new bank line', function () {
    $rec = $this->service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: -1500,
        serviceCharge: ['cents' => 1500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
    );

    $oldEntryId = $rec->service_charge_entry_id;
    $oldBankLineId = JournalEntry::with('lines')->find($oldEntryId)->lines->firstWhere('account_id', $this->bank->id)->id;

    $updated = $this->service->updateDetails(
        $rec,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: -2000,
        beginningBalanceCents: 0,
        serviceCharge: ['cents' => 2000, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
    );

    expect(JournalEntry::find($oldEntryId)->isVoided())->toBeTrue();
    expect($updated->service_charge_cents)->toBe(2000);
    expect($updated->service_charge_entry_id)->not->toBe($oldEntryId);

    $newBankLine = JournalEntry::with('lines')->find($updated->service_charge_entry_id)->lines->firstWhere('account_id', $this->bank->id);
    expect($newBankLine->credit_cents)->toBe(2000);
    expect($updated->markedLineIds())->toContain($newBankLine->id);
    expect($updated->markedLineIds())->not->toContain($oldBankLineId);
});

it('adds interest when editing a reconciliation that had none', function () {
    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 500);

    expect($rec->interest_earned_entry_id)->toBeNull();

    $updated = $this->service->updateDetails(
        $rec,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: 500,
        beginningBalanceCents: 0,
        interestEarned: ['cents' => 500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->income->id],
    );

    expect($updated->interest_earned_entry_id)->not->toBeNull();
    expect($updated->interest_earned_cents)->toBe(500);

    $bankLine = JournalEntry::with('lines')->find($updated->interest_earned_entry_id)->lines->firstWhere('account_id', $this->bank->id);
    expect($bankLine->debit_cents)->toBe(500);
    expect($updated->markedLineIds())->toContain($bankLine->id);
});

it('removes a service charge when edited to zero, voiding the entry and unmarking its line', function () {
    $rec = $this->service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: -1500,
        serviceCharge: ['cents' => 1500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
    );

    $oldEntryId = $rec->service_charge_entry_id;
    $oldBankLineId = JournalEntry::with('lines')->find($oldEntryId)->lines->firstWhere('account_id', $this->bank->id)->id;

    $updated = $this->service->updateDetails(
        $rec,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: 0,
        beginningBalanceCents: 0,
    );

    expect(JournalEntry::find($oldEntryId)->isVoided())->toBeTrue();
    expect($updated->service_charge_cents)->toBe(0);
    expect($updated->service_charge_entry_id)->toBeNull();
    expect($updated->markedLineIds())->not->toContain($oldBankLineId);
});

it('hides the reversal of a replaced service charge from the reconcile screen', function () {
    $user = User::factory()->create();
    $this->company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);

    $rec = $this->service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: -1500,
        serviceCharge: ['cents' => 1500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
    );

    $this->service->updateDetails(
        $rec,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: -2000,
        beginningBalanceCents: 0,
        serviceCharge: ['cents' => 2000, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
    );

    $component = Livewire\Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bank->id);

    // The reversal (a bank debit) is hidden, so nothing shows on the deposit side.
    expect($component->instance()->availableLines('deposits'))->toHaveCount(0);

    // Only the live 2000 service charge remains on the payments side.
    $payments = $component->instance()->availableLines('payments');
    expect($payments)->toHaveCount(1);
    expect((int) $payments->first()->credit_cents)->toBe(2000);
});

it('refuses a second in-progress reconciliation on the same account', function () {
    $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 0);

    expect(fn () => $this->service->begin($this->bank, Carbon::parse('2026-05-31'), 0))
        ->toThrow(RuntimeException::class);
});

it('renders the reconciliation detail page with summary and line tables', function () {
    $deposit = makeBankEntry($this->bank, $this->revenue, debitOnBankCents: 7500, creditOnBankCents: 0);
    $depositLineId = $deposit->lines->firstWhere('account_id', $this->bank->id)->id;

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 7500);
    $this->service->toggleMark($rec, $depositLineId);
    $completed = $this->service->complete($rec);

    $user = User::factory()->create();
    $company = $this->company;
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    $response = $this->get(route('banking.reconciliations.show', [
        'company' => $company->slug,
        'reconciliation' => $completed->id,
    ]));

    $response->assertOk();
    $response->assertSee('Reconciliation #'.$completed->id);
    $response->assertSee('Deposits and Other Credits');
    $response->assertSee('Cheques and Payments');
    $response->assertSee('75.00');
});

it('exports a reconciliation as CSV, Excel, and PDF', function () {
    $deposit = makeBankEntry($this->bank, $this->revenue, debitOnBankCents: 7500, creditOnBankCents: 0);
    $depositLineId = $deposit->lines->firstWhere('account_id', $this->bank->id)->id;

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 7500);
    $this->service->toggleMark($rec, $depositLineId);
    $completed = $this->service->complete($rec);

    $user = User::factory()->create();
    $company = $this->company;
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    foreach (['exportCsv' => '.csv', 'exportXlsx' => '.xlsx', 'exportPdf' => '.pdf'] as $method => $ext) {
        $test = Livewire\Livewire::test('pages::banking.reconciliation-show', [
            'company' => $company,
            'reconciliation' => $completed,
        ])->call($method);

        $name = data_get($test->effects, 'download.name');
        expect($name)->toEndWith($ext);
    }
});

it('scopes lines to the active company only', function () {
    $other = Company::factory()->create();
    $otherBank = Account::withoutGlobalScopes()
        ->where('company_id', $other->id)
        ->where('subtype', AccountSubtype::Bank->value)
        ->orderBy('code')
        ->first();
    $otherRevenue = Account::withoutGlobalScopes()
        ->where('company_id', $other->id)
        ->where('subtype', AccountSubtype::Income->value)
        ->orderBy('code')
        ->first();

    app()->instance('current_company', $other);
    $foreignEntry = makeBankEntry($otherBank, $otherRevenue, debitOnBankCents: 9999, creditOnBankCents: 0);
    $foreignLineId = $foreignEntry->lines->firstWhere('account_id', $otherBank->id)->id;

    app()->instance('current_company', $this->company);

    $rec = $this->service->begin($this->bank, Carbon::parse('2026-04-30'), 0);

    expect(fn () => $this->service->toggleMark($rec, $foreignLineId))
        ->toThrow(ModelNotFoundException::class);
});
