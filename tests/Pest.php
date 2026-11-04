<?php

use App\Actions\Payroll\SaveEmployeePayrollProfile;
use App\Actions\Payroll\SavePayRun;
use App\Actions\Sales\SaveInvoice;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\Contact;
use App\Models\CustomerReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\PaymentMethod;
use App\Models\PayrollSchedule;
use App\Models\PayRun;
use App\Models\User;
use App\Services\Payroll\CalculatePayRun;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\PayRunPoster;
use App\Services\Posting\ReceiptPoster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Symfony\Component\Mailer\SentMessage;
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
 * A Custom-role member of $company holding only the given sections — for tests
 * of what one section's members can and cannot reach.
 *
 * @param  list<Section>  $sections
 */
function customSectionsMember(Company $company, array $sections): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);

    // Through Eloquent, so the `sections` array cast encodes the JSON (pivot
    // attach() would bypass the cast and double-encode it).
    $company->memberships()->create([
        'user_id' => $user->id,
        'role' => CompanyRole::Custom,
        'sections' => array_map(fn (Section $section) => $section->value, $sections),
    ]);

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

/**
 * Stamp $entry's lines on $rec's account as ticked by $rec — the state
 * BankReconciliationService::complete() leaves behind — and add them to its
 * marked set. Pair with a completed() factory rec to put an entry's bank line
 * "on a completed reconciliation" without balancing a real one.
 */
function claimLinesForReconciliation(BankReconciliation $rec, JournalEntry $entry): void
{
    $ids = $entry->lines()->where('account_id', $rec->account_id)->pluck('id')->map(fn ($id) => (int) $id)->all();

    JournalLine::query()->whereIn('id', $ids)->update([
        'bank_reconciliation_id' => $rec->id,
        'cleared_at' => now(),
    ]);

    $rec->forceFill(['marked_line_ids' => array_values(array_unique([...$rec->markedLineIds(), ...$ids]))])->save();
}

/**
 * Every email the array mailer has sent in this test, HTML and plain-text parts
 * together with entities decoded, for asserting where the links in them point.
 */
function sentMailText(): string
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()
        ->map(fn (SentMessage $sent): string => html_entity_decode(
            $sent->getOriginalMessage()->getHtmlBody().' '.$sent->getOriginalMessage()->getTextBody(),
            ENT_QUOTES | ENT_HTML5,
        ))
        ->implode("\n");
}

/**
 * All the text of a .docx — body, headers and footers — tags stripped, for the
 * statutory accounts Word export tests.
 */
function docxText(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'docx');
    file_put_contents($path, $bytes);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = '';
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^word/(document|header\d*|footer\d*)\.xml$#', $name)) {
            $xml .= ' '.$zip->getFromName($name);
        }
    }
    $zip->close();
    @unlink($path);

    // One space per paragraph, none between runs, so "5" + "th" reads "5th".
    $xml = preg_replace('#</w:p>#', ' ', $xml);

    return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1));
}

/**
 * Open $url as $user and return the snapshot of $component on that page: what
 * the browser keeps, and can post back to Livewire's update endpoint long after
 * the page loaded. Shared by the Livewire re-authorization tests.
 *
 * @param  array<string, mixed>  $session
 */
function livewireReplayCapture(User $user, string $url, string $component, array $session = []): string
{
    $html = test()->actingAs($user)->withSession($session)->get($url)->assertOk()->getContent();

    livewireReplayEndRequest();

    return livewireReplaySnapshotIn($html, $component);
}

/**
 * The snapshot of $component in rendered HTML: a full page, or the
 * effects.html of an update response.
 */
function livewireReplaySnapshotIn(string $html, string $component): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === $component) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No {$component} snapshot in the HTML.");
}

/**
 * Post $snapshot back to Livewire's update endpoint the way the browser does
 * (a JSON body and the X-Livewire header), with optional property updates and
 * method calls given as [method, ...params]. Unlike Livewire::test(), which
 * switches route middleware off, this runs the endpoint for real, so Livewire's
 * persistent route middleware and BindCurrentCompanyHook both apply.
 *
 * @param  array<int, array<int, mixed>>  $calls
 * @param  array<string, mixed>  $updates
 */
