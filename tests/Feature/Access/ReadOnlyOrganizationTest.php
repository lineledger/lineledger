<?php

use App\Actions\Recurring\SaveRecurringDocument;
use App\Actions\Sales\SendInvoiceReminder;
use App\Contracts\CompanyAccess;
use App\Enums\AccountSubtype;
use App\Enums\Activity;
use App\Enums\CompanyRole;
use App\Enums\PermissionAction;
use App\Enums\PredefinedRole;
use App\Enums\SupportTicketType;
use App\Exceptions\Access\CompanyReadOnlyException;
use App\Jobs\ExportCompanyDataJob;
use App\Jobs\GenerateRecurringDocumentsForCompany;
use App\Jobs\SendPaymentRemindersForCompany;
use App\Mcp\Tools\FinancialSummaryTool;
use App\Mcp\Tools\Write\ProposeInvoiceTool;
use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\CompanyBackup;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\McpWriteProposal;
use App\Models\PaymentTerm;
use App\Models\RecurringDocument;
use App\Models\ReminderTier;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Posting\JournalPoster;
use App\Services\Recurring\RecurringDocumentGenerator;
use App\Services\Recurring\RecurringJournalEntryGenerator;
use App\Services\Reminders\DueReminderResolver;
use App\Support\Access\DefaultCompanyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Laravel\Mcp\Request;
use Livewire\Livewire;

/*
 * A read-only organization (App\Contracts\CompanyAccess, bound by a hosting
 * layer): every member, the Owner included, can only look; posting is refused
 * as a backstop; scheduled work skips it. What must still work: reports, the
 * Owner's backup and delete, support tickets, and each member's own account.
 */

beforeEach(function () {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');

    $this->company = Company::factory()->create(['timezone' => 'UTC']);
    $this->owner = User::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();
    $this->customer = Contact::create(['display_name' => 'Acme', 'email' => 'buyer@acme.test', 'is_customer' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
    CarbonImmutable::setTestNow();
});

function readOnlyTestInvoice(Contact $customer, Account $income, bool $post = false): Invoice
{
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.str()->random(6),
        'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-30',
    ]);
    $invoice->lines()->create([
        'account_id' => $income->id,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price_cents' => 10000,
        'line_subtotal_cents' => 10000,
        'line_tax_cents' => 0,
        'line_total_cents' => 10000,
        'line_order' => 0,
    ]);

    if ($post) {
        app(InvoicePoster::class)->post($invoice);
    }

    return $invoice->refresh();
}

it('binds a default under which no organization is read-only', function () {
    $access = app(CompanyAccess::class);

    expect($access)->toBeInstanceOf(DefaultCompanyAccess::class)
        ->and($access->isReadOnly($this->company))->toBeFalse()
        ->and($access->readOnlyNotice($this->company))->toBeNull();
});

it('leaves every member, the Owner included, only view permissions', function () {
    $sales = predefinedRoleMember($this->company, PredefinedRole::Sales);

    expect($this->owner->permissions($this->company)->allows(Activity::Invoices, PermissionAction::Create))->toBeTrue();

    makeCompanyReadOnly($this->company);

    $owner = $this->owner->permissions($this->company);
    $clerk = $sales->permissions($this->company);

    expect($owner->isOwner())->toBeFalse()
        ->and($owner->allows(Activity::Invoices, PermissionAction::View))->toBeTrue()
        ->and($owner->canViewBalances(Activity::Accounts))->toBeTrue()
        ->and($owner->allows(Activity::Invoices, PermissionAction::Create))->toBeFalse()
        ->and($owner->allows(Activity::Invoices, PermissionAction::Delete))->toBeFalse()
        ->and($owner->allows(Activity::CompanySettings, PermissionAction::Modify))->toBeFalse()
        ->and($clerk->allows(Activity::Invoices, PermissionAction::View))->toBeTrue()
        ->and($clerk->allows(Activity::Invoices, PermissionAction::Create))->toBeFalse()
        // Still only what the role could open before.
        ->and($clerk->allows(Activity::PayRuns, PermissionAction::View))->toBeFalse()
        // Who owns it doesn't change.
        ->and($this->owner->ownsCompany($this->company))->toBeTrue();
});

it('opens the pages that only look and refuses the ones that change things', function () {
    makeCompanyReadOnly($this->company);
    $this->actingAs($this->owner);

    $this->get(route('invoices.index', ['company' => $this->company->slug]))->assertOk();
    $this->get(route('reports.trial-balance', ['company' => $this->company->slug]))->assertOk();
    $this->get(route('invoices.create', ['company' => $this->company->slug]))->assertForbidden();
});

