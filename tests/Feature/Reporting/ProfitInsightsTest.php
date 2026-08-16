<?php

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->first();
    $this->ar = Account::query()->where('subtype', AccountSubtype::AccountsReceivable->value)->first();
    $this->ap = Account::query()->where('subtype', AccountSubtype::AccountsPayable->value)->first();

    $this->start = CarbonImmutable::now()->subMonth();
    $this->end = CarbonImmutable::now()->addDay();
});

afterEach(fn () => app()->forgetInstance('current_company'));

function postInsightEntry(Company $company, string $no, CarbonImmutable $date, array $lines): void
{
    $entry = JournalEntry::create([
        'company_id' => $company->id,
        'entry_no' => $no,
        'entry_date' => $date,
        'memo' => $no,
    ]);

    foreach ($lines as $i => $line) {
        $entry->lines()->create($line + ['line_order' => $i]);
    }

    app(JournalPoster::class)->post($entry);
}

it('headlines profit and ranks the top movers versus the prior year', function () {
    $now = CarbonImmutable::now();
    $priorYear = $now->subYear();

    // KPI source — posted GL: revenue credits income, expense debits the expense account.
    postInsightEntry($this->company, 'JE-REV-CUR', $now, [
        ['account_id' => $this->ar->id, 'debit_cents' => 10000, 'credit_cents' => 0],
        ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 10000],
    ]);
    postInsightEntry($this->company, 'JE-EXP-CUR', $now, [
        ['account_id' => $this->expense->id, 'debit_cents' => 4000, 'credit_cents' => 0],
        ['account_id' => $this->ap->id, 'debit_cents' => 0, 'credit_cents' => 4000],
    ]);
    postInsightEntry($this->company, 'JE-REV-PRI', $priorYear, [
        ['account_id' => $this->ar->id, 'debit_cents' => 6000, 'credit_cents' => 0],
        ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 6000],
    ]);
    postInsightEntry($this->company, 'JE-EXP-PRI', $priorYear, [
        ['account_id' => $this->expense->id, 'debit_cents' => 3000, 'credit_cents' => 0],
        ['account_id' => $this->ap->id, 'debit_cents' => 0, 'credit_cents' => 3000],
    ]);

    // Mover source — documents drive the customer/vendor lists.
    $customer = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Acme', 'is_customer' => true]);
    $vendor = Contact::create(['company_id' => $this->company->id, 'display_name' => 'Supplier', 'is_vendor' => true]);

    $invCur = Invoice::create(['company_id' => $this->company->id, 'contact_id' => $customer->id, 'invoice_no' => 'INV-C', 'invoice_date' => $now, 'due_date' => $now, 'status' => InvoiceStatus::Posted->value]);
    $invCur->lines()->create(['account_id' => $this->income->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 10000, 'line_subtotal_cents' => 10000, 'line_tax_cents' => 0, 'line_total_cents' => 10000, 'line_order' => 0]);

    $billCur = Bill::create(['company_id' => $this->company->id, 'contact_id' => $vendor->id, 'bill_no' => 'B-C', 'bill_date' => $now, 'due_date' => $now, 'status' => BillStatus::Posted->value]);
    $billCur->lines()->create(['account_id' => $this->expense->id, 'description' => 'x', 'quantity' => '1', 'unit_price_cents' => 4000, 'line_subtotal_cents' => 4000, 'line_tax_cents' => 0, 'line_total_cents' => 4000, 'line_order' => 0]);

    $page = Livewire::test('pages::reports.profit-insights', ['company' => $this->company])
        ->assertOk()
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString())
        ->set('comparisonBasis', 'prior_year');

    $insights = $page->instance()->insights;

    expect($insights['income']['current'])->toBe(10000)
        ->and($insights['expense']['current'])->toBe(4000)
        ->and($insights['profit']['current'])->toBe(6000)
        ->and($insights['profit']['prior'])->toBe(3000);

    // Headline + movers render.
    $page->assertSee('100.00')   // revenue
        ->assertSee('60.00')     // profit
        ->assertSee('Acme')      // top customer mover
        ->assertSee('Supplier')  // top vendor mover
        ->assertSee($this->expense->name); // top expense category mover
});

it('still headlines profit when there is no prior activity to compare', function () {
    postInsightEntry($this->company, 'JE-REV', CarbonImmutable::now(), [
        ['account_id' => $this->ar->id, 'debit_cents' => 5000, 'credit_cents' => 0],
        ['account_id' => $this->income->id, 'debit_cents' => 0, 'credit_cents' => 5000],
    ]);

    $page = Livewire::test('pages::reports.profit-insights', ['company' => $this->company])
        ->assertOk()
        ->set('startDate', $this->start->toDateString())
        ->set('endDate', $this->end->toDateString());

    expect($page->instance()->insights['profit']['current'])->toBe(5000)
        ->and($page->instance()->insights['profit']['prior'])->toBe(0);

    $page->assertSee('50.00');
});
