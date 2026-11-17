<?php

use App\Enums\AccountSubtype;
use App\Models\Account;
use App\Models\Company;
use App\Services\Migration\AccountResolver;
use App\Services\Migration\Importers\ChartOfAccountsImporter;

beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $this->importer = app(ChartOfAccountsImporter::class);
    expect($this->importer->commitForCompany(quickBooksSubAccountListingCsv(asFile: true), $this->company)->errors)->toBe([]);

    $this->account = fn (string $code) => Account::withoutGlobalScopes()
        ->where('company_id', $this->company->id)->where('code', $code)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('keeps two accounts with the same name apart by their parents (#229)', function () {
    $resolver = new AccountResolver($this->company->id);

    $topLevel = ($this->account)('Domian Name Reg/Rene');
    $nested = ($this->account)('Domian Name Reg/Re-2');

    expect($resolver->find('Domian Name Reg/Renew')?->id)->toBe($topLevel->id)
        ->and($resolver->find('Pass Through Expense:Hosting:Domian Name Reg/Renew')?->id)->toBe($nested->id)
        ->and($resolver->find('pass through expense:hosting:domian name reg/renew')?->id)->toBe($nested->id)
        ->and($resolver->find('Pass Through Expense:Hosting')?->id)->toBe(($this->account)('Hosting')->id);
});

it('matches a path written with QuickBooks account numbers', function () {
    $resolver = new AccountResolver($this->company->id);

    expect($resolver->find('9860 · Pass Through Expense:9861 · Hosting:9863 · Domian Name Reg/Renew')?->id)
        ->toBe(($this->account)('Domian Name Reg/Re-2')->id);
});

it('still falls back to the leaf name for a path the chart does not have', function () {
    $resolver = new AccountResolver($this->company->id);

    expect($resolver->find('Somewhere Else:Email Hosting')?->id)->toBe(($this->account)('Email Hosting')->id);
});

it('types an auto-created sub-account from its path, not another account with its name', function () {
    // The leaf name "Backups" ends up typed Expense (the last row wins); only the
    // path tells the replay the sub-account is Cost of Goods Sold.
    $listing = quickBooksSubAccountListingCsv()
        ."Pass Through Expense:Hosting:Backups,Cost of Goods Sold,,\n"
        ."Backups,Expense,,\n";
    $path = tempnam(sys_get_temp_dir(), 'qbsub').'.csv';
    file_put_contents($path, $listing);

    $hints = $this->importer->typeHints($path);
    $resolver = new AccountResolver($this->company->id, autoCreate: true, typeHints: $hints['byName'], typeHintsByCode: $hints['byCode']);

    expect($resolver->resolveOrCreate('9860 · Pass Through Expense:9861 · Hosting:9862 · Backups')?->subtype)
        ->toBe(AccountSubtype::CostOfGoodsSold);
});
