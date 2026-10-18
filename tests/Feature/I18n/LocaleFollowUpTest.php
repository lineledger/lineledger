<?php

use App\Actions\Accounting\EnableCompanyCurrency;
use App\Enums\AccountSubtype;
use App\Enums\CashFlowActivity;
use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Enums\OrganizationType;
use App\Enums\TaxReturnAdjustmentKind;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\TaxAgency;
use App\Models\TaxCode;
use App\Models\User;
use App\Notifications\Companies\StripeConnectionBrokenNotification;
use App\Notifications\Portal\PortalLoginLinkNotification;
use App\Notifications\Sales\InvoiceSharedNotification;
use App\Services\GlobalSearch;
use App\Services\Reporting\InvoicePdfRenderer;
use App\Support\Locales;
use App\Support\Reporting\ComparisonPeriod;
use App\Support\Translation\PaymentMethodCopy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config(['mail.default' => 'array']);

    $this->user = User::factory()->create(['locale' => 'en']);
    $this->company = Company::factory()->forCountry(Country::Canada, 'ON')->create(['locale' => 'en']);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);
    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    Locales::apply('en');
});

function followUpSentMail(): Email
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

function followUpInvoice(Contact $customer): Invoice
{
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-FU-1',
        'invoice_date' => CarbonImmutable::create(2026, 9, 7),
        'due_date' => CarbonImmutable::create(2026, 10, 7),
    ]);

    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Consulting',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    return $invoice->fresh(['contact']);
}

// --- Email bodies render in the recipient's language, not just the subject ---

it('renders a route-notified invoice email body in the customer document language', function () {
    $customer = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true, 'locale' => 'fr']);
    $invoice = followUpInvoice($customer);

    // The queue worker runs in the app default language.
    Locales::apply('en');
    Notification::route('mail', 'buyer@acme.test')
        ->notifyNow(new InvoiceSharedNotification($invoice, $this->company, 'http://pay.test/x', 'Merci.'));

    $mail = followUpSentMail();
    $body = (string) $mail->getHtmlBody();

    expect($mail->getSubject())->toStartWith('Facture INV-FU-1')
        ->and($body)->toContain(e(__('Hello!', [], 'fr')))
        ->and($body)->toContain(e(__('View & pay invoice', [], 'fr')))
        ->and($body)->not->toContain('Hello!')
        ->and($body)->not->toContain(e('View & pay invoice'))
        ->and(app()->getLocale())->toBe('en');
});

it('keeps an English customer invoice email fully English', function () {
    $customer = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true, 'locale' => 'en']);

    Notification::route('mail', 'buyer@acme.test')
        ->notifyNow(new InvoiceSharedNotification(followUpInvoice($customer), $this->company, 'http://pay.test/x', 'Thanks.'));

    expect((string) followUpSentMail()->getHtmlBody())->toContain('Hello!')
        ->and(followUpSentMail()->getSubject())->toStartWith('Invoice INV-FU-1');
});

it('renders a contact notification body in the contact document language', function () {
    $contact = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true, 'locale' => 'fr']);

    $contact->notifyNow(new PortalLoginLinkNotification('http://pay.test/login', $this->company, 15));

    $body = (string) followUpSentMail()->getHtmlBody();

    // Laravel's own layout text ("Regards,") is rendered after toMail(), so it
    // only comes out French when the whole send runs in the contact's locale.
    expect($body)->toContain(e(__('View my invoices', [], 'fr')))
        ->and($body)->toContain('Cordialement,')
        ->and($body)->not->toContain('Regards,')
        ->and($body)->not->toContain('View my invoices');
});

it('renders a staff notification in the user interface language', function () {
    $this->user->update(['locale' => 'fr']);

    $this->user->notifyNow(new StripeConnectionBrokenNotification($this->company));

    $mail = followUpSentMail();

    expect($mail->getSubject())->toBe(__('Action needed: reconnect Stripe to keep accepting card payments', [], 'fr'))
        ->and((string) $mail->getHtmlBody())->toContain(e(__('Reconnect Stripe', [], 'fr')))
        ->and((string) $mail->getHtmlBody())->toContain('Cordialement,')
        ->and((string) $mail->getHtmlBody())->not->toContain('Regards,');
});

