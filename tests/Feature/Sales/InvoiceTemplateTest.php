<?php

use App\Actions\Sales\SaveInvoiceTemplate;
use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Company;
use App\Models\InvoiceTemplate;
use App\Models\TaxCode;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->income = Account::query()->where('subtype', AccountSubtype::Income->value)->first();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function invoiceTemplateData(array $overrides = []): array
{
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    return array_merge([
        'name' => 'Standard service package',
        'is_active' => true,
        'lines' => [
            [
                'item_id' => null,
                'account_id' => test()->income->id,
                'description' => 'Consulting',
                'quantity' => '10',
                'unit_price_cents' => 10000,
                'tax_code_id' => $gst->id,
            ],
            [
                'item_id' => null,
                'account_id' => test()->income->id,
                'description' => 'Setup fee',
                'quantity' => '1',
                'unit_price_cents' => 2500,
                'tax_code_id' => null,
            ],
        ],
    ], $overrides);
}

it('creates a template with ordered lines via the action', function () {
    $template = app(SaveInvoiceTemplate::class)->handle(invoiceTemplateData());

    expect($template->company_id)->toBe($this->company->id)
        ->and($template->name)->toBe('Standard service package')
        ->and($template->is_active)->toBeTrue()
        ->and($template->lines)->toHaveCount(2);

    $first = $template->lines->firstWhere('line_order', 0);
    expect($first->description)->toBe('Consulting')
        ->and($first->unit_price_cents)->toBe(10000)
        ->and($first->unit_price_cents)->toBeInt()
        ->and($first->company_id)->toBe($this->company->id);

    expect($template->lines->pluck('line_order')->all())->toBe([0, 1]);
});

it('replaces the line set when a template is updated', function () {
    $template = app(SaveInvoiceTemplate::class)->handle(invoiceTemplateData());

    $updated = app(SaveInvoiceTemplate::class)->handle(invoiceTemplateData([
        'name' => 'Renamed',
        'lines' => [[
            'item_id' => null,
            'account_id' => $this->income->id,
            'description' => 'Only line',
            'quantity' => '3',
            'unit_price_cents' => 5000,
            'tax_code_id' => null,
        ]],
    ]), $template);

    expect($updated->id)->toBe($template->id)
        ->and($updated->name)->toBe('Renamed')
        ->and($updated->lines)->toHaveCount(1)
        ->and($updated->lines->first()->description)->toBe('Only line');

    expect(InvoiceTemplate::query()->find($template->id)->lines)->toHaveCount(1);
});

it('soft-deletes a template from the index component', function () {
    $template = app(SaveInvoiceTemplate::class)->handle(invoiceTemplateData());

    Livewire::test('pages::invoice-templates.index', ['company' => $this->company])
        ->call('delete', $template->id);

    $this->assertSoftDeleted('invoice_templates', ['id' => $template->id]);
});

it('only lists templates from the current company', function () {
    $mine = app(SaveInvoiceTemplate::class)->handle(invoiceTemplateData(['name' => 'Mine']));

    // Build a template owned by another company.
    $other = Company::factory()->create();
    app()->instance('current_company', $other);
    $theirs = app(SaveInvoiceTemplate::class)->handle([
        'name' => 'Theirs',
        'lines' => [[
            'item_id' => null,
            'account_id' => null,
            'description' => 'x',
            'quantity' => '1',
            'unit_price_cents' => 100,
            'tax_code_id' => null,
        ]],
    ]);
    app()->instance('current_company', $this->company);

    $ids = InvoiceTemplate::query()->pluck('id');

    expect($ids)->toContain($mine->id)
        ->and($ids)->not->toContain($theirs->id);
});

it('saves a template through the management form', function () {
    $gst = TaxCode::where('code', 'GST')->firstOrFail();

    Livewire::test('pages::invoice-templates.form', ['company' => $this->company])
        ->set('name', 'From form')
        ->set('lines.0.description', 'Widget')
        ->set('lines.0.account_id', $this->income->id)
        ->set('lines.0.quantity', '4')
        ->set('lines.0.unit_price', '25.00')
        ->set('lines.0.tax_code_id', $gst->id)
        ->call('save')
        ->assertRedirect(route('invoice-templates.index', ['company' => $this->company->slug]));

    $template = InvoiceTemplate::query()->where('name', 'From form')->firstOrFail();
    expect($template->lines)->toHaveCount(1)
        ->and($template->lines->first()->unit_price_cents)->toBe(2500)
        ->and($template->lines->first()->tax_code_id)->toBe($gst->id);
});
