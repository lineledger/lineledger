<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\TaxAppliesTo;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Item;
use App\Models\MembershipLevel;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

/*
 * An item, a contact or an account holds one default tax code for every document it
 * lands on. A document only takes it when it could have picked that code itself:
 * active, and applying in the document's direction. Cheques already screened account
 * defaults; PurchaseTaxCodeFilterTest and SalesTaxCodeFilterTest cover the pickers.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $code = fn (string $code, TaxAppliesTo $appliesTo, bool $active = true) => TaxCode::create([
        'company_id' => $this->company->id,
        'code' => $code,
        'name' => $code,
        'rate_basis_points' => 500,
        'applies_to' => $appliesTo->value,
        'is_active' => $active,
    ]);

    $this->saleOnly = $code('SALEONLY', TaxAppliesTo::SaleOnly);
    $this->purchaseOnly = $code('PURCHONLY', TaxAppliesTo::PurchaseOnly);
    $this->bothWays = $code('BOTHWAYS', TaxAppliesTo::Both);
    $this->alsoBothWays = $code('BOTHTOO', TaxAppliesTo::Both);
    $this->closed = $code('CLOSED', TaxAppliesTo::Both, active: false);

    $this->wrongWay = fn (string $direction) => $direction === 'sale' ? $this->purchaseOnly : $this->saleOnly;
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * Open a document form. A recurring schedule's direction comes from its type.
 *
 * @param  array{0: string, 1: string, 2?: string}  $form
 */
function taxDefaultForm(array $form, Company $company): mixed
{
    $page = Livewire::test($form[0], ['company' => $company]);

    return isset($form[2]) ? $page->set('document_type', $form[2]) : $page;
}

dataset('every document form', [
    'invoice' => [['pages::invoices.form', 'sale']],
    'estimate' => [['pages::estimates.form', 'sale']],
    'credit memo' => [['pages::credit-memos.form', 'sale']],
    'sales receipt' => [['pages::sales-receipts.form', 'sale']],
    'sales order' => [['pages::sales-orders.form', 'sale']],
    'invoice template' => [['pages::invoice-templates.form', 'sale']],
    'recurring invoice' => [['pages::recurring.form', 'sale', 'invoice']],
    'bill' => [['pages::bills.form', 'purchase']],
    'purchase order' => [['pages::purchase-orders.form', 'purchase']],
    'vendor credit' => [['pages::vendor-credits.form', 'purchase']],
    'recurring bill' => [['pages::recurring.form', 'purchase', 'bill']],
]);

dataset('forms that fill from an account', [
    'invoice' => [['pages::invoices.form', 'sale']],
    'estimate' => [['pages::estimates.form', 'sale']],
    'credit memo' => [['pages::credit-memos.form', 'sale']],
    'sales order' => [['pages::sales-orders.form', 'sale']],
    'invoice template' => [['pages::invoice-templates.form', 'sale']],
    'recurring invoice' => [['pages::recurring.form', 'sale', 'invoice']],
    'bill' => [['pages::bills.form', 'purchase']],
    'purchase order' => [['pages::purchase-orders.form', 'purchase']],
    'vendor credit' => [['pages::vendor-credits.form', 'purchase']],
    'recurring bill' => [['pages::recurring.form', 'purchase', 'bill']],
    'cheque' => [['pages::cheques.form', 'purchase']],
]);

dataset('forms that fill from a contact', [
    'invoice' => [['pages::invoices.form', 'sale']],
    'estimate' => [['pages::estimates.form', 'sale']],
    'credit memo' => [['pages::credit-memos.form', 'sale']],
    'sales receipt' => [['pages::sales-receipts.form', 'sale']],
    'sales order' => [['pages::sales-orders.form', 'sale']],
    'recurring invoice' => [['pages::recurring.form', 'sale', 'invoice']],
    'bill' => [['pages::bills.form', 'purchase']],
    'purchase order' => [['pages::purchase-orders.form', 'purchase']],
    'vendor credit' => [['pages::vendor-credits.form', 'purchase']],
    'recurring bill' => [['pages::recurring.form', 'purchase', 'bill']],
]);

it('skips an item default going the wrong way, and moves a usable second code up', function (array $form) {
    $item = Item::create([
        'name' => 'Widget',
        'type' => 'service',
        'default_tax_code_id' => ($this->wrongWay)($form[1])->id,
        'default_secondary_tax_code_id' => $this->bothWays->id,
    ]);

    $page = taxDefaultForm($form, $this->company)->set('lines.0.item_id', $item->id);

    expect($page->get('lines.0.tax_code_id'))->toBe($this->bothWays->id)
        ->and($page->get('lines.0.secondary_tax_code_id'))->toBeNull()
        ->and($page->get('lines.0.tax_code_ids'))->toBe([$this->bothWays->id]);
})->with('every document form');

it('keeps both item defaults that apply, in order', function (array $form) {
    $item = Item::create([
        'name' => 'Widget',
        'type' => 'service',
        'default_tax_code_id' => $this->alsoBothWays->id,
        'default_secondary_tax_code_id' => $this->bothWays->id,
    ]);

    $page = taxDefaultForm($form, $this->company)->set('lines.0.item_id', $item->id);

    expect($page->get('lines.0.tax_code_id'))->toBe($this->alsoBothWays->id)
        ->and($page->get('lines.0.secondary_tax_code_id'))->toBe($this->bothWays->id);
})->with('every document form');