it('falls back to the app default language for users and to the document language for contacts', function () {
    $this->user->update(['locale' => null]);
    $contact = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $this->company->update(['locale' => 'fr']);

    expect($this->user->fresh()->preferredLocale())->toBe('en')
        ->and($contact->fresh()->preferredLocale())->toBe('fr');
});

// --- Staff vs portal language precedence ---

it('keeps the staff app in the staff language while a portal session is open', function () {
    $quebec = Company::factory()->forCountry(Country::Canada, 'QC')->create(['locale' => 'fr']);
    $quebec->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $quebec);
    $employee = Contact::create(['display_name' => 'Owner as employee', 'is_customer' => true]);

    $this->withSession([Auth::guard('customer')->getName() => $employee->id])
        ->get(route('dashboard', ['company' => $quebec->slug]))
        ->assertOk()
        ->assertSee('<html lang="en">', false);
});

it('shows the portal in the company language and never resolves the customer guard early', function () {
    $quebec = Company::factory()->forCountry(Country::Canada, 'QC')->create(['locale' => 'fr']);
    // A portal session from another organization whose contact prefers English.
    $foreign = Contact::create(['display_name' => 'Other org contact', 'is_customer' => true, 'locale' => 'en']);
    app()->forgetInstance('current_company');
    Auth::guard('web')->logout();

    $this->withSession([Auth::guard('customer')->getName() => $foreign->id])
        ->get(route('portal.login', ['company' => $quebec->slug]))
        ->assertOk()
        ->assertSee('<html lang="fr">', false);

    expect(Auth::guard('customer')->hasUser())->toBeFalse();
});

it('lets a visitor’s language choice win over the portal company language', function () {
    $quebec = Company::factory()->forCountry(Country::Canada, 'QC')->create(['locale' => 'fr']);
    app()->forgetInstance('current_company');
    Auth::guard('web')->logout();

    $this->withCookie(Locales::COOKIE, 'en')
        ->get(route('portal.login', ['company' => $quebec->slug]))
        ->assertOk()
        ->assertSee('<html lang="en">', false);
});

it('labels the "Default" interface language with the app default, not the current language', function () {
    $this->user->update(['locale' => 'fr']);
    Locales::apply('fr');

    Livewire::test('pages::settings.profile')
        ->assertSee(__('Default (:locale)', ['locale' => 'English'], 'fr'));
});

// --- Settings → Invoices and the provincial number ---

it('does not print another agency’s number as a provincial number outside PST provinces', function () {
    $craAccount = Account::query()->where('code', '2200')->firstOrFail();
    TaxAgency::query()->where('payable_account_id', $craAccount->id)->firstOrFail()
        ->update(['name' => 'CRA', 'registration_number' => '123456789RT0001']);
    $this->company->update(['tax_number' => '123456789RT0001']);

    $customer = Contact::create(['display_name' => 'Acme', 'is_customer' => true]);
    $invoice = followUpInvoice($customer);
    $invoice->lines()->first()->update(['tax_code_id' => TaxCode::where('code', 'HST-ON')->firstOrFail()->id]);
    $html = app(InvoicePdfRenderer::class)->html($this->company->fresh(), $invoice->fresh(['contact', 'lines.taxCode.agency']));

    expect($this->company->fresh()->provincialTaxNumber())->toBeNull()
        ->and($html)->not->toContain('PST No.');
});

// --- Display-only translation stays safe and matches main in English ---

it('shows the tax-code name, not just its code, in the entry form tax summary', function () {
    $bc = Company::factory()->forCountry(Country::Canada, 'BC')->create();
    $bc->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $bc);
    $customer = Contact::factory()->create(['is_customer' => true]);
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    $component = Livewire::test('pages::invoices.form', ['company' => $bc])
        ->set('contact_id', $customer->id)
        ->set('lines', [[
            'item_id' => null,
            'account_id' => $income->id,
            'description' => 'Service',
            'service_date' => '',
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount_pct' => '',
            'markup_pct' => '',
            'tax_code_id' => $gst->id,
            'secondary_tax_code_id' => null,
            'class_id' => null,
            'location_id' => null,
            'subtotal' => 0,
            'tax' => 500,
            'secondary_tax' => 0,
            'total' => 0,
        ]]);

    $rows = $component->instance()->taxBreakdown();

    expect($rows)->not->toBeEmpty()
        ->and($rows[0]['label'])->toBe((string) $gst->name);
});

