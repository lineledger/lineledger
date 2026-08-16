<?php

use App\Enums\CompanyRole;
use App\Enums\InvoiceStatus;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Storage\StorageDisks;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The storage-role indirection: uploads follow `filesystems.roles`, the disk is
 * recorded per row, and files written before a switch stay readable afterwards.
 */
beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    Storage::fake('s3');
    Storage::fake('s3_public');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($this->user);

    app()->instance('current_company', $this->company);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function makeInvoice(): Invoice
{
    $customer = Contact::factory()->customer()->create();

    return Invoice::create([
        'contact_id' => $customer->id,
        'invoice_no' => 'INV-'.fake()->unique()->numberBetween(1000, 9999),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'status' => InvoiceStatus::Draft,
    ]);
}

it('defaults every role to the local filesystem', function () {
    expect(StorageDisks::attachments())->toBe('local')
        ->and(StorageDisks::logos())->toBe('public')
        ->and(StorageDisks::backups())->toBe('local');
});

it('rejects a role pointing at a disk that is not configured', function () {
    config()->set('filesystems.roles.attachments', 'nope');

    expect(fn () => StorageDisks::attachments())
        ->toThrow(RuntimeException::class, 'nope');
});

it('reports which disks can hand out a real path', function () {
    expect(StorageDisks::isLocal('local'))->toBeTrue()
        ->and(StorageDisks::isLocal('public'))->toBeTrue()
        ->and(StorageDisks::isLocal('s3'))->toBeFalse();
});

