<?php

use App\Actions\Documents\SaveDocumentFolder;
use App\Enums\CompanyRole;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\DocumentFolder;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('creates a top-level folder from the index', function () {
    Livewire::test('pages::documents.index', ['company' => $this->company])
        ->set('folderName', 'Incorporation')
        ->call('createFolder')
        ->assertHasNoErrors();

    $folder = DocumentFolder::firstOrFail();

    expect($folder->name)->toBe('Incorporation')
        ->and($folder->company_id)->toBe($this->company->id)
        ->and($folder->parent_folder_id)->toBeNull()
        ->and($folder->created_by_member_id)->toBe($this->membership->id)
        ->and($folder->created_by_user_id)->toBe($this->user->id);
});

it('creates a nested subfolder', function () {
    $parent = app(SaveDocumentFolder::class)->handle(['name' => 'Legal'], null, $this->membership);

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $parent])
        ->set('subfolderName', 'Contracts')
        ->call('createSubfolder')
        ->assertHasNoErrors();

    $child = DocumentFolder::where('name', 'Contracts')->firstOrFail();

    expect($child->parent_folder_id)->toBe($parent->id);
});

it('refuses to move a folder into its own descendant', function () {
    $parent = app(SaveDocumentFolder::class)->handle(['name' => 'A'], null, $this->membership);
    $child = app(SaveDocumentFolder::class)->handle(['name' => 'B', 'parent_folder_id' => $parent->id], null, $this->membership);

    expect(fn () => app(SaveDocumentFolder::class)->handle(
        ['name' => 'A', 'parent_folder_id' => $child->id],
        $parent,
        $this->membership,
    ))->toThrow(HttpException::class);
});

it('uploads a large document up to 10 MB', function () {
    $folder = app(SaveDocumentFolder::class)->handle(['name' => 'Scans'], null, $this->membership);
    $file = UploadedFile::fake()->create('articles.pdf', 8 * 1024, 'application/pdf');

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
        ->set('newFiles', [$file])
        ->call('uploadFiles')
        ->assertHasNoErrors();

    $attachment = Attachment::firstOrFail();

    expect($attachment->attachable_type)->toBe($folder->getMorphClass())
        ->and($attachment->attachable_id)->toBe($folder->id)
        ->and($attachment->original_filename)->toBe('articles.pdf');

    Storage::disk('local')->assertExists($attachment->path);
});

it('rejects a document larger than 10 MB', function () {
    $folder = app(SaveDocumentFolder::class)->handle(['name' => 'Scans'], null, $this->membership);
    $file = UploadedFile::fake()->create('too-big.pdf', 11 * 1024, 'application/pdf');

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
        ->set('newFiles', [$file])
        ->call('uploadFiles')
        ->assertHasErrors(['newFiles.0']);

    expect(Attachment::count())->toBe(0);
});

it('renames a folder without touching its stored files', function () {
    $folder = app(SaveDocumentFolder::class)->handle(['name' => 'Old'], null, $this->membership);
    $file = UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf');

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
        ->set('newFiles', [$file])
        ->call('uploadFiles');

    $path = Attachment::firstOrFail()->path;

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
        ->set('renameName', 'New name')
        ->call('rename')
        ->assertHasNoErrors();

    expect($folder->fresh()->name)->toBe('New name');
    Storage::disk('local')->assertExists($path);
});

it('deletes a folder and cascades to subfolders and files', function () {
    $parent = app(SaveDocumentFolder::class)->handle(['name' => 'Parent'], null, $this->membership);
    $child = app(SaveDocumentFolder::class)->handle(['name' => 'Child', 'parent_folder_id' => $parent->id], null, $this->membership);

    foreach ([$parent, $child] as $folder) {
        Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $folder])
            ->set('newFiles', [UploadedFile::fake()->create('f.pdf', 15, 'application/pdf')])
            ->call('uploadFiles');
    }

    $paths = Attachment::pluck('path')->all();
    expect($paths)->toHaveCount(2);

    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $parent])
        ->call('deleteFolder')
        ->assertRedirect(route('documents.index', ['company' => $this->company->slug]));

    expect(DocumentFolder::count())->toBe(0)
        ->and(Attachment::count())->toBe(0);

    foreach ($paths as $path) {
        Storage::disk('local')->assertMissing($path);
    }
});
