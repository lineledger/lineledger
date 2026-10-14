<?php

use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

/**
 * Give $company a home currency saved before the two-decimal rule (e.g. JPY).
 * Written raw because the Company model now refuses it. Returns the refreshed
 * company. Shared by the home-currency restriction tests.
 */
function forceLegacyHomeCurrency(Company $company, string $currencyCode): Company
{
    DB::table('companies')->where('id', $company->id)->update(['currency_code' => $currencyCode]);

    return $company->refresh();
}

/**
 * A user who is a member of $company with $role — for edit-lock tests, which
 * need several people in one company.
 */
function editLockMember(Company $company, CompanyRole $role = CompanyRole::Accountant): User
{
    $user = User::factory()->create();
    $company->members()->attach($user, ['role' => $role->value]);

    return $user;
}

/**
 * A draft invoice for $company, built through SaveInvoice. Binds the company
 * as current (and leaves it bound) so scoped lookups work.
 */
function editLockDraftInvoice(Company $company): Invoice
{
    app()->instance('current_company', $company);

    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $customer = Contact::query()->create(['display_name' => 'Edit Lock Customer '.uniqid(), 'is_customer' => true]);

    return app(SaveInvoice::class)->handle([
        'contact_id' => $customer->id,
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
        'lines' => [[
            'account_id' => $income->id,
            'quantity' => '1',
            'unit_price_cents' => 10000,
        ]],
    ]);
}

/**
 * The opening <body> tag of a rendered app page, where the layout puts
 * per-user switches such as data-escape-back.
 */
function escapeBackBodyTag(string $html): string
{
    preg_match('/<body\b[^>]*>/s', $html, $match);

    return $match[0] ?? '';
}

/**
 * A posted, tax-free invoice of $cents for $customer — for the overpayment
 * refund tests. Expects the company to be bound as current.
 */
function overpaymentInvoice(Contact $customer, int $cents, ?string $date = null): Invoice
{
    $date ??= now()->subDays(10)->toDateString();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.uniqid(),
        'invoice_date' => $date,
        'due_date' => $date,
    ]);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Services',
        'quantity' => '1',
        'unit_price_cents' => $cents,
        'line_subtotal_cents' => $cents,
        'line_tax_cents' => 0,
        'line_total_cents' => $cents,
        'line_order' => 0,
    ]);

    app(InvoicePoster::class)->post($invoice);

    return $invoice->fresh();
}

/**
 * A posted payment of $cents from $customer to Undeposited Funds, applied in
 * full to $appliedTo when given (otherwise left unapplied: customer credit).
 * $method names a payment method ("Credit card", "Cheque") when it exists.
 * Expects the company to be bound as current.
 */
function overpaymentReceipt(Contact $customer, int $cents, ?Invoice $appliedTo = null, ?string $date = null, ?string $method = null): CustomerReceipt
{
    $undeposited = Account::query()->where('subtype', AccountSubtype::UndepositedFunds->value)->firstOrFail();

    $receipt = CustomerReceipt::create([
        'contact_id' => $customer->id,
        'receipt_no' => 'REC-'.uniqid(),
        'receipt_date' => $date ?? now()->subDays(5)->toDateString(),
        'deposit_to_account_id' => $undeposited->id,
        'payment_method_id' => $method !== null ? PaymentMethod::query()->where('name', $method)->value('id') : null,
        'amount_cents' => $cents,
    ]);

    if ($appliedTo !== null) {
        $receipt->applications()->create(['invoice_id' => $appliedTo->id, 'amount_cents' => $cents]);
    }

    app(ReceiptPoster::class)->post($receipt->fresh('applications'));

    return $receipt->fresh();
}
