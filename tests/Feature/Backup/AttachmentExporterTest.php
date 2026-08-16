<?php

use App\Models\Attachment;
use App\Models\Company;
use App\Models\Contact;
use App\Services\Backup\AttachmentExporter;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');

    $this->company = Company::factory()->create(['name' => 'Acme']);
    app()->instance('current_company', $this->company);

    $this->customer = Contact::factory()->customer()->create(['display_name' => 'Wile E.']);

    // Two real blobs on the fake disk + an Attachment row pointing at each.
    Storage::disk('local')->put('attachments/'.$this->company->id.'/one.pdf', 'CONTENTS-ONE');
    Storage::disk('local')->put('attachments/'.$this->company->id.'/two.pdf', 'CONTENTS-TWO');

    $this->att1 = Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $this->customer->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/one.pdf',
        'original_filename' => 'one.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('CONTENTS-ONE'),
    ]);

    $this->att2 = Attachment::create([
        'attachable_type' => (new Contact)->getMorphClass(),
        'attachable_id' => $this->customer->id,
        'disk' => 'local',
        'path' => 'attachments/'.$this->company->id.'/two.pdf',
        'original_filename' => 'two.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen('CONTENTS-TWO'),
    ]);

    $this->workDir = sys_get_temp_dir().'/backup-attach-test-'.uniqid();
    mkdir($this->workDir, 0755, true);
});

afterEach(function () {
    app()->forgetInstance('current_company');

    if (is_dir($this->workDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->workDir);
    }
});

it('copies every attachment blob into files/ and returns a path-rewrite map', function () {
    $exporter = new AttachmentExporter;

    $result = $exporter->exportAttachments($this->company->id, $this->workDir);

    expect($result['files_count'])->toBe(2)
        ->and($result['missing'])->toBe([])
        ->and($result['bytes'])->toBe(strlen('CONTENTS-ONE') + strlen('CONTENTS-TWO'));

    expect($result['attachments'])->toHaveKey($this->att1->id)
        ->and($result['attachments'])->toHaveKey($this->att2->id);

    $relA = $result['attachments'][$this->att1->id];
    $relB = $result['attachments'][$this->att2->id];

    expect($relA)->toStartWith('files/attachments/contact/'.$this->customer->id.'/')
        ->and($relA)->toEndWith('one.pdf');

    expect(file_exists($this->workDir.'/'.$relA))->toBeTrue()
        ->and(file_get_contents($this->workDir.'/'.$relA))->toBe('CONTENTS-ONE');

    expect(file_exists($this->workDir.'/'.$relB))->toBeTrue()
        ->and(file_get_contents($this->workDir.'/'.$relB))->toBe('CONTENTS-TWO');
});

it('records the attachment id in missing[] when the source blob is gone', function () {
    Storage::disk('local')->delete('attachments/'.$this->company->id.'/two.pdf');

    $exporter = new AttachmentExporter;
    $result = $exporter->exportAttachments($this->company->id, $this->workDir);

    expect($result['files_count'])->toBe(1)
        ->and($result['missing'])->toBe([$this->att2->id])
        ->and($result['attachments'])->toHaveKey($this->att1->id)
        ->and($result['attachments'])->not->toHaveKey($this->att2->id);
});

it('copies a company logo into files/company-logo and returns the relative path', function () {
    Storage::disk('public')->put('logos/'.$this->company->id.'/brand.png', 'PNG-BYTES');
    $this->company->update(['logo_path' => 'logos/'.$this->company->id.'/brand.png']);

    $exporter = new AttachmentExporter;
    $relative = $exporter->exportCompanyLogo($this->company->fresh(), $this->workDir);

    expect($relative)->toBe('files/company-logo/brand.png')
        ->and(file_get_contents($this->workDir.'/'.$relative))->toBe('PNG-BYTES');
});

it('returns null from exportCompanyLogo when no logo is set', function () {
    $exporter = new AttachmentExporter;

    expect($exporter->exportCompanyLogo($this->company, $this->workDir))->toBeNull();
});

it('copies a company document logo into files/company-document-logo', function () {
    Storage::disk('public')->put('logos/'.$this->company->id.'/doc.png', 'DOC-PNG-BYTES');
    $this->company->update(['document_logo_path' => 'logos/'.$this->company->id.'/doc.png']);

    $exporter = new AttachmentExporter;
    $relative = $exporter->exportCompanyDocumentLogo($this->company->fresh(), $this->workDir);

    expect($relative)->toBe('files/company-document-logo/doc.png')
        ->and(file_get_contents($this->workDir.'/'.$relative))->toBe('DOC-PNG-BYTES');
});
