<?php

use App\Enums\BillType;
use App\Enums\Section;
use App\Models\Attachment;
use App\Models\Bill;
use App\Models\Company;
use App\Models\Contact;
use App\Models\DocumentFolder;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Attachments\AttachmentAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * One download route serves every record's attachments, so it is not gated by
 * section; each download is gated by the record it belongs to instead — or by
 * Documents, whose attachment index lists every record's files.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->company = Company::factory()->create();
    app()->instance('current_company', $this->company);

    $contact = Contact::create(['display_name' => 'Acme', 'is_customer' => true, 'is_vendor' => true, 'is_employee' => true]);
    $dates = ['due_date' => CarbonImmutable::create(2026, 5, 31)];

    $this->invoice = Invoice::create(['contact_id' => $contact->id, 'invoice_no' => 'INV-1', 'invoice_date' => '2026-05-01', ...$dates]);
    $this->bill = Bill::create(['contact_id' => $contact->id, 'bill_type' => BillType::Vendor, 'bill_no' => 'B-1', 'bill_date' => '2026-05-01', ...$dates]);
    $this->reimbursement = Bill::create(['contact_id' => $contact->id, 'bill_type' => BillType::Reimbursement, 'bill_no' => 'R-1', 'bill_date' => '2026-05-01', ...$dates]);

    $this->attach = function (Model $record): Attachment {
        $path = 'attachments/'.uniqid().'.pdf';
        Storage::disk('local')->put($path, '%PDF-');

        return Attachment::create([
            'attachable_type' => $record->getMorphClass(),
            'attachable_id' => $record->getKey(),
            'disk' => 'local',
            'path' => $path,
            'original_filename' => 'receipt.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 5,
            'uploaded_by_id' => null,
        ]);
    };

    $this->download = fn (User $user, Attachment $attachment) => $this->actingAs($user)
        ->get(route('attachments.download', ['company' => $this->company->slug, 'attachment' => $attachment->id]));
});

afterEach(fn () => app()->forgetInstance('current_company'));

it('lets a member download attachments of records in their sections only', function () {
    $member = customSectionsMember($this->company, [Section::Customers]);

    ($this->download)($member, ($this->attach)($this->invoice))->assertOk();
    ($this->download)($member, ($this->attach)($this->bill))->assertForbidden();
});

it('gates bill attachments by the kind of bill', function () {
    $employees = customSectionsMember($this->company, [Section::Employees]);

    ($this->download)($employees, ($this->attach)($this->reimbursement))->assertOk();
    ($this->download)($employees, ($this->attach)($this->bill))->assertForbidden();
});

it('lets a Documents member download any record\'s attachment, as the attachment index lists them', function () {
    $documents = customSectionsMember($this->company, [Section::Documents]);

    ($this->download)($documents, ($this->attach)($this->invoice))->assertOk();
    ($this->download)($documents, ($this->attach)($this->bill))->assertOk();
});

it('maps every model that takes attachments to the sections that open it', function () {
    $attachable = collect(File::files(app_path('Models')))
        ->filter(fn (SplFileInfo $file): bool => str_contains($file->getContents(), 'morphMany(Attachment::class'))
        ->map(fn (SplFileInfo $file): string => 'App\\Models\\'.$file->getBasename('.php'))
        ->reject(fn (string $class): bool => $class === DocumentFolder::class) // behind DocumentFolderPolicy instead
        ->sort()
        ->values()
        ->all();

    expect($attachable)->not->toBeEmpty()
        ->and(collect(array_keys(AttachmentAccess::SECTIONS))->sort()->values()->all())->toBe($attachable);
});