it('refuses Livewire saves, voids and deletes', function () {
    $posted = readOnlyTestInvoice($this->customer, $this->income, post: true);
    makeCompanyReadOnly($this->company);

    Livewire::actingAs($this->owner)
        ->test('pages::settings.lists.payment-terms', ['company' => $this->company])
        ->set('f_name', 'Net 45')
        ->set('f_days', 45)
        ->call('save')
        ->assertForbidden();

    Livewire::actingAs($this->owner)
        ->test('pages::invoices.show', ['company' => $this->company, 'invoice' => $posted])
        ->call('void')
        ->assertForbidden();

    expect(PaymentTerm::query()->where('name', 'Net 45')->exists())->toBeFalse()
        ->and($posted->fresh()->journalEntry->isVoided())->toBeFalse();
});

it('refuses to post, void or repost as a backstop', function () {
    $draft = readOnlyTestInvoice($this->customer, $this->income);
    $posted = readOnlyTestInvoice($this->customer, $this->income, post: true);
    makeCompanyReadOnly($this->company);

    expect(fn () => app(InvoicePoster::class)->post($draft))->toThrow(CompanyReadOnlyException::class)
        ->and(fn () => app(InvoicePoster::class)->void($posted))->toThrow(CompanyReadOnlyException::class)
        ->and(fn () => app(InvoicePoster::class)->repost($posted->fresh()))->toThrow(CompanyReadOnlyException::class)
        ->and($draft->fresh()->journal_entry_id)->toBeNull()
        ->and($posted->fresh()->journalEntry->isVoided())->toBeFalse();

    // The one way through, for money that has already moved (the Stripe webhook).
    JournalPoster::allowingReadOnly(fn () => app(InvoicePoster::class)->post($draft));

    expect($draft->fresh()->journal_entry_id)->not->toBeNull();
});

it('lets API keys read but not write, whoever made them', function () {
    $draft = readOnlyTestInvoice($this->customer, $this->income);
    $ownerKey = CompanyApiKey::mint($this->company, 'Owner key', $this->owner->id)['plaintext'];
    $consoleKey = CompanyApiKey::mint($this->company, 'Console key')['plaintext'];
    makeCompanyReadOnly($this->company);

    foreach ([$ownerKey, $consoleKey] as $token) {
        $this->withToken($token)->getJson('/api/v1/customers')->assertOk();
        $this->withToken($token)->postJson('/api/v1/customers', ['display_name' => 'New customer'])
            ->assertForbidden()
            ->assertJson(['message' => 'This organization is read-only: its API keys can only read.']);
        $this->withToken($token)->deleteJson("/api/v1/invoices/{$draft->id}")->assertForbidden();
    }

    expect(Contact::query()->where('display_name', 'New customer')->exists())->toBeFalse()
        ->and(Invoice::query()->whereKey($draft->id)->exists())->toBeTrue();
});

it('lets an MCP connection read but not propose', function () {
    config(['mcp.write_enabled' => true]);
    $this->company->forceFill(['settings' => ['mcp' => ['agentic_writes' => true]]])->save();
    bindMcpTenant($this->company);
    makeCompanyReadOnly($this->company);

    $proposal = (new ProposeInvoiceTool)->handle(new Request([
        'contact_id' => $this->customer->id,
        'invoice_date' => '2026-06-01',
        'lines' => [['account' => $this->income->id, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100]],
    ]));

    expect((new FinancialSummaryTool)->handle(new Request(['period' => 'ytd']))->isError())->toBeFalse()
        ->and($proposal->isError())->toBeTrue()
        ->and(McpWriteProposal::query()->count())->toBe(0);
});

it('is skipped, not failed, by each per-organization scheduled command', function (string $command, array $options) {
    makeCompanyReadOnly($this->company);

    $this->artisan($command, ['company' => $this->company->id, ...$options])
        ->expectsOutputToContain($this->company->slug.' — skipped: read-only.')
        ->assertSuccessful();
})->with([
    'recurring documents' => ['recurring:generate', ['--sync' => true]],
    'depreciation' => ['depreciation:generate', ['--sync' => true]],
    'payment reminders' => ['reminders:send', ['--sync' => true]],
    'scheduled reports' => ['reports:send-scheduled', ['--sync' => true]],
    'deadline emails' => ['deadlines:notify', ['--sync' => true]],
    'time-off accrual' => ['payroll:accrue-time-off', []],
    'daily insights' => ['insights:generate', ['--sync' => true]],
]);

