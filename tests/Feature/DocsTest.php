<?php

use App\Models\User;
use Illuminate\Support\Facades\File;

test('guests are redirected to the login page from any docs route', function () {
    $this->get('/docs')->assertRedirect(route('login'));
    $this->get(route('docs.customers'))->assertRedirect(route('login'));
});

test('the /docs URL redirects to the getting started page', function () {
    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->get('/docs')
        ->assertRedirect(route('docs.getting-started'));
});

test('authenticated users can visit each documentation page', function (string $routeName) {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route($routeName));

    $response->assertOk();
})->with([
    'docs.getting-started',
    'docs.creating-a-company',
    'docs.dashboard',
    'docs.customers',
    'docs.members',
    'docs.estimates',
    'docs.sales-orders',
    'docs.recurring',
    'docs.sales-receipts',
    'docs.customer-portal',
    'docs.vendors',
    'docs.purchase-orders',
    'docs.employees',
    'docs.payroll',
    'docs.accounting',
    'docs.banking',
    'docs.inventory',
    'docs.fixed-assets',
    'docs.multi-currency',
    'docs.reports',
    'docs.budgets',
    'docs.fundraising',
    'docs.tax-returns',
    'docs.documents',
    'docs.inbox',
    'docs.lists',
    'docs.settings',
    'docs.migration',
    'docs.api',
]);

test('the docs nav lists every topic on a page', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('docs.getting-started'));

    $response->assertOk();

    foreach (['Getting started', 'Create an organization', 'Dashboard', 'Customers', 'Members', 'Estimates', 'Sales orders', 'Recurring', 'Sales receipts', 'Customer portal', 'Vendors', 'Purchase orders', 'Employees', 'Payroll', 'Accounting', 'Banking', 'Inventory', 'Fixed assets', 'Multi-currency', 'Reports', 'Budgets', 'Fundraising', 'Tax returns', 'Documents', 'Inbox', 'Lists', 'Settings', 'Import from QuickBooks', 'API'] as $label) {
        $response->assertSeeText($label);
    }
});

test('the customers page renders the step-by-step manual format', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('docs.customers'));

    $response->assertOk();
    $response->assertSeeText('To create an invoice:');
    $response->assertSeeText('To record a payment:');
    $response->assertSee('docs/screenshots/customers/', false);
});

test('the payroll page renders the step-by-step manual format', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('docs.payroll'));

    $response->assertOk();
    $response->assertSeeText('To run payroll:');
    $response->assertSeeText('Quebec payroll');
    $response->assertSee('docs/screenshots/payroll/', false);
});

test('the creating a company page renders the wizard walkthrough', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('docs.creating-a-company'));

    $response->assertOk();
    $response->assertSeeText('To create an organization:');
    $response->assertSeeText('Sole proprietorship');
    $response->assertSeeText('Corporation');
    $response->assertSeeText('Non-profit corporation');
    $response->assertSeeText('Unincorporated association');
    $response->assertSee('docs/screenshots/creating-a-company/', false);
});

test('the members page renders the step-by-step manual format', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('docs.members'));

    $response->assertOk();
    $response->assertSeeText('To add a member:');
    $response->assertSee('docs/screenshots/members/', false);
});

test('every screenshot referenced by a docs page exists on disk', function () {
    $pages = File::glob(resource_path('views/pages/docs/*.blade.php'));

    expect($pages)->not->toBeEmpty();

    foreach ($pages as $page) {
        preg_match_all("/asset\\('(docs\\/screenshots\\/[^']+)'\\)/", File::get($page), $matches);

        foreach ($matches[1] as $relativePath) {
            expect(File::exists(public_path($relativePath)))
                ->toBeTrue("Missing screenshot referenced in {$page}: public/{$relativePath}");
        }
    }
});