it('writes attachments to the configured disk and records it on the row', function () {
    config()->set('filesystems.roles.attachments', 's3');

    $invoice = makeInvoice();

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('newAttachments', [UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->disk)->toBe('s3');
    Storage::disk('s3')->assertExists($attachment->path);
    Storage::disk('local')->assertMissing($attachment->path);
});

it('keeps serving and deleting files written before the disk changed', function () {
    // Uploaded while the install was still on local storage.
    $invoice = makeInvoice();

    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('newAttachments', [UploadedFile::fake()->create('old.pdf', 10, 'application/pdf')])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $legacy = Attachment::firstOrFail();
    expect($legacy->disk)->toBe('local');

    // The operator then moves attachments to object storage.
    config()->set('filesystems.roles.attachments', 's3');

    $this->get(route('attachments.download', [
        'company' => $this->company->slug,
        'attachment' => $legacy->id,
    ]))->assertOk();

    // And a new upload lands on S3 while the old row is untouched.
    Livewire::test('pages::invoices.show', ['company' => $this->company, 'invoice' => $invoice])
        ->set('newAttachments', [UploadedFile::fake()->create('new.pdf', 10, 'application/pdf')])
        ->call('uploadAttachments')
        ->assertHasNoErrors();

    $fresh = Attachment::where('original_filename', 'new.pdf')->firstOrFail();

    expect($fresh->disk)->toBe('s3')
        ->and($legacy->fresh()->disk)->toBe('local');

    Storage::disk('local')->assertExists($legacy->path);
    Storage::disk('s3')->assertExists($fresh->path);
});

it('writes company logos to the configured logo disk', function () {
    config()->set('filesystems.roles.logos', 's3_public');

    Livewire::test('pages::companies.edit', ['company' => $this->company])
        ->set('logo', UploadedFile::fake()->image('logo.png'))
        ->call('updateCompany')
        ->assertHasNoErrors();

    $path = $this->company->fresh()->logo_path;

    expect($path)->not->toBeNull();
    Storage::disk('s3_public')->assertExists($path);
    Storage::disk('public')->assertMissing($path);
});

it('builds the document logo data URI from the configured logo disk', function () {
    config()->set('filesystems.roles.logos', 's3_public');

    Storage::disk('s3_public')->put('company-logos/mark.png', 'not-really-a-png');
    $this->company->forceFill(['logo_path' => 'company-logos/mark.png'])->save();

    expect($this->company->fresh()->documentLogoDataUri())->toStartWith('data:');
});

it('memoizes the document logo so a multi-page render does not refetch it', function () {
    Storage::disk('public')->put('company-logos/mark.png', 'not-really-a-png');
    $company = $this->company->fresh();
    $company->forceFill(['logo_path' => 'company-logos/mark.png'])->save();

    $first = $company->documentLogoDataUri();

    // Delete the blob out from under the model: a second read would now miss.
    Storage::disk('public')->delete('company-logos/mark.png');

    expect($company->documentLogoDataUri())->toBe($first);
});

it('recomputes the document logo when the path changes on a live instance', function () {
    Storage::disk('public')->put('company-logos/old.png', 'old-bytes');
    Storage::disk('public')->put('company-logos/new.png', 'new-bytes');

    $company = $this->company->fresh();
    $company->forceFill(['logo_path' => 'company-logos/old.png'])->save();

    $old = $company->documentLogoDataUri();

    $company->forceFill(['logo_path' => 'company-logos/new.png'])->save();

    expect($company->documentLogoDataUri())
        ->not->toBe($old)
        ->and(base64_decode(explode(',', $company->documentLogoDataUri())[1]))->toBe('new-bytes');
});

it('caches the absence of a logo without probing the disk again', function () {
    $company = $this->company->fresh();

    expect($company->documentLogoDataUri())->toBeNull()
        ->and($company->documentLogoDataUri())->toBeNull();
});

/**
 * A blank `AWS_URL=` line in .env yields '' rather than null, and the S3 adapter
 * gates on isset() — which accepts ''. Left unguarded it builds every URL against
 * an empty base, so logos render as relative paths and 404 on the app domain.
 */
it('treats a blank AWS url env value as unset, not as an empty base URL', function () {
    // Re-evaluate the real config file with the env vars present but blank —
    // exactly what `AWS_URL=` in a .env produces.
    $restore = [];

    foreach (['AWS_URL', 'AWS_PUBLIC_URL', 'AWS_ENDPOINT'] as $key) {
        $restore[$key] = $_ENV[$key] ?? null;
        $_ENV[$key] = $_SERVER[$key] = '';
    }

    try {
        $config = require config_path('filesystems.php');

        expect($config['disks']['s3']['url'])->toBeNull()
            ->and($config['disks']['s3']['endpoint'])->toBeNull()
            ->and($config['disks']['s3_public']['url'])->toBeNull()
            ->and($config['disks']['s3_public']['endpoint'])->toBeNull();
    } finally {
        foreach ($restore as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $_SERVER[$key] = $value;
            }
        }
    }
});

it('would produce a relative URL without that guard', function () {
    // Pins the underlying adapter behaviour the guard exists to defeat: the S3
    // adapter gates on isset(), and isset('') is true.
    config()->set('filesystems.disks.s3_public.bucket', 'lineledger-public');
    config()->set('filesystems.disks.s3_public.region', 'ca-central-1');
    config()->set('filesystems.disks.s3_public.url', '');
    Storage::forgetDisk('s3_public');

    expect(Storage::disk('s3_public')->url('company-logos/mark.png'))
        ->toBe('/company-logos/mark.png');
})->skip(fn () => ! class_exists(AwsS3V3Adapter::class), 'S3 adapter not installed');

it('builds an absolute logo URL rather than a relative path', function () {
    config()->set('filesystems.roles.logos', 's3_public');
    config()->set('filesystems.disks.s3_public.bucket', 'lineledger-public');
    config()->set('filesystems.disks.s3_public.region', 'ca-central-1');
    config()->set('filesystems.disks.s3_public.url', 'https://lineledger-public.s3.ca-central-1.amazonaws.com');
    Storage::forgetDisk('s3_public');

    $this->company->forceFill(['logo_path' => 'company-logos/mark.png'])->save();

    $url = $this->company->fresh()->logoUrl();

    expect($url)->toStartWith('https://')
        ->and($url)->toBe('https://lineledger-public.s3.ca-central-1.amazonaws.com/company-logos/mark.png');
});
