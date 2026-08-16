<?php

use App\Enums\AccountSubtype;
use App\Enums\BankStatementFormat;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use App\Services\Banking\Import\StatementMetadataExtractor;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

function autofillOfx(): string
{
    return <<<'OFX'
        OFXHEADER:100
        DATA:OFXSGML
        VERSION:102

        <OFX>
        <BANKMSGSRSV1><STMTTRNRS><TRNUID>1<STATUS><CODE>0<SEVERITY>INFO</STATUS>
        <STMTRS><CURDEF>CAD
        <BANKACCTFROM><BANKID>900<ACCTID>123456789<ACCTTYPE>CHECKING</BANKACCTFROM>
        <BANKTRANLIST><DTSTART>20260101<DTEND>20260131
        <STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20260105120000<TRNAMT>2000.00<FITID>FIT-1<NAME>PAYROLL DEPOSIT</STMTTRN>
        <STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20260106<TRNAMT>-120.00<FITID>FIT-2<NAME>HYDRO</STMTTRN>
        </BANKTRANLIST>
        <LEDGERBAL><BALAMT>1875.50<DTASOF>20260131</LEDGERBAL>
        </STMTRS></STMTTRNRS></BANKMSGSRSV1>
        </OFX>
        OFX;
}

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->bankAccount = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('extracts the closing balance and statement date from an OFX statement', function () {
    $path = sys_get_temp_dir().'/'.uniqid('stmt_', true).'.ofx';
    file_put_contents($path, autofillOfx());

    $meta = app(StatementMetadataExtractor::class)->extract($path, BankStatementFormat::Ofx);

    expect($meta['endBalanceCents'])->toBe(187550)
        ->and($meta['endDate']->toDateString())->toBe('2026-01-31');

    @unlink($path);
});

it('returns blanks for a tabular format with no mapping', function () {
    $meta = app(StatementMetadataExtractor::class)->extract('/nonexistent.csv', BankStatementFormat::Csv);

    expect($meta)->toBe(['endBalanceCents' => null, 'endDate' => null, 'beginDate' => null]);
});

it('auto-fills the begin form from a dropped statement, then attaches it on begin', function () {
    $file = UploadedFile::fake()->createWithContent('jan.ofx', autofillOfx());

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bankAccount->id)
        ->set('statementForFill', $file)
        ->assertSet('endingBalance', '1875.50')
        ->assertSet('statementDate', '2026-01-31')
        ->assertSet('statementForFill', null)   // carried into newAttachments
        ->call('startReconciliation')
        ->assertHasNoErrors();

    // The dropped statement is attached to the new reconciliation.
    expect(Attachment::count())->toBe(1);
});

it('never clobbers a value the user already typed', function () {
    $file = UploadedFile::fake()->createWithContent('jan.ofx', autofillOfx());

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bankAccount->id)
        ->set('endingBalance', '999.99')   // user typed this first
        ->set('statementForFill', $file)
        ->assertSet('endingBalance', '999.99')      // untouched
        ->assertSet('statementDate', '2026-01-31'); // empty field still filled
});

it('falls back gracefully for an unreadable statement', function () {
    $file = UploadedFile::fake()->createWithContent('blank.ofx', 'not really ofx');

    Livewire::test('pages::banking.reconcile', ['company' => $this->company])
        ->set('account_id', $this->bankAccount->id)
        ->set('statementForFill', $file)
        ->assertSet('endingBalance', '')   // nothing to fill, no error
        ->assertHasNoErrors();
});