function livewireReplay(string $snapshot, array $calls = [], array $updates = []): TestResponse
{
    $payload = ['components' => [[
        'snapshot' => $snapshot,
        'updates' => (object) $updates,
        'calls' => array_map(fn (array $call): array => [
            'path' => '',
            'method' => array_shift($call),
            'params' => $call,
        ], $calls),
    ]]];

    $response = test()->call('POST', EndpointResolver::updatePath(), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_LIVEWIRE' => '1',
    ], json_encode($payload));

    livewireReplayEndRequest();

    return $response;
}

/**
 * Reset what a real request would not carry over. A test's requests share one
 * application, so drop the bound company and Livewire's per-request state (the
 * last route its persistent middleware ran for, the models it resolved), as
 * Livewire::test() does after each render.
 */
function livewireReplayEndRequest(): void
{
    app()->forgetInstance('current_company');

    Livewire::flushState();
}

/**
 * Save, calculate and post a one-employee pay run, for the year-end filing tests
 * (RL-1 slips, Record of Employment).
 */
function payrollFilingPostRun(PayrollSchedule $schedule, Contact $employee, string $periodStart, string $periodEnd, string $payDate): PayRun
{
    $run = app(SavePayRun::class)->handle([
        'payroll_schedule_id' => $schedule->id,
        'period_start_date' => $periodStart,
        'period_end_date' => $periodEnd,
        'pay_date' => $payDate,
        'lines' => [['contact_id' => $employee->id]],
    ]);
    app(CalculatePayRun::class)->calculate($run);
    app(PayRunPoster::class)->post($run->fresh());

    return $run->fresh(['lines']);
}

/**
 * XPath over a generated filing XML document (no namespaces), for the filing tests.
 */
function payrollFilingXpath(string $xml): DOMXPath
{
    $doc = new DOMDocument;
    expect($doc->loadXML($xml))->toBeTrue();

    return new DOMXPath($doc);
}

/**
 * Validate an XML document against an official XSD; returns libxml's messages,
 * empty when the document is valid.
 *
 * @return array<int, string>
 */
function payrollFilingSchemaErrors(string $xml, string $xsdPath): array
{
    $previous = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new DOMDocument;
    $valid = $doc->loadXML($xml) && $doc->schemaValidate($xsdPath);
    $errors = array_map(fn (LibXMLError $e): string => trim($e->message), libxml_get_errors());

    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $valid ? [] : ($errors !== [] ? $errors : ['The document did not validate.']);
}

/**
 * A salaried Quebec employee on the given schedule, optionally with standing pay
 * items (recurring earnings, deductions or employer-paid benefits), for the RL-1
 * filing tests.
 *
 * @param  array<int, array<string, mixed>>  $recurringItems
 */
function payrollFilingQuebecEmployee(PayrollSchedule $schedule, int $annualSalaryCents, array $recurringItems = [], string $name = 'Québec Employé'): Contact
{
    [$first, $last] = explode(' ', $name, 2) + [1 => ''];
    $employee = Contact::create(['display_name' => $name, 'first_name' => $first, 'last_name' => $last, 'is_employee' => true]);

    app(SaveEmployeePayrollProfile::class)->handle([
        'contact_id' => $employee->id,
        'sin' => '046454286',
        'hire_date' => '2025-01-06',
        'province_of_employment' => 'QC',
        'pay_basis' => 'salary',
        'annual_salary_cents' => $annualSalaryCents,
        'payroll_schedule_id' => $schedule->id,
        'td1_federal_claim_cents' => 1645200,
        'td1_provincial_claim_cents' => 1895200,
        'vacation_policy' => 'accrue',
        'vacation_rate_bp' => 400,
        'recurring_items' => $recurringItems,
    ]);

    return $employee;
}
