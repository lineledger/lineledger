<?php

use App\Enums\AccountSubtype;
use App\Enums\DataMigrationMode;
use App\Enums\DataMigrationStatus;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DataMigrationRun;
use App\Models\JournalEntry;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\GeneralLedgerReplayImporter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->run = DataMigrationRun::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'status' => DataMigrationStatus::InProgress,
        'mode' => DataMigrationMode::FullHistory,
        'conversion_date' => CarbonImmutable::create(2026, 7, 31),
        'current_step' => 1,
        'step_results' => [],
        'started_at' => now(),
    ]);

    $this->ar = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $this->income = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Income->value)->orderBy('code')->first();
    $this->bank = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @param  array<string, mixed>  $overrides
 */
function glContext($test, array $overrides = []): ImportContext
{
    return new ImportContext(
        company: $test->company,
        run: $test->run,
        conversionDate: CarbonImmutable::create(2026, 7, 31),
        sourceFormat: $overrides['sourceFormat'] ?? 'csv',
        autoCreateAccounts: $overrides['autoCreateAccounts'] ?? false,
        linkContactNames: $overrides['linkContactNames'] ?? true,
        accountTypesPath: $overrides['accountTypesPath'] ?? null,
    );
}

function writeFile(string $contents, string $ext = 'csv'): string
{
    $path = tempnam(sys_get_temp_dir(), 'gl').'.'.$ext;
    file_put_contents($path, $contents);

    return $path;
}

it('replays a balanced transaction into one posted journal entry', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1001,Invoice,2024-01-15,INV-1,Acme Co,Job 12,{$this->ar->name},1000.00,\n"
        .",,,,,,{$this->income->name},,1000.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(1)
        ->and($result->summary['transactions'])->toBe(1);

    $entry = JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($entry->is_posted)->toBeTrue()
        ->and($entry->source_type)->toBe('qbd_import')
        ->and($entry->source_external_id)->not->toBeNull()
        ->and($entry->lines)->toHaveCount(2);

    $this->ar->recomputeBalance();
    $this->income->recomputeBalance();
    expect((int) $this->ar->balance_cents)->toBe(100000)
        ->and((int) $this->income->balance_cents)->toBe(100000);
});

it('handles a multi-line split transaction', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."2001,Deposit,2024-02-01,,,,{$this->bank->name},1500.00,\n"
        .",,,,,,{$this->income->name},,1000.00\n"
        .",,,,,,{$this->ar->name},,500.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue();
    $entry = JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect($entry->lines)->toHaveCount(3)
        ->and($entry->totalDebitsCents())->toBe(150000)
        ->and($entry->totalCreditsCents())->toBe(150000);
});

it('absorbs a one-cent imbalance into a variance account but rejects large imbalances', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."3001,JE,2024-03-01,,,,{$this->bank->name},100.00,\n"
        .",,,,,,{$this->income->name},,99.99\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['rounding_adjusted'])->toBe(1);

    $variance = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Conversion Rounding Variance')->first();
    expect($variance)->not->toBeNull();

    // A large imbalance is rejected and nothing is posted.
    $bad = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."4001,JE,2024-03-02,,,,{$this->bank->name},100.00,\n"
        .",,,,,,{$this->income->name},,40.00\n";

    $result2 = app(GeneralLedgerReplayImporter::class)->commit(writeFile($bad), glContext($this));
    expect($result2->hasErrors())->toBeTrue()
        ->and($result2->errors[0]['message'])->toContain('does not balance');
});

it('is idempotent — re-running the same file imports nothing the second time', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."5001,Invoice,2024-04-01,INV-9,Acme Co,,{$this->ar->name},250.00,\n"
        .",,,,,,{$this->income->name},,250.00\n";

    $first = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));
    $second = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($first->summary['committed'])->toBe(1)
        ->and($second->summary['committed'])->toBe(0)
        ->and($second->summary['skipped_duplicate'])->toBe(1)
        ->and(JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(1);
});

it('rejects unknown accounts when auto-create is off', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."6001,JE,2024-05-01,,,,Totally Made Up Account,100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->preview(writeFile($csv), glContext($this));

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('not found');
});

