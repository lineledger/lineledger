<?php

use App\Models\Company;
use App\Models\CompanyApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Bind a company + API key the way AuthenticateApiKey would, so an MCP tool's
 * handle() can be invoked directly in a test. Reloads the company so DB-default
 * columns (e.g. fiscal_year_start_month) are populated. An empty abilities array
 * grants full access. Shared by all tests/Feature/Mcp tests.
 *
 * @param  array<int, string>  $abilities
 */
function bindMcpTenant(Company $company, array $abilities = []): void
{
    $company->refresh();

    ['key' => $key] = CompanyApiKey::mint($company, 'MCP test', null, $abilities);

    app()->instance('current_company', $company);
    app()->instance('current_api_key', $key);
}
