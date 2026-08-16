<?php

use App\Enums\AccountSubtype;
use App\Enums\CompanyRole;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Cheque;
use App\Models\Company;
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

    $this->bank = Account::query()->where('subtype', AccountSubtype::Bank->value)->orderBy('code')->firstOrFail();
    $this->expense = Account::query()->where('subtype', AccountSubtype::Expense->value)->orderBy('code')->firstOrFail();
    $this->gst = TaxCode::where('code', 'GST')->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('overrides the auto-computed tax on a cheque line', function () {
    // GST is 5%, so $100.00 would auto-compute to $5.00 of tax. Override to $7.00.
    expect($this->gst->taxFor(10000))->toBe(500);

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_name', 'Tax Vendor')
        ->set('lines', [[
            'account_id' => $this->expense->id,
            'description' => 'Service',
            'amount' => '100.00',
            'tax_code_id' => $this->gst->id,
            'tax_override' => '7.00',
            'class_id' => null,
            'location_id' => null,
            'auto_tax_cents' => 0,
            'tax_cents' => 0,
            'total' => 0,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Cheque::firstOrFail()->lines()->firstOrFail();

    expect($line->tax_cents)->toBe(700)
        ->and($line->tax_override_cents)->toBe(700);

    expect(Cheque::firstOrFail()->amount_cents)->toBe(10700);
});

it('falls back to the tax code amount when no override is given', function () {
    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_name', 'Tax Vendor')
        ->set('lines', [[
            'account_id' => $this->expense->id,
            'description' => 'Service',
            'amount' => '100.00',
            'tax_code_id' => $this->gst->id,
            'tax_override' => '',
            'class_id' => null,
            'location_id' => null,
            'auto_tax_cents' => 0,
            'tax_cents' => 0,
            'total' => 0,
        ]])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $line = Cheque::firstOrFail()->lines()->firstOrFail();

    expect($line->tax_cents)->toBe(500)
        ->and($line->tax_override_cents)->toBeNull();
});

it('attaches staged files to a new cheque when the draft is saved', function () {
    $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_name', 'Quick Mart')
        ->set('lines', [[
            'account_id' => $this->expense->id,
            'description' => 'Snacks',
            'amount' => '50.00',
            'tax_code_id' => null,
            'tax_override' => '',
            'class_id' => null,
            'location_id' => null,
            'auto_tax_cents' => 0,
            'tax_cents' => 0,
            'total' => 0,
        ]])
        ->set('newAttachments', [$file])
        ->call('saveDraft')
        ->assertHasNoErrors();

    $cheque = Cheque::firstOrFail();
    $attachment = Attachment::firstOrFail();

    expect($cheque->attachments()->count())->toBe(1)
        ->and($attachment->attachable_id)->toBe($cheque->id)
        ->and($attachment->attachable_type)->toBe($cheque->getMorphClass())
        ->and($attachment->original_filename)->toBe('receipt.pdf')
        ->and($attachment->uploaded_by_id)->toBe($this->user->id);

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects an oversized cheque attachment', function () {
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::cheques.form', ['company' => $this->company])
        ->set('payee_name', 'Quick Mart')
        ->set('newAttachments', [$file])
        ->call('saveDraft')
        ->assertHasErrors('newAttachments.0');

    expect(Attachment::count())->toBe(0);
});
