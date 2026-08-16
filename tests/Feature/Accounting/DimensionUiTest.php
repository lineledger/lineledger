<?php

use App\Actions\Accounting\SaveJournalEntry;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Classification;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Location;
use App\Models\User;
use App\Services\Posting\InvoicePoster;
use App\Services\Reporting\ReportCalculator;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    // Dimensions are opt-in modules (default off); enable both so the UI/report gating
    // tests exercise the on-state. The "feature off" behaviour is covered separately.
    $this->company->update(['features_classes' => true, 'features_locations' => true]);
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->customer = Contact::create(['display_name' => 'UI Dim Customer', 'is_customer' => true]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('creates a class and a location via the settings pages', function () {
    Livewire::test('pages::settings.lists.classifications', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'East Region')
        ->call('save')
        ->assertHasNoErrors();

    Livewire::test('pages::settings.lists.locations', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_name', 'Chapel A')
        ->call('save')
        ->assertHasNoErrors();

    $class = Classification::query()->where('name', 'East Region')->first();
    $location = Location::query()->where('name', 'Chapel A')->first();

    expect($class)->not->toBeNull()
        ->and($class->company_id)->toBe($this->company->id)
        ->and($class->is_active)->toBeTrue();
    expect($location)->not->toBeNull()
        ->and($location->company_id)->toBe($this->company->id);
});

it('persists a line class and location through the invoice form', function () {
    $class = Classification::create(['name' => 'East Region']);
    $location = Location::create(['name' => 'Chapel A']);

    // Mount seeds one empty line; set its fields individually, the way wire:model
    // binds them in the real form (setting the whole array bypasses updatedLines).
    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->customer->id)
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.description', 'Service')
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '100.00')
        ->set('lines.0.class_id', $class->id)
        ->set('lines.0.location_id', $location->id)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Invoice::query()->latest('id')->firstOrFail()->lines()->firstOrFail();

    expect($line->class_id)->toBe($class->id)
        ->and($line->location_id)->toBe($location->id);
});

it('filters the income statement by class', function () {
    $east = Classification::create(['name' => 'East']);
    $west = Classification::create(['name' => 'West']);

    $post = function (int $classId, int $cents): void {
        $invoice = Invoice::create([
            'contact_id' => $this->customer->id,
            'invoice_no' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-06-20',
        ]);
        $invoice->lines()->create([
            'account_id' => $this->income->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => $cents,
            'line_subtotal_cents' => $cents,
            'line_tax_cents' => 0,
            'line_total_cents' => $cents,
            'line_order' => 0,
            'class_id' => $classId,
        ]);
        app(InvoicePoster::class)->post($invoice);
    };

    $post($east->id, 10000); // East: $100
    $post($west->id, 6000);  // West: $60

    $component = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-05-01')
        ->set('endDate', '2026-05-31');

    // Unfiltered: both classes count.
    expect($component->instance()->report()['total_income'])->toBe(16000);

    // Filtered to East only.
    $component->set('classId', $east->id);
    expect($component->instance()->report()['total_income'])->toBe(10000);
});

it('persists a line class and location through the bill form', function () {
    $class = Classification::create(['name' => 'East Region']);
    $location = Location::create(['name' => 'Chapel A']);
    $vendor = Contact::create(['display_name' => 'UI Dim Vendor', 'is_vendor' => true]);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();

    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $vendor->id)
        ->set('lines.0.account_id', $expense->id)
        ->set('lines.0.description', 'Supplies')
        ->set('lines.0.quantity', '1')
        ->set('lines.0.unit_price', '50.00')
        ->set('lines.0.class_id', $class->id)
        ->set('lines.0.location_id', $location->id)
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Bill::query()->latest('id')->firstOrFail()->lines()->firstOrFail();

    expect($line->class_id)->toBe($class->id)
        ->and($line->location_id)->toBe($location->id);
});

it('persists class and location through SaveJournalEntry', function () {
    $class = Classification::create(['name' => 'East Region']);
    $location = Location::create(['name' => 'Chapel A']);
    $cash = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();

    $entry = app(SaveJournalEntry::class)->handle([
        'entry_date' => '2026-05-20',
        'memo' => 'Dimension JE',
        'lines' => [
            ['account_id' => $expense->id, 'debit_cents' => 5000, 'credit_cents' => 0, 'class_id' => $class->id, 'location_id' => $location->id],
            ['account_id' => $cash->id, 'debit_cents' => 0, 'credit_cents' => 5000],
        ],
    ]);

    $debitLine = $entry->lines()->where('account_id', $expense->id)->firstOrFail();
    $creditLine = $entry->lines()->where('account_id', $cash->id)->firstOrFail();

    expect($debitLine->class_id)->toBe($class->id)
        ->and($debitLine->location_id)->toBe($location->id)
        ->and($creditLine->class_id)->toBeNull();
});

it('renders labelled class and location fields on the journal form when tracked', function () {
    // Both modules are enabled in beforeEach; the two-tier line layout puts Class
    // and Location on a labelled second row beneath each line.
    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->assertSeeHtml('data-test="line-class"')
        ->assertSeeHtml('data-test="line-location"')
        ->assertSee('Class')
        ->assertSee('Location');
});

