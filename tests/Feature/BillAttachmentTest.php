<?php

use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Enums\CompanyRole;
use App\Models\Attachment;
use App\Models\Bill;
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

    $vendor = Contact::factory()->vendor()->create();

    $this->bill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-1',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => BillStatus::Draft,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('uploads a PDF to a bill', function () {
    $file = UploadedFile::fake()->create('vendor-invoice.pdf', 100, 'application/pdf');

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_id)->toBe($this->bill->id)
        ->and($attachment->attachable_type)->toBe($this->bill->getMorphClass())
        ->and($attachment->company_id)->toBe($this->company->id)
        ->and($attachment->original_filename)->toBe('vendor-invoice.pdf')
        ->and($attachment->mime_type)->toBe('application/pdf')
        ->and($attachment->uploaded_by_id)->toBe($this->user->id);

    Storage::disk('local')->assertExists($attachment->path);
});

it('uploads multiple files to a bill at once', function () {
    $files = [
        UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf'),
        UploadedFile::fake()->image('packing-slip.jpg', 400, 400),
    ];

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->set('newAttachments', $files)
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    expect(Attachment::count())->toBe(2);
});

it('rejects an oversized bill attachment', function () {
    $file = UploadedFile::fake()->create('huge.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('rejects a disallowed bill attachment type', function () {
    $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('removes a bill attachment and deletes the file', function () {
    $file = UploadedFile::fake()->create('invoice.pdf', 50, 'application/pdf');

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments');

    $attachment = Attachment::firstOrFail();
    $path = $attachment->path;

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->call('removeAttachment', $attachment->id);

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('shows existing bill attachments on the show page', function () {
    Attachment::create([
        'attachable_type' => $this->bill->getMorphClass(),
        'attachable_id' => $this->bill->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/bills/'.$this->bill->id.'/fake.pdf',
        'original_filename' => 'supplier-invoice.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 4321,
        'uploaded_by_id' => $this->user->id,
    ]);

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->assertSee('supplier-invoice.pdf');
});

it('cannot remove an attachment belonging to a different bill', function () {
    $vendor = Contact::factory()->vendor()->create();
    $otherBill = Bill::create([
        'contact_id' => $vendor->id,
        'bill_type' => BillType::Vendor,
        'bill_no' => 'BILL-2',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => BillStatus::Draft,
    ]);

    $foreign = Attachment::create([
        'attachable_type' => $otherBill->getMorphClass(),
        'attachable_id' => $otherBill->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/bills/'.$otherBill->id.'/x.pdf',
        'original_filename' => 'x.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'uploaded_by_id' => $this->user->id,
    ]);

    Livewire::test('pages::bills.show', ['company' => $this->company, 'bill' => $this->bill])
        ->call('removeAttachment', $foreign->id)
        ->assertStatus(404);

    expect(Attachment::whereKey($foreign->id)->exists())->toBeTrue();
});