it('generates recurring documents for everyone else on the same run', function () {
    $other = Company::factory()->create(['timezone' => 'UTC']);

    $schedules = [];

    foreach ([$this->company, $other] as $company) {
        app()->instance('current_company', $company);
        $customer = Contact::create(['display_name' => 'Customer of '.$company->name, 'is_customer' => true]);
        $income = Account::query()->where('subtype', AccountSubtype::Income->value)->orderBy('code')->firstOrFail();

        $schedules[$company->id] = app(SaveRecurringDocument::class)->handle([
            'document_type' => 'invoice',
            'contact_id' => $customer->id,
            'terms_id' => null,
            'memo' => 'Monthly retainer',
            'name' => 'Retainer',
            'frequency' => 'monthly',
            'start_date' => '2026-06-15',
            'day_of_month' => 15,
            'end_type' => 'never',
            'lines' => [[
                'item_id' => null,
                'account_id' => $income->id,
                'description' => 'Service',
                'quantity' => '1',
                'unit_price_cents' => 10000,
                'tax_code_id' => null,
            ]],
        ]);
    }

    app()->forgetInstance('current_company');
    makeCompanyReadOnly($this->company);

    $this->artisan('recurring:generate', ['--sync' => true])->assertSuccessful();

    expect(Invoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0)
        ->and(Invoice::withoutGlobalScopes()->where('company_id', $other->id)->count())->toBe(1)
        ->and(RecurringDocument::withoutGlobalScopes()->find($schedules[$this->company->id]->id)->occurrences_generated)->toBe(0);
});

it('has its queued jobs skip it when it became read-only after they were queued', function () {
    makeCompanyReadOnly($this->company);

    app(GenerateRecurringDocumentsForCompany::class, ['companyId' => $this->company->id])->handle(
        app(RecurringDocumentGenerator::class),
        app(RecurringJournalEntryGenerator::class),
    );
    (new SendPaymentRemindersForCompany($this->company->id))->handle(
        app(DueReminderResolver::class),
        app(SendInvoiceReminder::class),
    );

    // The reminder run would seed the default tiers first; it never started.
    expect(ReminderTier::withoutGlobalScopes()->where('company_id', $this->company->id)->count())->toBe(0);
});

it('still runs reports', function () {
    makeCompanyReadOnly($this->company);

    Livewire::actingAs($this->owner)
        ->test('pages::reports.trial-balance', ['company' => $this->company])
        ->assertOk()
        ->set('accountType', '')
        ->assertOk();
});

it('still lets the Owner export a backup', function () {
    Bus::fake([ExportCompanyDataJob::class]);
    makeCompanyReadOnly($this->company);
    $this->actingAs($this->owner);

    $this->get(route('settings.backup-and-export', ['company' => $this->company->slug]))->assertOk();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.backup-and-export', ['company' => $this->company])
        ->call('createBackup')
        ->assertHasNoErrors();

    expect(CompanyBackup::query()->count())->toBe(1);
    Bus::assertDispatched(ExportCompanyDataJob::class);
});

it('still lets the Owner delete the organization', function () {
    makeCompanyReadOnly($this->company);

    expect($this->owner->toCompanyPermissions($this->company)->canDeleteCompany)->toBeTrue();

    Livewire::actingAs($this->owner)
        ->test('pages::companies.delete-company-modal', ['company' => $this->company])
        ->set('deleteName', $this->company->name)
        ->call('deleteCompany')
        ->assertHasNoErrors();

    expect($this->company->fresh()->trashed())->toBeTrue();
});

it('still lets members raise a support ticket and edit their own profile', function () {
    Notification::fake();
    $this->owner->switchCompany($this->company);
    makeCompanyReadOnly($this->company);

    Livewire::actingAs($this->owner)
        ->test('pages::support.index')
        ->set('subject', 'Read-only question')
        ->set('type', SupportTicketType::General->value)
        ->set('body', 'Why is my organization read-only?')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::actingAs($this->owner)
        ->test('pages::settings.profile')
        ->set('name', 'Renamed Owner')
        ->set('email', $this->owner->email)
        ->call('updateProfileInformation')
        ->assertHasNoErrors();

    expect(SupportTicket::query()->count())->toBe(1)
        ->and($this->owner->fresh()->name)->toBe('Renamed Owner');
});

it('shows the notice across the organization’s pages', function () {
    makeCompanyReadOnly($this->company, 'Billing lapsed: this organization is read-only until it is renewed.');
    $this->actingAs($this->owner);

    $this->get(route('dashboard', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('data-test="read-only-banner"', escape: false)
        ->assertSee('Billing lapsed: this organization is read-only until it is renewed.');
});

it('shows no notice where there is none', function () {
    $this->actingAs($this->owner);

    $this->get(route('dashboard', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertDontSee('data-test="read-only-banner"', escape: false);
});
