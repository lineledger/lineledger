<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DataMigrationRun;
use App\Models\Invoice;
use App\Services\Migration\ImportContext;
use App\Services\Migration\Importers\OpenInvoicesImporter;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    Contact::create(['company_id' => $this->company->id, 'display_name' => '3000', 'is_customer' => true, 'is_active' => true]);

    $this->ctx = new ImportContext(
        company: $this->company,
        run: new DataMigrationRun(['company_id' => $this->company->id]),
        conversionDate: CarbonImmutable::create(2026, 7, 31),
    );
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('imports a QuickBooks Open Invoices report, netting credits per customer', function () {
    $csv = ",Type,Date,Num,P. O. #,Terms,Due Date,Aging,Open Balance\n"
        ."3000,,,,,,,,\n"
        .",Invoice,2024-03-10,INV-B,,,2024-04-10,,200.00\n"
        .",Payment,2024-03-15,,,,,,-50.00\n"
        ."Total 3000,,,,,,,,\n"
        ."2000,,,,,,,,\n"
        .",Invoice,2024-01-10,INV-A,,,2024-02-10,,100.00\n"
        .",Payment,2024-02-01,,,,,,-100.00\n"
        ."Total 2000,,,,,,,,\n"
        ."4000,,,,,,,,\n"
        .",Invoice,2024-05-01,INV-C,,,2024-06-01,,75.00\n"
        ."Total 4000,,,,,,,,\n";

    $path = tempnam(sys_get_temp_dir(), 'qboi').'.csv';
    file_put_contents($path, $csv);

    $result = app(OpenInvoicesImporter::class)->commit($path, $this->ctx);

    expect($result->isOk())->toBeTrue()
        ->and($result->summary['created'])->toBe(2); // 3000 (net 150) + 4000 (75); 2000 nets to 0

    // Customer 3000: invoice net to 150.
    $b = Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->where('invoice_no', 'INV-B')->first();
    expect($b->total_cents)->toBe(15000)
        ->and($b->is_opening_balance)->toBeTrue();

    // Customer 2000 nets to zero — no invoice.
    expect(Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->where('invoice_no', 'INV-A')->exists())->toBeFalse();

    // Customer 4000 didn't exist — auto-created from the file.
    $created = Contact::withoutGlobalScopes()->where('company_id', $this->company->id)->where('display_name', '4000')->first();
    expect($created)->not->toBeNull()
        ->and($created->is_customer)->toBeTrue();

    $ar = Account::withoutGlobalScopes()->where('company_id', $this->company->id)->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $ar->recomputeBalance();
    expect((int) $ar->balance_cents)->toBe(22500); // 150 + 75
});
