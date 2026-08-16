<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\MemorizedReport;
use App\Models\User;
use App\Services\Posting\JournalPoster;
use App\Support\Reporting\ReportCatalog;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->company = Company::factory()->create(['fiscal_year_start_month' => 1]);
    $this->user = User::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);

    app()->instance('current_company', $this->company);
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('renders all three list reports with seeded data', function () {
    $account = Account::query()->orderBy('code')->first();

    Contact::create(['display_name' => 'List Customer Co', 'is_customer' => true, 'email' => 'cust@example.com']);
    Contact::create(['display_name' => 'List Vendor Co', 'is_vendor' => true, 'email' => 'vend@example.com']);

    $this->actingAs($this->user);

    $this->get(route('reports.account-list', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee($account->name);

    $this->get(route('reports.customer-contact-list', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('List Customer Co')
        ->assertDontSee('List Vendor Co');

    $this->get(route('reports.vendor-contact-list', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('List Vendor Co')
        ->assertDontSee('List Customer Co');
});

it('honors the as-of date on account balances', function () {
    $bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->first();
    $income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();

    $post = function (string $date, int $cents) use ($bank, $income): void {
        $entry = JournalEntry::create([
            'company_id' => $this->company->id,
            'entry_no' => 'JE-LIST-'.$date,
            'entry_date' => CarbonImmutable::parse($date),
            'memo' => 'List report test',
        ]);
        $entry->lines()->create(['account_id' => $bank->id, 'debit_cents' => $cents, 'credit_cents' => 0, 'line_order' => 0]);
        $entry->lines()->create(['account_id' => $income->id, 'debit_cents' => 0, 'credit_cents' => $cents, 'line_order' => 1]);
        app(JournalPoster::class)->post($entry);
    };

    $post('2026-05-10', 5000);
    $post('2026-06-05', 7000); // after the as-of date — must be excluded

    $rows = Livewire::actingAs($this->user)
        ->test('pages::reports.account-list', ['company' => $this->company])
        ->set('asOf', '2026-05-31')
        ->instance()
        ->rows();

    expect(collect($rows)->firstWhere('id', $bank->id)['balance'])->toBe(5000);

    $later = Livewire::actingAs($this->user)
        ->test('pages::reports.account-list', ['company' => $this->company])
        ->set('asOf', '2026-06-30')
        ->instance()
        ->rows();

    expect(collect($later)->firstWhere('id', $bank->id)['balance'])->toBe(12000);
});

it('hides inactive accounts by default and shows them with the toggle', function () {
    $inactive = Account::query()->where('is_system', false)->orderBy('code')->first()
        ?? Account::query()->orderBy('code')->first();
    $inactive->update(['is_active' => false]);

    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.account-list', ['company' => $this->company]);

    expect(collect($component->instance()->rows())->pluck('id'))->not->toContain($inactive->id);

    $component->set('includeInactive', true);

    $row = collect($component->instance()->rows())->firstWhere('id', $inactive->id);

    expect($row)->not->toBeNull()
        ->and($row['active'])->toBeFalse();
});

it('sorts the account list by code in both directions', function () {
    $component = Livewire::actingAs($this->user)
        ->test('pages::reports.account-list', ['company' => $this->company]);

    $codes = collect($component->instance()->rows())->pluck('code');
    expect($codes->all())->toBe($codes->sortBy(fn ($c) => mb_strtolower($c))->values()->all());

    $component->call('sortBy', 'code'); // same field → flips to desc

    $descCodes = collect($component->instance()->rows())->pluck('code');
    expect($component->get('sortDir'))->toBe('desc')
        ->and($descCodes->all())->toBe($codes->reverse()->values()->all());
});

it('lists only customers with their contact details and open balance', function () {
    $customer = Contact::create([
        'display_name' => 'Acme Buyer',
        'company_name' => 'Acme Inc',
        'is_customer' => true,
        'email' => 'buyer@acme.test',
        'phone' => '555-0001',
        'billing_line1' => '1 Main St',
        'billing_city' => 'Calgary',
        'billing_region' => 'AB',
        'billing_postal_code' => 'T2P 1A1',
    ]);
    $customer->forceFill(['ar_balance_cents' => 12345])->saveQuietly();

    Contact::create(['display_name' => 'Supplies Only Ltd', 'is_vendor' => true]);

    $this->actingAs($this->user);

    $this->get(route('reports.customer-contact-list', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Acme Buyer')
        ->assertSee('buyer@acme.test')
        ->assertSee('555-0001')
        ->assertSee('1 Main St, Calgary, AB, T2P 1A1')
        ->assertSee('123.45')
        ->assertDontSee('Supplies Only Ltd');
});

it('lists only vendors and falls back to the mobile number', function () {
    $vendor = Contact::create([
        'display_name' => 'Bolt Supply',
        'is_vendor' => true,
        'email' => 'ap@bolt.test',
        'mobile' => '555-0099', // no work phone — mobile should show
    ]);
    $vendor->forceFill(['ap_balance_cents' => 6700])->saveQuietly();

    Contact::create(['display_name' => 'Buyers Only Co', 'is_customer' => true]);

    $this->actingAs($this->user);

    $this->get(route('reports.vendor-contact-list', ['company' => $this->company->slug]))
        ->assertOk()
        ->assertSee('Bolt Supply')
        ->assertSee('555-0099')
        ->assertSee('67.00')
        ->assertDontSee('Buyers Only Co');
});

it('hides inactive contacts by default and shows them with the toggle', function () {
    Contact::create(['display_name' => 'Active Customer', 'is_customer' => true]);
    Contact::create(['display_name' => 'Dormant Customer', 'is_customer' => true, 'is_active' => false]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.customer-contact-list', ['company' => $this->company])
        ->assertSee('Active Customer')
        ->assertDontSee('Dormant Customer')
        ->set('includeInactive', true)
        ->assertSee('Dormant Customer');

    Contact::create(['display_name' => 'Dormant Vendor', 'is_vendor' => true, 'is_active' => false]);

    Livewire::actingAs($this->user)
        ->test('pages::reports.vendor-contact-list', ['company' => $this->company])
        ->assertDontSee('Dormant Vendor')
        ->set('includeInactive', true)
        ->assertSee('Dormant Vendor');
});

it('lists the three reports in the catalog under Lists', function () {
    expect(array_keys(ReportCatalog::flatten($this->company, $this->user)))
        ->toContain('reports.account-list', 'reports.customer-contact-list', 'reports.vendor-contact-list');

    $lists = collect(ReportCatalog::for($this->company, $this->user))
        ->firstWhere('label', 'Lists');

    expect($lists)->not->toBeNull()
        ->and(collect($lists['reports'])->pluck('key')->all())
        ->toBe(['reports.account-list', 'reports.customer-contact-list', 'reports.vendor-contact-list']);
});

it('surfaces both contact lists when searching the hub for contact list', function () {
    $categories = Livewire::actingAs($this->user)
        ->test('pages::reports.index', ['company' => $this->company])
        ->set('search', 'contact list')
        ->instance()
        ->categories();

    $labels = collect($categories)
        ->flatMap(fn (array $category) => collect($category['reports'])->pluck('label'))
        ->all();

    expect($labels)->toBe(['Customer Contact List', 'Vendor Contact List']);
});

it('exports each list report as csv, xlsx, and pdf', function () {
    Contact::create(['display_name' => 'Export Customer', 'is_customer' => true]);
    Contact::create(['display_name' => 'Export Vendor', 'is_vendor' => true]);

    foreach ([
        'pages::reports.account-list',
        'pages::reports.customer-contact-list',
        'pages::reports.vendor-contact-list',
    ] as $page) {
        foreach (['exportXlsx' => '.xlsx', 'exportPdf' => '.pdf'] as $method => $ext) {
            $component = Livewire::actingAs($this->user)
                ->test($page, ['company' => $this->company])
                ->call($method);

            expect(data_get($component->effects, 'download.name'))->toEndWith($ext, "{$page} {$method}");
        }

        Livewire::actingAs($this->user)
            ->test($page, ['company' => $this->company])
            ->call('exportCsv')
            ->assertOk();
    }
});

it('round-trips includeInactive through memorize and apply', function () {
    Livewire::actingAs($this->user)
        ->test('pages::reports.customer-contact-list', ['company' => $this->company])
        ->set('includeInactive', true)
        ->set('memorizeName', 'All customers incl. inactive')
        ->call('memorizeReport')
        ->assertHasNoErrors();

    $memorized = MemorizedReport::query()->where('user_id', $this->user->id)->first();

    expect($memorized)->not->toBeNull()
        ->and($memorized->report_key)->toBe('reports.customer-contact-list')
        ->and($memorized->settings['includeInactive'])->toBeTrue();

    Livewire::actingAs($this->user)
        ->test('pages::reports.customer-contact-list', ['company' => $this->company])
        ->assertSet('includeInactive', false)
        ->call('applyMemorized', $memorized->id)
        ->assertSet('includeInactive', true);
});
