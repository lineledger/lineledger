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

    $this->customer = Contact::factory()->customer()->create();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('uploads a PDF to a customer', function () {
    $file = UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf');

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openEdit', $this->customer->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_id)->toBe($this->customer->id)
        ->and($attachment->attachable_type)->toBe((new Contact)->getMorphClass())
        ->and($attachment->company_id)->toBe($this->company->id)
        ->and($attachment->original_filename)->toBe('agreement.pdf');

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects an oversized customer attachment', function () {
    // Attachments share a 10 MB per-file cap across the app.
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openEdit', $this->customer->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('removes a customer attachment and deletes the file', function () {
    $file = UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf');

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openEdit', $this->customer->id)
        ->set('newAttachments', [$file])
        ->call('uploadAttachments');

    $attachment = Attachment::firstOrFail();
    $path = $attachment->path;

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openEdit', $this->customer->id)
        ->call('removeAttachment', $attachment->id);

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('shows existing customer attachments in the edit modal', function () {
    Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $this->customer->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/contacts/'.$this->customer->id.'/fake.pdf',
        'original_filename' => 'existing.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1234,
        'uploaded_by_id' => $this->user->id,
    ]);

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openEdit', $this->customer->id)
        ->set('formTab', 'attachments')
        ->assertSee('existing.pdf');
});

it('attaches buffered files when creating a new customer', function () {
    $file = UploadedFile::fake()->create('intake.pdf', 100, 'application/pdf');

    Livewire::test('pages::customers.index', ['company' => $this->company])
        ->call('openCreate')
        ->set('f_display_name', 'Fresh Co')
        ->set('newAttachments', [$file])
        ->call('save')
        ->assertHasNoErrors();

    $customer = Contact::query()->where('display_name', 'Fresh Co')->firstOrFail();

    $attachment = Attachment::query()
        ->where('attachable_id', $customer->id)
        ->where('attachable_type', (new Contact)->getMorphClass())
        ->firstOrFail();

    expect($attachment->original_filename)->toBe('intake.pdf');
    Storage::disk('local')->assertExists($attachment->path);
});
