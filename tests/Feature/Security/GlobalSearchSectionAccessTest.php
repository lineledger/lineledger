<?php

use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\User;
use App\Services\GlobalSearch;
use App\Support\SiteSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * Global search returns only the result groups whose pages the searcher can
 * open — the sections EnsureSectionAccess checks for those pages (R07, audit
 * D7Security-06: it used to return every section's records to any member).
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);
    URL::defaults(['company' => $this->company->slug]);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('returns every group to an owner', function () {
    $owner = User::factory()->create();
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    expect(array_keys(app(GlobalSearch::class)->search('zz', $owner)))->toEqualCanonicalizing([
        'invoices', 'credit_memos', 'bills', 'bill_payments', 'receipts', 'cheques', 'deposits',
        'journal_entries', 'contacts', 'accounts', 'items', 'tax_returns',
    ]);
});

it('returns only the groups a one-section member can open', function (Section $section, array $groups) {
    $member = customSectionsMember($this->company, [$section]);

    expect(array_keys(app(GlobalSearch::class)->search('zz', $member)))->toEqualCanonicalizing($groups);
})->with([
    'customers' => [Section::Customers, ['invoices', 'credit_memos', 'receipts', 'contacts']],
    'vendors' => [Section::Vendors, ['bills', 'bill_payments', 'contacts']],
    'employees' => [Section::Employees, ['bills', 'contacts']],
    'banking' => [Section::Banking, ['cheques', 'deposits']],
    'accounting' => [Section::Accounting, ['journal_entries', 'accounts']],
    'reports' => [Section::Reports, ['accounts', 'tax_returns']],
    'lists' => [Section::Lists, ['items', 'contacts']],
    'payroll' => [Section::Payroll, []],
    'inventory' => [Section::Inventory, []],
    'inbox' => [Section::Inbox, []],
    'fundraising' => [Section::Fundraising, []],
    'documents' => [Section::Documents, []],
    'settings' => [Section::Settings, []],
]);

it('lists only the contacts whose role the searcher can open, linking to a page they can reach', function () {
    Contact::create(['display_name' => 'Zephyr Customer', 'is_customer' => true]);
    Contact::create(['display_name' => 'Zephyr Vendor', 'is_vendor' => true]);
    Contact::create(['display_name' => 'Zephyr Employee', 'is_employee' => true]);

    $member = customSectionsMember($this->company, [Section::Customers]);
    $contacts = app(GlobalSearch::class)->search('Zephyr', $member)['contacts'];

    // The customer's AR statement is a Reports page this member can't open,
    // so the result falls back to the customer list.
    expect($contacts->pluck('label')->all())->toBe(['Zephyr Customer'])
        ->and($contacts->first()->url)->toBe(route('customers.index'));
});

it('shows vendor bills to Vendors members and reimbursements to Employees members', function () {
    $contact = Contact::create(['display_name' => 'Payee', 'is_vendor' => true, 'is_employee' => true]);

    foreach ([['ZB-1', BillType::Vendor], ['ZR-1', BillType::Reimbursement]] as [$no, $type]) {
        Bill::create([
            'contact_id' => $contact->id,
            'bill_type' => $type,
            'bill_no' => $no,
            'bill_date' => CarbonImmutable::create(2026, 5, 1),
            'due_date' => CarbonImmutable::create(2026, 5, 31),
        ]);
    }

    $vendors = customSectionsMember($this->company, [Section::Vendors]);
    $employees = customSectionsMember($this->company, [Section::Employees]);

    $search = app(GlobalSearch::class);

    expect($search->search('ZB', $vendors)['bills']->pluck('label')->all())->toBe(['ZB-1'])
        ->and($search->search('ZR', $vendors)['bills'])->toBeEmpty()
        ->and($search->search('ZR', $employees)['bills']->pluck('label')->all())->toBe(['ZR-1'])
        ->and($search->search('ZB', $employees)['bills'])->toBeEmpty();
});

it('hides a group when the site admin has switched its section off, even from an owner', function () {
    $owner = User::factory()->create();
    $this->company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);

    SiteSettings::set('disabled_sections', [Section::Banking->value]);

    $groups = array_keys(app(GlobalSearch::class)->search('zz', $owner));

    expect($groups)->not->toContain('cheques')
        ->and($groups)->not->toContain('deposits')
        ->and($groups)->toContain('invoices');
});