it('filters the general ledger by class', function () {
    $east = Classification::create(['name' => 'East']);
    $west = Classification::create(['name' => 'West']);

    $post = function (int $classId, int $cents): void {
        $invoice = Invoice::create([
            'contact_id' => $this->customer->id,
            'invoice_no' => 'INV-'.fake()->unique()->numerify('######'),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-06-20',
        ]);
        $invoice->lines()->create([
            'account_id' => $this->income->id,
            'description' => 'Service',
            'quantity' => '1',
            'unit_price_cents' => $cents,
            'line_subtotal_cents' => $cents,
            'line_tax_cents' => 0,
            'line_total_cents' => $cents,
            'line_order' => 0,
            'class_id' => $classId,
        ]);
        app(InvoicePoster::class)->post($invoice);
    };

    $post($east->id, 10000);
    $post($west->id, 6000);

    $calc = app(ReportCalculator::class);
    $start = CarbonImmutable::parse('2026-05-01');
    $end = CarbonImmutable::parse('2026-05-31');

    // Unfiltered: both revenue lines show on the income account's ledger.
    expect($calc->generalLedgerPaginated($this->income, $start, $end, 100)['lines'])->toHaveCount(2);

    // Filtered to East: only that class's line remains.
    $eastOnly = $calc->generalLedgerPaginated($this->income, $start, $end, 100, $east->id);
    expect($eastOnly['lines'])->toHaveCount(1)
        ->and($eastOnly['lines']->first()['credit'])->toBe(10000);
});

// Regression: deferred class/location selects make the browser send a whole-`lines`
// update (top-level, dot-less path), which Livewire dispatches to updatedLines() with
// a NULL key. The hook must tolerate that instead of fataling on `string $key`.
it('survives a whole-lines update on the journal form (null-key updatedLines)', function () {
    $class = Classification::create(['name' => 'East']);
    $expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->first();
    $cash = Account::query()->where('subtype', AccountSubtype::Bank->value)->first();

    $journalLine = fn (array $overrides): array => array_merge([
        'account_id' => null, 'contact_id' => null, 'contact_query' => '', 'contact_creating' => false,
        'new_contact_name' => '', 'debit' => '', 'credit' => '', 'memo' => null, 'class_id' => null, 'location_id' => null,
    ], $overrides);

    Livewire::test('pages::journal.form', ['company' => $this->company])
        ->set('lines', [
            $journalLine(['account_id' => $expense->id, 'debit' => '50.00', 'class_id' => $class->id]),
            $journalLine(['account_id' => $cash->id, 'credit' => '50.00']),
        ])
        ->assertHasNoErrors()
        ->assertStatus(200);
});

// Feature gating: with the modules OFF, the dimension UI is hidden and a stale class
// filter is ignored — even if classes exist and are tagged on transactions.
it('hides dimensions and ignores filters when the modules are off', function () {
    $this->company->update(['features_classes' => false, 'features_locations' => false]);
    $east = Classification::create(['name' => 'East']);

    $invoice = Invoice::create([
        'contact_id' => $this->customer->id, 'invoice_no' => 'INV-OFF1',
        'invoice_date' => '2026-05-20', 'due_date' => '2026-06-20',
    ]);
    $invoice->lines()->create([
        'account_id' => $this->income->id, 'description' => 'Service', 'quantity' => '1',
        'unit_price_cents' => 10000, 'line_subtotal_cents' => 10000, 'line_tax_cents' => 0,
        'line_total_cents' => 10000, 'line_order' => 0, 'class_id' => $east->id,
    ]);
    app(InvoicePoster::class)->post($invoice);

    // Invoice form: the class/location columns are not rendered.
    $form = Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->assertDontSeeHtml('data-test="line-class"')
        ->assertDontSeeHtml('data-test="line-location"');

    expect($form->instance()->tracksClasses())->toBeFalse()
        ->and($form->instance()->tracksLocations())->toBeFalse();

    // Income statement: a stale ?class= filter is ignored (full income still shown).
    $is = Livewire::test('pages::reports.income-statement', ['company' => $this->company])
        ->set('startDate', '2026-05-01')
        ->set('endDate', '2026-05-31')
        ->set('classId', $east->id);

    expect($is->instance()->report()['total_income'])->toBe(10000);
});

it('survives a whole-lines update on the invoice form (null-key updatedLines)', function () {
    $class = Classification::create(['name' => 'East']);

    Livewire::test('pages::invoices.form', ['company' => $this->company])
        ->set('contact_id', $this->customer->id)
        ->set('lines', [[
            'item_id' => null, 'account_id' => $this->income->id, 'description' => 'Service',
            'quantity' => '1', 'unit_price' => '100.00', 'tax_code_id' => null,
            'class_id' => $class->id, 'location_id' => null, 'subtotal' => 0, 'tax' => 0, 'total' => 0,
        ]])
        ->assertHasNoErrors()
        ->assertStatus(200);
});
