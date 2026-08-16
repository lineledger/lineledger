<?php

use App\Enums\CompanyRole;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
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
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('uploads a PDF to a vendor', function () {
    $file = UploadedFile::fake()->create('w9.pdf', 100, 'application/pdf');

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openEdit', $this->vendor->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_id)->toBe($this->vendor->id)
        ->and($attachment->attachable_type)->toBe((new Contact)->getMorphClass())
        ->and($attachment->company_id)->toBe($this->company->id)
        ->and($attachment->original_filename)->toBe('w9.pdf')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->uploaded_by_id)->toBe($this->user->id);

    Storage::disk('local')->assertExists($attachment->path);
});

it('uploads multiple files at once', function () {
    $files = [
        UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'),
        UploadedFile::fake()->image('receipt.jpg', 200, 200),
    ];

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openEdit', $this->vendor->id)
        ->set('newAttachments', $files)
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    expect(Attachment::count())->toBe(2);
    expect(Attachment::pluck('original_filename')->sort()->values()->all())
        ->toBe(['contract.pdf', 'receipt.jpg']);
});

it('rejects a file larger than 10 MB', function () {
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openEdit', $this->vendor->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('rejects a disallowed file type', function () {
    $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openEdit', $this->vendor->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('removes an attachment and deletes the file', function () {
    $file = UploadedFile::fake()->create('w9.pdf', 50, 'application/pdf');

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openEdit', $this->vendor->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments');

    $attachment = Attachment::firstOrFail();
    $path = $attachment->path;

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openEdit', $this->vendor->id)
        ->call('removeAttachment', $attachment->id);

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('shows existing attachments in the edit modal', function () {
    Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $this->vendor->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/contacts/'.$this->vendor->id.'/fake.pdf',
        'original_filename' => 'existing-contract.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1234,
        'uploaded_by_id' => $this->user->id,
    ]);

    Livewire::test('pages::vendors.index', ['company' => $this->company])
        ->call('openEdit', $this->vendor->id)
        ->assertSee('existing-contract.pdf');
});

it('blocks download of an attachment from another company', function () {
    $otherCompany = Company::factory()->create();
    app()->instance('current_company', $otherCompany);
    $otherVendor = Contact::factory()->vendor()->create();
    $otherAttachment = Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $otherVendor->id,
        'disk' => 'local',
        'path' => 'attachments/'.$otherCompany->id.'/contacts/'.$otherVendor->id.'/fake.pdf',
        'original_filename' => 'secret.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'uploaded_by_id' => null,
    ]);

    app()->instance('current_company', $this->company);

    $this->get(route('attachments.download', [
        'company' => $this->company->slug,
        'attachment' => $otherAttachment->id,
    ]))->assertNotFound();
});