it('skips a deactivated item default', function (array $form) {
    $item = Item::create(['name' => 'Widget', 'type' => 'service', 'default_tax_code_id' => $this->closed->id]);

    $page = taxDefaultForm($form, $this->company)->set('lines.0.item_id', $item->id);

    expect($page->get('lines.0.tax_code_id'))->toBeNull();
})->with('every document form');

it('fills a blank line from an account default only when it applies', function (array $form) {
    $account = fn (string $code, ?TaxCode $tax) => Account::create([
        'code' => $code,
        'name' => "Account {$code}",
        'subtype' => AccountSubtype::Expense->value,
        'type' => AccountSubtype::Expense->type()->value,
        'normal_balance' => AccountSubtype::Expense->type()->normalBalance()->value,
        'default_tax_code_id' => $tax?->id,
    ]);

    $wrong = $account('5901', ($this->wrongWay)($form[1]));
    $right = $account('5902', $this->bothWays);

    $page = taxDefaultForm($form, $this->company)->set('lines.0.account_id', $wrong->id);
    expect($page->get('lines.0.tax_code_id'))->toBeNull();

    $page->set('lines.0.account_id', $right->id);
    expect($page->get('lines.0.tax_code_id'))->toBe($this->bothWays->id);
})->with('forms that fill from an account');

it('fills blank lines from a contact default only when it applies', function (array $form) {
    $contact = fn (string $name, TaxCode $tax) => Contact::create([
        'display_name' => $name,
        'is_customer' => true,
        'is_vendor' => true,
        'default_tax_code_id' => $tax->id,
    ]);

    $wrong = $contact('Wrong way', ($this->wrongWay)($form[1]));
    $right = $contact('Right way', $this->bothWays);

    $page = taxDefaultForm($form, $this->company)->call('selectContact', $wrong->id);
    expect($page->get('lines.0.tax_code_id'))->toBeNull();

    $page->call('selectContact', $right->id);
    expect($page->get('lines.0.tax_code_id'))->toBe($this->bothWays->id);
})->with('forms that fill from a contact');

it('never overwrites a code already on the line', function () {
    $contact = Contact::create(['display_name' => 'Acme', 'is_customer' => true, 'default_tax_code_id' => $this->bothWays->id]);

    $page = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.tax_code_id', $this->alsoBothWays->id)
        ->call('selectContact', $contact->id);

    expect($page->get('lines.0.tax_code_id'))->toBe($this->alsoBothWays->id);
});

it('skips a membership level default going the wrong way', function () {
    $this->company->forceFill(['features_membership' => true])->save();

    $wrong = MembershipLevel::create(['name' => 'Wrong', 'default_dues_cents' => 1000, 'default_tax_code_id' => $this->purchaseOnly->id]);
    $right = MembershipLevel::create(['name' => 'Right', 'default_dues_cents' => 1000, 'default_tax_code_id' => $this->bothWays->id]);

    $page = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('lines.0.item_id', "level:{$wrong->id}");
    expect($page->get('lines.0.tax_code_id'))->toBeNull();

    $page->set('lines.0.item_id', "level:{$right->id}");
    expect($page->get('lines.0.tax_code_id'))->toBe($this->bothWays->id);
});

it('screens each bundle component on a sales form', function (string $component) {
    $good = Item::create(['name' => 'Good', 'type' => 'service', 'default_tax_code_id' => $this->bothWays->id]);
    $bad = Item::create(['name' => 'Bad', 'type' => 'service', 'default_tax_code_id' => $this->purchaseOnly->id]);
    $bundle = Item::create(['name' => 'Kit', 'type' => 'bundle']);
    $bundle->components()->create(['component_item_id' => $good->id, 'quantity' => 1]);
    $bundle->components()->create(['component_item_id' => $bad->id, 'quantity' => 1]);

    $page = Livewire::test($component, ['company' => $this->company])->set('lines.0.item_id', $bundle->id);

    $byItem = collect($page->get('lines'))->keyBy('item_id');

    expect($byItem[$good->id]['tax_code_id'])->toBe($this->bothWays->id)
        ->and($byItem[$bad->id]['tax_code_id'])->toBeNull();
})->with(['pages::invoices.form', 'pages::sales-receipts.form']);

it('compacts the pair so a usable second code is never left without a first', function () {
    expect(TaxCode::defaultsForPurchases($this->saleOnly->id, $this->bothWays->id))->toBe([$this->bothWays->id, null])
        ->and(TaxCode::defaultsForSales($this->purchaseOnly->id, $this->bothWays->id))->toBe([$this->bothWays->id, null])
        ->and(TaxCode::defaultsForSales($this->bothWays->id, $this->purchaseOnly->id))->toBe([$this->bothWays->id, null])
        ->and(TaxCode::defaultsForSales($this->bothWays->id, $this->alsoBothWays->id))->toBe([$this->bothWays->id, $this->alsoBothWays->id])
        ->and(TaxCode::defaultsForSales(null, null))->toBe([null, null])
        ->and(TaxCode::defaultForSales(null))->toBeNull()
        ->and(TaxCode::defaultForSales((string) $this->saleOnly->id))->toBe($this->saleOnly->id)
        ->and(TaxCode::defaultForPurchases($this->saleOnly->id))->toBeNull();
});
