<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Enums\CustomerStatementType;
use App\Enums\Section;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceSetting;
use App\Models\User;
use App\Notifications\Sales\CustomerStatementNotification;
use App\Services\Posting\InvoicePoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['email' => 'owner@example.com']);
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->customer()->create([
        'display_name' => 'Rainbow Memorials',
        'email' => 'rain@example.com',
        'billing_line1' => '456 Harbour Rd',
        'billing_city' => 'Victoria',
    ]);

    $this->postInvoice = function (string $no, string $date, string $due, int $cents, array $extra = []): Invoice {
        $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
        $invoice = Invoice::create([
            'contact_id' => $this->customer->id,
            'invoice_no' => $no,
            'invoice_date' => CarbonImmutable::parse($date),
            'due_date' => CarbonImmutable::parse($due),
            ...$extra,
        ]);
        $invoice->lines()->create([
            'account_id' => $income->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => $cents,
            'line_subtotal_cents' => $cents,
            'line_tax_cents' => 0,
            'line_total_cents' => $cents,
            'line_order' => 0,
        ]);
        app(InvoicePoster::class)->post($invoice);

        return $invoice;
    };

    ($this->postInvoice)('INV-OLD', '2025-11-01', '2025-12-01', 10000, ['memo' => 'Autumn retainer', 'customer_po' => 'PO-OLD-1']);
    ($this->postInvoice)('INV-NEW', '2026-02-01', '2026-03-03', 4000, ['memo' => 'Winter plot', 'customer_po' => 'PO-NEW-2']);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function statementPage(object $test, array $query = []): Testable
{
    return Livewire::withQueryParams($query)
        ->test('pages::customers.statement', ['company' => $test->company, 'contact' => $test->customer]);
}

it('previews the open-invoices statement with every open invoice by default', function () {
    $response = $this->get(route('customers.statement', ['company' => $this->company->slug, 'contact' => $this->customer->id]));

    $response->assertOk()
        ->assertSee('data-test="statement-preview"', escape: false)
        ->assertSee('Rainbow Memorials')
        ->assertSee('456 Harbour Rd')
        ->assertSee('INV-OLD')
        ->assertSee('INV-NEW')
        ->assertSee('Autumn retainer')
        ->assertSee('data-test="statement-as-of"', escape: false)
        ->assertDontSee('data-test="statement-forward-row"', escape: false)
        ->assertDontSee('PO-OLD-1')
        ->assertSee('140.00');

    expect($response->getContent())->not->toContain('<flux:');
});

it('opens with the All period, the customer email and today as the statement date', function () {
    $today = $this->company->currentDateTime()->toDateString();

    statementPage($this)
        ->assertSet('type', 'open-invoices')
        ->assertSet('preset', 'all')
        ->assertSet('endDate', $today)
        ->assertSet('cols', null)
        ->assertSet('columns', ['memo', 'due_date', 'original'])
        ->assertSet('toEmail', 'rain@example.com');
});

it('carries older open invoices forward when a start date is set', function () {
    statementPage($this, ['start' => '2026-01-01', 'end' => '2026-06-01'])
        ->assertSet('preset', 'custom')
        ->assertSee('Balance forward')
        ->assertSeeHtml('data-test="statement-forward-row"')
        ->assertSeeHtml('data-test="statement-period"')
        ->assertSee('1/1/2026 – 6/1/2026')
        ->assertSee('INV-NEW')
        ->assertDontSee('INV-OLD')
        // The total still ties to the full balance owed.
        ->assertSeeHtml('data-test="statement-total">$140.00');
});

it('ignores the start date under the All period', function () {
    statementPage($this, ['start' => '2026-01-01', 'end' => '2026-06-01', 'range' => 'all'])
        ->assertSet('preset', 'all')
        ->assertSee('INV-OLD')
        ->assertDontSeeHtml('data-test="statement-forward-row"');
});

it('switches to account activity with its own period and columns', function () {
    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        ...InvoiceSetting::defaults(),
        'statement_columns' => ['activity' => ['po']],
    ]);

    $fyStart = $this->company->currentDateTime()->startOfYear()->toDateString();

    statementPage($this)
        ->set('type', 'activity')
        ->assertSet('preset', 'this_fiscal_year_to_date')
        ->assertSet('startDate', $fyStart)
        ->assertSet('columns', ['po'])
        ->assertSee('Opening balance')
        ->assertSeeHtml('data-test="statement-column-header-po"')
        ->assertDontSeeHtml('data-test="statement-column-header-type"')
        ->assertSeeHtml('data-test="statement-period"')
        ->set('type', 'open-invoices')
        ->assertSet('preset', 'all')
        ->assertSet('columns', ['memo', 'due_date', 'original']);
});

