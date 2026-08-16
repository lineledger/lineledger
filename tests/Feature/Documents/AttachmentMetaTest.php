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

function makeAttachment(Company $company, $attachable, string $mime, string $name): Attachment
{
    $path = 'attachments/'.$company->id.'/'.$name;
    Storage::disk('local')->put($path, 'BYTES');

    return Attachment::create([
        'attachable_type' => $attachable->getMorphClass(),
        'attachable_id' => $attachable->getKey(),
        'disk' => 'local',
        'path' => $path,
        'original_filename' => $name,
        'mime_type' => $mime,
        'size_bytes' => 5,
        'uploaded_by_id' => null,
    ]);
}

function dispositionOf($response): string
{
    return (string) $response->headers->get('content-disposition');
}

it('serves a PDF inline when requested and as a download otherwise', function () {
    $contact = Contact::factory()->customer()->create();
    $att = makeAttachment($this->company, $contact, 'application/pdf', 'statement.pdf');

    $base = route('attachments.download', ['company' => $this->company->slug, 'attachment' => $att->id]);

    expect(dispositionOf($this->get($base.'?inline=1')))->toContain('inline')
        ->and(dispositionOf($this->get($base)))->toContain('attachment');
});

it('forces download for non-inline types even when inline is requested', function () {
    $contact = Contact::factory()->customer()->create();
    $att = makeAttachment($this->company, $contact, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'memo.docx');

    $response = $this->get(route('attachments.download', ['company' => $this->company->slug, 'attachment' => $att->id]).'?inline=1');

    expect(dispositionOf($response))->toContain('attachment');
});

it('renames a document and sets its description without moving the blob', function () {
    $folder = app(SaveDocumentFolder::class)->handle(['name' => 'Legal'], null, $this->membership);

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
        ->set('newFiles', [UploadedFile::fake()->create('raw.pdf', 30, 'application/pdf')])
        ->call('uploadFiles');

    $att = Attachment::firstOrFail();
    $path = $att->path;

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
        ->call('openFileModal', $att->id)
        ->set('editFileName', 'Articles of Incorporation.pdf')
        ->set('editFileDescription', 'Filed with BC registry, 2020.')
        ->call('saveFile')
        ->assertHasNoErrors();

    $att->refresh();
    expect($att->original_filename)->toBe('Articles of Incorporation.pdf')
        ->and($att->description)->toBe('Filed with BC registry, 2020.')
        ->and($att->path)->toBe($path);

    Storage::disk('local')->assertExists($path);
});

it('edits the description from the attachment index', function () {
    $customer = Contact::factory()->customer()->create();
    $invoice = Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-9',
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => InvoiceStatus::Draft,
    ]);
    $att = makeAttachment($this->company, $invoice, 'application/pdf', 'po.pdf');

    Livewire::test('pages::documents.attached-index', ['company' => $this->company])
        ->assertSee('Description')
        ->call('editDescription', $att->id)
        ->set('editDescription', 'Customer purchase order')
        ->call('saveDescription')
        ->assertHasNoErrors()
        ->assertSee('Customer purchase order');

    expect($att->fresh()->description)->toBe('Customer purchase order');
});
