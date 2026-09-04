<?php

use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\Section;
use App\Models\Company;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * EnsureSectionAccess gates each company route by the section it belongs to.
 * Owner and Admin reach everything; Accountant reaches all but Settings; Custom
 * reaches only the sections stored on its membership.
 */
beforeEach(function () {
    $this->company = Company::factory()->create();
});

function sectionMember(Company $company, CompanyRole $role, ?array $sections = null): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => $role,
        'sections' => $sections,
    ]);

    return $user;
}

function visitSection(User $user, Company $company, string $routeName, array $params = []): TestResponse
{
    return test()->actingAs($user)->get(route($routeName, array_merge(['company' => $company->slug], $params)));
}

it('lets the owner reach every section', function () {
    $owner = sectionMember($this->company, CompanyRole::Owner);

    visitSection($owner, $this->company, 'customers.index')->assertSuccessful();
    visitSection($owner, $this->company, 'banking.register')->assertSuccessful();
    visitSection($owner, $this->company, 'reports.balance-sheet')->assertSuccessful();
    visitSection($owner, $this->company, 'settings.invoices')->assertSuccessful();
});

it('lets an accountant reach every section except settings', function () {
    $accountant = sectionMember($this->company, CompanyRole::Accountant);

    visitSection($accountant, $this->company, 'customers.index')->assertSuccessful();
    visitSection($accountant, $this->company, 'reports.balance-sheet')->assertSuccessful();
    visitSection($accountant, $this->company, 'lists.items')->assertSuccessful();
    visitSection($accountant, $this->company, 'settings.invoices')->assertForbidden();
});

it('restricts a custom member to its granted sections', function () {
    $custom = sectionMember($this->company, CompanyRole::Custom, [Section::Banking->value]);

    visitSection($custom, $this->company, 'banking.register')->assertSuccessful();
    visitSection($custom, $this->company, 'customers.index')->assertForbidden();
    visitSection($custom, $this->company, 'reports.balance-sheet')->assertForbidden();
    visitSection($custom, $this->company, 'settings.invoices')->assertForbidden();
});

it('leaves ungated routes accessible to any member', function () {
    $custom = sectionMember($this->company, CompanyRole::Custom, []);

    visitSection($custom, $this->company, 'dashboard')->assertSuccessful();
});

it('gates time-tracking routes by Payroll and transfers by Banking', function () {
    // Regression: time-entries, time-off-policies and transfers were not mapped
    // in Section::forRouteName(), so they slipped past EnsureSectionAccess (and
    // the platform-wide kill switch) entirely. They belong to Payroll / Banking.
    $company = Company::factory()->create([
        'address_country' => Country::Canada->value,
        'features_payroll' => true,
    ]);

    $payrollOnly = sectionMember($company, CompanyRole::Custom, [Section::Payroll->value]);
    $bankingOnly = sectionMember($company, CompanyRole::Custom, [Section::Banking->value]);

    visitSection($payrollOnly, $company, 'time-entries.index')->assertSuccessful();
    visitSection($payrollOnly, $company, 'time-off-policies.index')->assertSuccessful();
    visitSection($bankingOnly, $company, 'time-entries.index')->assertForbidden();

    visitSection($bankingOnly, $company, 'transfers.index')->assertSuccessful();
    visitSection($payrollOnly, $company, 'transfers.index')->assertForbidden();
});

it('gates sales-receipts by Customers and expenses by Vendors', function () {
    // Regression: sales-receipts (revenue + tax + cash) and expenses (pay-now
    // purchases) were not mapped in Section::forRouteName(), so they slipped past
    // EnsureSectionAccess entirely — any member could view/post them regardless
    // of role. They belong to Customers / Vendors like their siblings.
    $customersOnly = sectionMember($this->company, CompanyRole::Custom, [Section::Customers->value]);
    $vendorsOnly = sectionMember($this->company, CompanyRole::Custom, [Section::Vendors->value]);

    visitSection($customersOnly, $this->company, 'sales-receipts.index')->assertSuccessful();
    visitSection($vendorsOnly, $this->company, 'sales-receipts.index')->assertForbidden();

    visitSection($vendorsOnly, $this->company, 'expenses.index')->assertSuccessful();
    visitSection($customersOnly, $this->company, 'expenses.index')->assertForbidden();
});

it('grants recurring access when the member can reach either customers or vendors', function () {
    $salesOnly = sectionMember($this->company, CompanyRole::Custom, [Section::Customers->value]);
    $purchasesOnly = sectionMember($this->company, CompanyRole::Custom, [Section::Vendors->value]);
    $neither = sectionMember($this->company, CompanyRole::Custom, [Section::Banking->value]);

    visitSection($salesOnly, $this->company, 'recurring.index')->assertSuccessful();
    visitSection($purchasesOnly, $this->company, 'recurring.index')->assertSuccessful();
    visitSection($neither, $this->company, 'recurring.index')->assertForbidden();
});

it('gates the other names list by Lists', function () {
    // lists.other-names must live under the `lists` route prefix so the
    // Section::forRouteName() arm gates it; any other first segment would
    // fall to `default => []` and ship ungated.
    $bankingOnly = sectionMember($this->company, CompanyRole::Custom, [Section::Banking->value]);
    $accountant = sectionMember($this->company, CompanyRole::Accountant);

    visitSection($bankingOnly, $this->company, 'lists.other-names')->assertForbidden();
    visitSection($accountant, $this->company, 'lists.other-names')->assertSuccessful();
});