it('auto-creates accounts from an IIF file using its account types', function () {
    $iif = implode("\n", [
        implode("\t", ['!ACCNT', 'NAME', 'ACCNTTYPE']),
        implode("\t", ['ACCNT', 'Petty Cash', 'BANK']),
        implode("\t", ['ACCNT', 'Sales Revenue', 'INC']),
        implode("\t", ['!TRNS', 'TRNSID', 'TRNSTYPE', 'DATE', 'ACCNT', 'NAME', 'AMOUNT', 'DOCNUM', 'MEMO']),
        implode("\t", ['!SPL', 'SPLID', 'TRNSTYPE', 'DATE', 'ACCNT', 'NAME', 'AMOUNT', 'DOCNUM', 'MEMO']),
        implode("\t", ['!ENDTRNS']),
        implode("\t", ['TRNS', '1', 'DEPOSIT', '1/15/2024', 'Petty Cash', '', '500.00', 'D-1', 'Opening deposit']),
        implode("\t", ['SPL', '2', 'DEPOSIT', '1/15/2024', 'Sales Revenue', '', '-500.00', 'D-1', '']),
        implode("\t", ['ENDTRNS']),
    ])."\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($iif, 'iif'), glContext($this, ['sourceFormat' => 'iif', 'autoCreateAccounts' => true]));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(1);

    $cash = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Petty Cash')->first();
    $sales = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Sales Revenue')->first();

    expect($cash->subtype)->toBe(AccountSubtype::Bank)
        ->and($sales->subtype)->toBe(AccountSubtype::Income);

    $cash->recomputeBalance();
    expect((int) $cash->balance_cents)->toBe(50000);
});

it('types CSV-replayed auto-created accounts from an attached QuickBooks Account Listing', function () {
    // A QuickBooks "Account Listing" export: number · name, with a Type column.
    $listing = ",,Account,,Type,,Accnt. #\n"
        .",,820 · CANA,,Expense,,820\n"
        .",,440 · Investment in FCI,,Other Asset,,440\n";
    $listingPath = writeFile($listing);

    // GL labels the account the QuickBooks way ("820 · CANA"); it isn't in the seeded chart.
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."9001,Check,2024-03-01,CHK-1,,Dues,820 · CANA,250.00,\n"
        .",,,,,,{$this->bank->name},,250.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(
        writeFile($csv),
        glContext($this, ['autoCreateAccounts' => true, 'accountTypesPath' => $listingPath]),
    );

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(1);

    $cana = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'CANA')->first();

    expect($cana)->not->toBeNull()
        ->and($cana->code)->toBe('820')
        ->and($cana->subtype)->toBe(AccountSubtype::Expense);
});

it('falls back to Other Asset for a CSV replay with no account listing', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."9002,Check,2024-03-01,CHK-2,,Dues,820 · CANA,250.00,\n"
        .",,,,,,{$this->bank->name},,250.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(
        writeFile($csv),
        glContext($this, ['autoCreateAccounts' => true]),
    );

    expect($result->isOk())->toBeTrue();

    $cana = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'CANA')->first();

    expect($cana->subtype)->toBe(AccountSubtype::OtherAsset);
});

it('links journal lines to a matching contact and tolerates unmatched names', function () {
    $contact = Contact::withoutGlobalScopes()->create([
        'company_id' => $this->company->id,
        'display_name' => 'Acme Co',
        'is_customer' => true,
    ]);

    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."7001,Invoice,2024-06-01,INV-7,Acme Co,,{$this->ar->name},300.00,\n"
        .",,,,,,{$this->income->name},,300.00\n"
        ."7002,Invoice,2024-06-02,INV-8,Ghost LLC,,{$this->ar->name},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(2)
        ->and($result->summary['unmatched_names'])->toBe(1);

    $arLine = JournalEntry::withoutGlobalScopes()
        ->where('company_id', $this->company->id)
        ->where('memo', 'like', '%INV-7%')
        ->first()
        ->lines()
        ->where('account_id', $this->ar->id)
        ->first();

    expect($arLine->contact_id)->toBe($contact->id);
});

it('refuses to replay into a locked company', function () {
    $this->company->forceFill(['lock_date' => CarbonImmutable::create(2024, 12, 31)])->save();

    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."8001,JE,2024-01-01,,,,{$this->bank->name},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('locked');
});

it('skips QuickBooks title/preamble rows above the column header', function () {
    $csv = "Acme Funeral Services,,,,,,,,\n"
        ."Journal,,,,,,,,\n"
        ."Accrual Basis,,,,,,,,\n"
        ."All Transactions,,,,,,,,\n"
        ."trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."9001,Invoice,2024-07-01,INV-7,Acme Co,,{$this->ar->name},425.00,\n"
        .",,,,,,{$this->income->name},,425.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(1);

    $this->ar->recomputeBalance();
    expect((int) $this->ar->balance_cents)->toBe(42500);
});

it('groups transactions by the start row when there is no Trans # column', function () {
    // Journal report without Trans #: a new transaction starts on the row carrying
    // Type/Date; split lines beneath it leave Type/Date blank.
    $csv = "type,date,num,name,memo,account,debit,credit\n"
        ."Invoice,2024-08-01,INV-1,Acme Co,,{$this->ar->name},600.00,\n"
        .",,,,,{$this->income->name},,600.00\n"
        ."Cheque,2024-08-02,101,Landlord,,{$this->bank->name},,200.00\n"
        .",,,,,{$this->income->name},200.00,\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(2)
        ->and($result->summary['transactions'])->toBe(2);
});