it('keeps a range the user picked when switching type', function () {
    statementPage($this, ['start' => '2026-01-01', 'end' => '2026-06-01'])
        ->set('type', 'activity')
        ->assertSet('preset', 'custom')
        ->assertSet('startDate', '2026-01-01')
        ->assertSet('endDate', '2026-06-01');
});

it('takes columns from the query string and toggles them per run', function () {
    statementPage($this, ['cols' => 'po,bogus'])
        ->assertSet('columns', ['po'])
        ->assertSet('cols', 'po')
        ->assertSeeHtml('data-test="statement-column-header-po"')
        ->assertDontSeeHtml('data-test="statement-column-header-memo"')
        ->assertSee('PO-OLD-1')
        ->set('columns', ['terms', 'po'])
        ->assertSet('columns', ['po', 'terms'])
        ->assertSet('cols', 'po,terms')
        ->set('columns', [])
        ->assertSet('cols', 'none')
        ->assertDontSeeHtml('data-test="statement-column-header-po"')
        // Back to the default: the URL goes clean again.
        ->set('columns', ['memo', 'due_date', 'original'])
        ->assertSet('cols', null);

    statementPage($this, ['cols' => 'none'])->assertSet('columns', []);
});

it('hands the preview\'s exact type, dates and columns to the PDF links', function () {
    $component = statementPage($this, ['start' => '2026-01-01', 'end' => '2026-06-01'])
        ->set('columns', ['po']);

    $expected = route('customers.statement.print', [
        'company' => $this->company->slug,
        'contact' => $this->customer->id,
        'type' => 'open-invoices',
        'end' => '2026-06-01',
        'cols' => 'po',
        'as_of' => '2026-06-01',
        'start' => '2026-01-01',
    ]);

    $component->assertSeeHtml('href="'.e($expected).'"');

    // Under All the open-invoices link carries no start.
    $all = statementPage($this)->instance()->pdfParams();

    expect($all)->not->toHaveKey('start')
        ->and($all['cols'])->toBe('memo,due_date,original');
});

it('survives nonsense dates in the query string', function () {
    $today = $this->company->currentDateTime()->toDateString();

    $component = statementPage($this, ['start' => 'nope', 'end' => '2026-13-99', 'range' => 'custom']);

    $component->assertOk();

    expect($component->instance()->pdfParams()['end'])->toBe($today);
});

it('saves the column choice as the company default for that type', function () {
    statementPage($this)
        ->assertDontSeeHtml('data-test="statement-save-default-columns"')
        ->set('columns', ['po', 'memo'])
        ->assertSeeHtml('data-test="statement-save-default-columns"')
        ->call('saveDefaultColumns')
        ->assertSet('cols', null)
        ->assertDontSeeHtml('data-test="statement-save-default-columns"');

    $settings = InvoiceSetting::where('company_id', $this->company->id)->firstOrFail();

    expect($settings->statement_columns)->toBe(['open-invoices' => ['po', 'memo']])
        ->and($settings->statementColumnsFor(CustomerStatementType::OpenInvoices))->toBe(['po', 'memo'])
        ->and($settings->statementColumnsFor(CustomerStatementType::Activity))->toBeNull()
        // A first save seeds the rest of the invoice settings from their defaults.
        ->and($settings->show_tax_column)->toBe(InvoiceSetting::defaults()['show_tax_column']);

    // The next visit starts from the saved default.
    $this->company->unsetRelation('invoiceSettings');
    statementPage($this)->assertSet('columns', ['po', 'memo']);
});

it('keeps the other type\'s saved default when saving one', function () {
    InvoiceSetting::updateOrCreate(['company_id' => $this->company->id], [
        ...InvoiceSetting::defaults(),
        'statement_columns' => ['activity' => ['po']],
    ]);

    statementPage($this)->set('columns', ['terms'])->call('saveDefaultColumns');

    expect(InvoiceSetting::where('company_id', $this->company->id)->value('statement_columns'))
        ->toBe(['activity' => ['po'], 'open-invoices' => ['terms']]);
});

