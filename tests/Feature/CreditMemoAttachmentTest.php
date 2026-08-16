<?php

use App\Enums\CompanyRole;
use App\Enums\CreditMemoStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\CreditMemo;
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

    $this->creditMemo = CreditMemo::create([
        'contact_id' => $customer->id,
        'credit_memo_no' => 'CM-1',
        'credit_memo_date' => now()->toDateString(),
        'status' => CreditMemoStatus::Draft,
    ]);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('uploads a PDF to a credit memo', function () {
    $file = UploadedFile::fake()->create('return-authorization.pdf', 100, 'application/pdf');

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $this->creditMemo])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_id)->toBe($this->creditMemo->id)
        ->and($attachment->attachable_type)->toBe($this->creditMemo->getMorphClass())
        ->and($attachment->company_id)->toBe($this->company->id)
        ->and($attachment->original_filename)->toBe('return-authorization.pdf');

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects a disallowed credit-memo attachment type', function () {
    $file = UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream');

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $this->creditMemo])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments')
        ->assertHasErrors(['newAttachments.0']);

    expect(Attachment::count())->toBe(0);
});

it('removes a credit-memo attachment and deletes the file', function () {
    $file = UploadedFile::fake()->create('cm.pdf', 50, 'application/pdf');

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $this->creditMemo])
        ->set('newAttachments', [$file])
        ->call('uploadAttachments');

    $attachment = Attachment::firstOrFail();
    $path = $attachment->path;

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $this->creditMemo])
        ->call('removeAttachment', $attachment->id);

    expect(Attachment::count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

it('shows existing credit-memo attachments on the show page', function () {
    Attachment::create([
        'attachable_type' => $this->creditMemo->getMorphClass(),
        'attachable_id' => $this->creditMemo->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/credit_memos/'.$this->creditMemo->id.'/fake.pdf',
        'original_filename' => 'rma-doc.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 9999,
        'uploaded_by_id' => $this->user->id,
    ]);

    Livewire::test('pages::credit-memos.show', ['company' => $this->company, 'credit_memo' => $this->creditMemo])
        ->assertSee('rma-doc.pdf');
});
