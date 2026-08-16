<?php

use App\Actions\Documents\SaveDocumentFolder;
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
    $this->membership = $this->user->companyMembership($this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('lists transaction attachments and links to the source, excluding repository documents', function () {
    $customer = Contact::factory()->customer()->create();
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-77',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => InvoiceStatus::Draft,
    ]);

    $invoiceAttachment = Attachment::create([
        'attachable_type' => $invoice->getMorphClass(),
        'attachable_id' => $invoice->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/invoices/'.$invoice->id.'/signed.pdf',
        'original_filename' => 'signed-contract.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'uploaded_by_id' => $this->user->id,
    ]);

    // A repository document that must NOT appear in the transaction index.
    $folder = app(SaveDocumentFolder::class)->handle(['name' => 'HR'], null, $this->membership);
    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
        ->set('newFiles', [UploadedFile::fake()->create('handbook.pdf', 20, 'application/pdf')])
        ->call('uploadFiles');

    Livewire::test('pages::documents.attached-index', ['company' => $this->company])
        ->assertSee('signed-contract.pdf')
        ->assertSee('INV-77')
        ->assertDontSee('handbook.pdf');

    expect($invoiceAttachment->attachable_type)->not->toBe($folder->getMorphClass());
});