it('does not let a member without settings access save the default', function (CompanyRole $role, array $sections) {
    $member = User::factory()->create();
    $this->company->memberships()->create([
        'user_id' => $member->id,
        'role' => $role,
        'sections' => $sections,
    ]);
    $this->actingAs($member);

    statementPage($this)
        ->set('columns', ['po'])
        ->assertDontSeeHtml('data-test="statement-save-default-columns"')
        ->call('saveDefaultColumns')
        ->assertForbidden();

    expect(InvoiceSetting::where('company_id', $this->company->id)->value('statement_columns'))->toBeNull();
})->with([
    'accountant' => [CompanyRole::Accountant, []],
    // Settings section access alone isn't enough: Invoice settings saves need UpdateCompany.
    'custom with settings' => [CompanyRole::Custom, [Section::Customers->value, Section::Settings->value]],
]);

it('404s for another company\'s contact', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $foreign = Contact::factory()->customer()->create();
    app()->instance('current_company', $this->company);

    $this->get(route('customers.statement', ['company' => $this->company->slug, 'contact' => $foreign->id]))
        ->assertNotFound();
});

it('404s for a vendor-only contact', function () {
    $vendor = Contact::factory()->vendor()->create();

    $this->get(route('customers.statement', ['company' => $this->company->slug, 'contact' => $vendor->id]))
        ->assertNotFound();
});

it('is closed to a member without customer access', function () {
    $reportsOnly = User::factory()->create();
    $this->company->memberships()->create([
        'user_id' => $reportsOnly->id,
        'role' => CompanyRole::Custom,
        'sections' => [Section::Reports->value],
    ]);
    $this->actingAs($reportsOnly);

    $this->get(route('customers.statement', ['company' => $this->company->slug, 'contact' => $this->customer->id]))
        ->assertForbidden();
});

it('emails the statement with the previewed range and columns', function () {
    Notification::fake();

    statementPage($this, ['start' => '2026-01-01', 'end' => '2026-06-01', 'cols' => 'po'])
        ->set('toEmail', 'rain@example.com, second@example.com')
        ->set('ccEmail', 'bookkeeper@example.com')
        ->set('ccSelf', true)
        ->set('emailMessage', 'Here is your statement.')
        ->call('emailStatement')
        ->assertHasNoErrors();

    Notification::assertSentOnDemand(
        CustomerStatementNotification::class,
        function (CustomerStatementNotification $notification, array $channels, object $notifiable): bool {
            return $notifiable->routes['mail'] === ['rain@example.com', 'second@example.com']
                && $notification->cc === ['bookkeeper@example.com', 'owner@example.com']
                && $notification->type === CustomerStatementType::OpenInvoices
                && $notification->start === '2026-01-01'
                && $notification->end === '2026-06-01'
                && $notification->columns === ['po']
                && $notification->message === 'Here is your statement.'
                && $notification->contact->is($this->customer);
        },
    );
});

it('emails an all-open-invoices statement without a lower bound', function () {
    Notification::fake();

    statementPage($this)->call('emailStatement')->assertHasNoErrors();

    Notification::assertSentOnDemand(
        CustomerStatementNotification::class,
        fn (CustomerStatementNotification $notification): bool => $notification->start === null
            && $notification->columns === ['memo', 'due_date', 'original'],
    );
});

it('rejects a malformed recipient address', function () {
    Notification::fake();

    statementPage($this)
        ->set('toEmail', 'not-an-email')
        ->call('emailStatement')
        ->assertHasErrors(['toEmail']);

    Notification::assertNothingSent();
});

it('flags a customer whose automated invoice emails are off', function () {
    $this->customer->update(['invoice_emails_enabled' => false]);

    statementPage($this)->assertSeeHtml('data-test="statement-opted-out"');
});

it('links the customers list to the statement page', function () {
    $response = $this->get(route('customers.index', ['company' => $this->company->slug]));

    $expected = route('customers.statement', ['company' => $this->company->slug, 'contact' => $this->customer->id]);

    $response->assertOk()
        ->assertSee('data-test="customer-open-balance"', escape: false)
        ->assertSee('data-test="customer-statement-button"', escape: false)
        ->assertSee('href="'.$expected.'"', escape: false)
        ->assertDontSee('customer-statement-modal', escape: false);
});
