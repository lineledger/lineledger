<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Services\Audit\PostedMutationGate;
use App\Services\Posting\JournalPoster;
use App\Services\Reconciliation\BankReconciliationService;
use App\Services\Reconciliation\ReconciliationStampBackfiller;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $this->expense = Account::query()->where('code', '6010')->first(); // Bank Charges
    $this->service = app(BankReconciliationService::class);

    // A reconciliation completed before the fix: its service charge was
    // replaced mid-edit, and the replaced charge kept the cleared stamp it was
    // posted with.
    $this->completeWithLeftoverStamp = function (): array {
        $rec = $this->service->begin(
            $this->bank,
            Carbon::parse('2026-04-30'),
            endingBalanceCents: -2000,
            serviceCharge: ['cents' => 1500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
        );

        $oldLine = JournalEntry::with('lines')->find($rec->service_charge_entry_id)->lines->firstWhere('account_id', $this->bank->id);

        $rec = $this->service->updateDetails(
            $rec,
            Carbon::parse('2026-04-30'),
            endingBalanceCents: -2000,
            beginningBalanceCents: 0,
            serviceCharge: ['cents' => 2000, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
        );

        $rec = $this->service->complete($rec);

        PostedMutationGate::within(fn () => $oldLine->fresh()->forceFill(['cleared_at' => now(), 'bank_reconciliation_id' => $rec->id])->save());

        return [$rec, $oldLine->fresh()];
    };
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('un-clears a replaced service charge a completed reconciliation never counted', function () {
    [$rec, $oldLine] = ($this->completeWithLeftoverStamp)();

    $result = app(ReconciliationStampBackfiller::class)->backfill($this->company->id);

    expect($result)->toBe(['unstamped' => 1, 'reconciliations' => 1, 'skipped' => []])
        ->and($oldLine->fresh()->cleared_at)->toBeNull()
        ->and($oldLine->fresh()->bank_reconciliation_id)->toBeNull()
        // The live charge the reconciliation did count keeps its stamp.
        ->and(JournalLine::query()->where('bank_reconciliation_id', $rec->id)->pluck('credit_cents')->map(fn ($c) => (int) $c)->all())->toBe([2000]);

    // Idempotent.
    expect(app(ReconciliationStampBackfiller::class)->backfill($this->company->id)['unstamped'])->toBe(0);
});

it('writes nothing on a dry run', function () {
    [, $oldLine] = ($this->completeWithLeftoverStamp)();

    $this->artisan('banking:backfill-reconciliation-stamps', ['company' => $this->company->slug, '--dry-run' => true])
        ->expectsOutputToContain('would un-clear 1 line(s) on 1 reconciliation(s)')
        ->assertSuccessful();

    expect($oldLine->fresh()->bank_reconciliation_id)->not->toBeNull();

    $this->artisan('banking:backfill-reconciliation-stamps', ['company' => $this->company->slug])
        ->expectsOutputToContain('un-cleared 1 line(s) on 1 reconciliation(s)')
        ->assertSuccessful();

    expect($oldLine->fresh()->bank_reconciliation_id)->toBeNull();
});

it('skips a reconciliation whose ticked lines no longer match its cleared ones', function () {
    [$rec, $oldLine] = ($this->completeWithLeftoverStamp)();

    // A restore renumbers journal lines but not the ticked list.
    $rec->forceFill(['marked_line_ids' => [999999]])->save();

    $result = app(ReconciliationStampBackfiller::class)->backfill($this->company->id);

    expect($result['skipped'])->toBe([$rec->id])
        ->and($result['unstamped'])->toBe(0)
        ->and($oldLine->fresh()->bank_reconciliation_id)->toBe($rec->id);
});

it('skips a reconciliation when an unticked cleared line is not one of its own adjustments', function () {
    [$rec] = ($this->completeWithLeftoverStamp)();

    $deposit = JournalEntry::create(['entry_no' => 'JE-STAMP1', 'entry_date' => '2026-05-15', 'memo' => 'test']);
    $deposit->lines()->create(['account_id' => $this->bank->id, 'debit_cents' => 500, 'credit_cents' => 0, 'line_order' => 0]);
    $deposit->lines()->create(['account_id' => $this->expense->id, 'debit_cents' => 0, 'credit_cents' => 500, 'line_order' => 1]);
    app(JournalPoster::class)->post($deposit->refresh());

    $depositLine = $deposit->lines()->where('account_id', $this->bank->id)->firstOrFail();
    PostedMutationGate::within(fn () => $depositLine->forceFill(['cleared_at' => now(), 'bank_reconciliation_id' => $rec->id])->save());

    $result = app(ReconciliationStampBackfiller::class)->backfill($this->company->id);

    expect($result['skipped'])->toBe([$rec->id])
        ->and($depositLine->fresh()->bank_reconciliation_id)->toBe($rec->id);
});

it('leaves in-progress reconciliations alone — completing one clears the stamps itself', function () {
    $rec = $this->service->begin(
        $this->bank,
        Carbon::parse('2026-04-30'),
        endingBalanceCents: 0,
        serviceCharge: ['cents' => 1500, 'date' => Carbon::parse('2026-04-30'), 'account_id' => $this->expense->id],
    );

    $line = JournalEntry::with('lines')->find($rec->service_charge_entry_id)->lines->firstWhere('account_id', $this->bank->id);
    $this->service->toggleMark($rec, $line->id);

    expect(app(ReconciliationStampBackfiller::class)->backfill($this->company->id)['unstamped'])->toBe(0)
        ->and(BankReconciliation::query()->inProgress()->count())->toBe(1)
        ->and($line->fresh()->bank_reconciliation_id)->toBe($rec->id);
});