it('passes a name through unchanged when it matches a Laravel translation group', function () {
    $account = Account::query()->where('subtype', AccountSubtype::Expense->value)->firstOrFail();
    $account->update(['name' => 'validation']);
    $code = TaxCode::query()->firstOrFail();
    $code->update(['code' => 'auth', 'name' => 'passwords']);

    expect($account->fresh()->displayName())->toBe('validation')
        ->and($code->fresh()->label())->toBe('auth')
        ->and($code->fresh()->displayName())->toBe('passwords');
});

it('keeps English relative times worded as on main', function () {
    Locales::apply('en');

    expect(CarbonImmutable::now()->addDays(3)->addHour()->diffForHumans())->toContain('from now');
});

it('translates each GIFI label once and uppercases French headings correctly', function () {
    $this->company->update(['organization_type' => OrganizationType::Corporation->value, 'fiscal_year_start_month' => 1]);
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $expense->update(['gifi_code' => '8910']);

    $entry = JournalEntry::create(['entry_no' => 'JE-FU-1', 'entry_date' => now()->startOfYear()->addMonth()->toDateString(), 'is_posted' => true]);
    $entry->lines()->create(['account_id' => $expense->id, 'debit_cents' => 50000, 'credit_cents' => 0, 'line_order' => 0]);
    $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => 0, 'credit_cents' => 50000, 'line_order' => 1]);

    $page = Livewire::test('pages::reports.gifi', ['company' => $this->company])->instance();

    $html = Locales::using('fr', fn (): string => view('pdf.reports.gifi', [
        'company' => $this->company,
        'report' => $page->report(),
        'startDate' => $page->startDate,
        'endDate' => $page->endDate,
        'title' => 'GIFI',
    ])->render());

    expect(__('Rental', [], 'fr'))->toBe('Location')
        ->and($html)->toContain('Location')
        ->and($html)->not->toContain('Emplacement')
        ->and($html)->not->toMatch('/\p{Lu}{2,}\p{Ll}\p{Lu}{2,}/u');
});

it('stores English names when a French-speaking user turns on a foreign currency', function () {
    Locales::apply('fr');

    app(EnableCompanyCurrency::class)->handle($this->company, 'USD');

    $accounts = Account::query()->where('currency_code', 'USD')->get(['name', 'description']);

    expect($accounts)->not->toBeEmpty();
    foreach ($accounts as $account) {
        expect($account->name.' '.$account->description)->not->toContain('Comptes')
            ->and($account->name.' '.$account->description)->not->toContain('Fournisseurs');
    }
});

// --- Strings looked up through a variable have French entries ---

it('has French for labels that are translated through a variable', function () {
    $english = [
        ...array_map(fn (CashFlowActivity $a): string => Locales::using('en', fn () => $a->label()), CashFlowActivity::cases()),
        ...array_map(fn (TaxReturnAdjustmentKind $k): string => Locales::using('en', fn () => $k->label()), TaxReturnAdjustmentKind::cases()),
        Locales::using('en', fn () => ComparisonPeriod::label(ComparisonPeriod::PriorPeriod)),
        Locales::using('en', fn () => ComparisonPeriod::label(ComparisonPeriod::PriorYear)),
        ...Locales::using('en', fn () => PaymentMethodCopy::names()),
        ...array_map(fn (string $g): string => Locales::using('en', fn () => GlobalSearch::groupLabel($g)), ['invoices', 'credit_memos', 'bills', 'bill_payments', 'receipts', 'cheques', 'deposits', 'journal_entries', 'accounts', 'items', 'tax_returns']),
    ];

    $catalog = json_decode((string) file_get_contents(lang_path('fr.json')), true, flags: JSON_THROW_ON_ERROR);
    $missing = array_values(array_filter($english, fn (string $key): bool => ! array_key_exists($key, $catalog)));

    expect($missing)->toBeEmpty('Missing French for: '.implode(' | ', $missing));
});
