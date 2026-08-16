<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\TaxCode;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);

    $this->vendor = Contact::factory()->vendor()->create();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

/**
 * @return array<string, mixed>
 */
function billLine(int $accountId, int $taxCodeId, string $override): array
{
    return [
        'item_id' => null,
        'account_id' => $accountId,
        'description' => 'Service',
        'quantity' => '1',
        'unit_price' => '100.00',
        'discount_pct' => '',
        'tax_code_id' => $taxCodeId,
        'tax_override' => $override,
        'class_id' => null,
        'location_id' => null,
        'subtotal' => 0,
        'auto_tax' => 0,
        'tax' => 0,
        'total' => 0,
    ];
}

it('overrides the auto-computed tax on a bill line', function () {
    // GST is 5%, so $100.00 would auto-compute to $5.00 of tax. Override to $7.00.
    expect($this->gst->taxFor(10000))->toBe(500);

    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('lines', [billLine($this->expense->id, $this->gst->id, '7.00')])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Bill::firstOrFail()->lines()->firstOrFail();

    expect($line->line_subtotal_cents)->toBe(10000)
        ->and($line->line_tax_cents)->toBe(700)
        ->and($line->tax_override_cents)->toBe(700)
        ->and($line->line_total_cents)->toBe(10700);
});

it('falls back to the tax code amount when no override is given', function () {
    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('lines', [billLine($this->expense->id, $this->gst->id, '')])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Bill::firstOrFail()->lines()->firstOrFail();

    expect($line->line_tax_cents)->toBe(500)
        ->and($line->tax_override_cents)->toBeNull()
        ->and($line->line_total_cents)->toBe(10500);
});

it('attaches staged files to a new bill when the draft is saved', function () {
    $file = UploadedFile::fake()->create('vendor-invoice.pdf', 100, 'application/pdf');

    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('lines', [billLine($this->expense->id, $this->gst->id, '')])
        ->set('newAttachments', [$file])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $bill = Bill::firstOrFail();
    $attachment = Attachment::firstOrFail();

    expect($bill->attachments()->count())->toBe(1)
        ->and($attachment->attachable_id)->toBe($bill->id)
        ->and($attachment->attachable_type)->toBe($bill->getMorphClass())
        ->and($attachment->original_filename)->toBe('vendor-invoice.pdf');

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects an oversized bill attachment', function () {
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::bills.form', ['company' => $this->company])
        ->set('contact_id', $this->vendor->id)
        ->set('lines', [billLine($this->expense->id, $this->gst->id, '')])
        ->set('newAttachments', [$file])
        ->call('saveDraft')
        ->assertHasErrors('newAttachments.0');

    expect(Attachment::count())->toBe(0);
});