it('rejects a General Ledger report that has no Account column', function () {
    // The GL report carries a "Split" column, not "Account" — it can't be imported directly.
    $csv = "type,date,num,name,memo,split,amount,balance\n"
        ."General Journal,2024-07-31,YREN,,,102 · Cash,100.00,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->preview(writeFile($csv), glContext($this));

    expect($result->hasErrors())->toBeTrue()
        ->and($result->errors[0]['message'])->toContain('Journal');
});

it('imports multiple files into one chronological ledger and dedupes across them', function () {
    $fileA = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2025-03-01,A-1,Acme,,{$this->ar->name},300.00,\n"
        .",,,,,,{$this->income->name},,300.00\n";
    // fileB repeats transaction 1 (overlap) and adds an earlier-dated transaction 2.
    $fileB = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2025-03-01,A-1,Acme,,{$this->ar->name},300.00,\n"
        .",,,,,,{$this->income->name},,300.00\n"
        ."2,Invoice,2024-01-15,A-2,Acme,,{$this->ar->name},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(
        [writeFile($fileA), writeFile($fileB)],
        glContext($this),
    );

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(2)          // 2 unique transactions
        ->and($result->summary['skipped_duplicate'])->toBe(1)  // the repeated A-1
        ->and($result->summary['date_min'])->toBe('2024-01-15')
        ->and($result->summary['date_max'])->toBe('2025-03-01');

    $this->ar->recomputeBalance();
    expect((int) $this->ar->balance_cents)->toBe(40000); // 300 + 100
});

it('matches an account by its QuickBooks number when the label is "NUMBER · NAME"', function () {
    // QuickBooks exports the account column as e.g. "301 · Due to PA Franchise Corp".
    // Even with a mismatched name, the number must resolve to the right account.
    $label = $this->bank->code.' · Totally Different Label';

    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Deposit,2024-11-01,,,,{$label},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(1);

    $this->bank->recomputeBalance();
    expect((int) $this->bank->balance_cents)->toBe(10000);
});

it('auto-creates an account reusing the QuickBooks number and name from the label', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,JE,2024-11-02,,,,{$this->bank->name},100.00,\n"
        .",,,,,,8888 · Due to PA Franchise Corp,,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this, ['autoCreateAccounts' => true]));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(1);

    $created = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', 'Due to PA Franchise Corp')->first();
    expect($created)->not->toBeNull()
        ->and($created->code)->toBe('8888');
});

it('skips zero-dollar transactions such as voided cheques', function () {
    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Cheque,2024-10-01,3,Void,,{$this->bank->name},0.00,\n"
        .",,,,,,{$this->income->name},0.00,\n"
        ."2,Invoice,2024-10-02,INV-1,Acme Co,,{$this->ar->name},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['committed'])->toBe(1)
        ->and($result->summary['skipped_zero'])->toBe(1);
});

it('converts Windows-1252 encoded cells to valid UTF-8', function () {
    // QuickBooks exports cp1252: 0xE9 = é, 0x97 = em dash. These are not valid UTF-8.
    $name = 'Caf'.chr(0xE9).' Society';
    $memo = 'paid'.chr(0x97).'in full';

    $csv = "trans_no,type,date,num,name,memo,account,debit,credit\n"
        ."1,Invoice,2024-09-01,INV-1,{$name},{$memo},{$this->ar->name},100.00,\n"
        .",,,,,,{$this->income->name},,100.00\n";

    $result = app(GeneralLedgerReplayImporter::class)->commit(writeFile($csv), glContext($this));

    expect($result->isOk())->toBeTrue();

    $entry = JournalEntry::withoutGlobalScopes()->where('company_id', $this->company->id)->first();
    expect(mb_check_encoding($entry->memo, 'UTF-8'))->toBeTrue()
        ->and($entry->memo)->toContain('Café');

    // The whole result must be JSON-encodable (this is what Livewire does with the preview).
    expect(json_encode($result->summary))->not->toBeFalse();
});

it('returns a bounded preview for a large file with file-wide aggregates', function () {
    $rows = "trans_no,type,date,num,name,memo,account,debit,credit\n";
    for ($i = 1; $i <= 300; $i++) {
        $rows .= "{$i},Invoice,2024-01-15,INV-{$i},Acme Co,,{$this->ar->name},10.00,\n";
        $rows .= ",,,,,,{$this->income->name},,10.00\n";
    }

    $result = app(GeneralLedgerReplayImporter::class)->preview(writeFile($rows), glContext($this));

    expect($result->summary['transactions'])->toBe(300)
        ->and(count($result->previewRows))->toBe(200)
        ->and($result->summary['date_min'])->toBe('2024-01-15');
});
