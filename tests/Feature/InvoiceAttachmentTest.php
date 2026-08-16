<?php

use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
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

    $customer = Contact::factory()->customer()->create();

    $this->invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-1',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => InvoiceStatus::Draft,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('uploads a PDF to an invoice', function () {
    $file = UploadedFile::fake()->create('signed-invoice.pdf', 100, 'application/pdf');

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_id)->toBe($this->invoice->id)
        ->and($attachment->attachable_type)->toBe($this->invoice->getMorphClass())
        ->and($attachment->company_id)->toBe($this->company->id)
        ->and($attachment->original_filename)->toBe('signed-invoice.pdf');

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects an oversized invoice attachment', function () {
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('removes an invoice attachment and deletes the file', function () {
    $file = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments');

    $attachment = Attachment::firstOrFail();
    $path = $attachment->path;

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->call('removeAttachment', $attachment->id);

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('shows existing invoice attachments on the show page', function () {
    Attachment::create([
        'attachable_type' => $this->invoice->getMorphClass(),
        'attachable_id' => $this->invoice->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/invoices/'.$this->invoice->id.'/fake.pdf',
        'original_filename' => 'po-12345.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 4321,
        'uploaded_by_id' => $this->user->id,
    ]);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->assertSee('po-12345.pdf');
});

it('cannot remove an attachment belonging to a different invoice', function () {
    $customer = Contact::factory()->customer()->create();
    $otherInvoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-2',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => InvoiceStatus::Draft,
    ]);

    $foreign = Attachment::create([
        'attachable_type' => $otherInvoice->getMorphClass(),
        'attachable_id' => $otherInvoice->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/invoices/'.$otherInvoice->id.'/x.pdf',
        'original_filename' => 'x.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'uploaded_by_id' => $this->user->id,
    ]);

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $this->invoice])
        ->call('removeAttachment', $foreign->id)
        ->assertStatus(404);

    expect(Attachment::whereKey($foreign->id)->exists())->toBeTrue();
});
