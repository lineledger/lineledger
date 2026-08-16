<?php

use App\Actions\Documents\SaveDocumentFolder;
use App\Enums\CompanyRole;
use App\Enums\Section;
use App\Models\Attachment;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->owner = User::factory()->create();
    $this->admin = User::factory()->create();
    $this->member = User::factory()->create();

    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->owner, ['role' => CompanyRole::Owner->value]);
    $this->company->members()->attach($this->admin, ['role' => CompanyRole::Admin->value]);
    // Accountant reaches every section (incl. Documents) but is a regular member
    // for per-folder visibility.
    $this->company->members()->attach($this->member, ['role' => CompanyRole::Accountant->value]);

    app()->instance('current_company', $this->company);

    $this->ownerMembership = $this->owner->companyMembership($this->company);
    $this->memberMembership = $this->member->companyMembership($this->company);

    // Owner creates a private folder with one document.
    $this->folder = app(SaveDocumentFolder::class)->handle(['name' => 'Incorporation'], null, $this->ownerMembership);

    $this->actingAs($this->owner);
    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $this->folder])
        ->set('newFiles', [UploadedFile::fake()->create('articles.pdf', 30, 'application/pdf')])
        ->call('uploadFiles');

    $this->attachment = Attachment::where('attachable_id', $this->folder->id)->firstOrFail();
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function downloadUrl(Company $company, Attachment $attachment): string
{
    return route('attachments.download', ['company' => $company->slug, 'attachment' => $attachment->id]);
}

it('hides a private folder from a non-creator member on the index', function () {
    $this->actingAs($this->member);

    Livewire::test('pages::documents.index', ['company' => $this->company])
        ->assertDontSee('Incorporation');
});

it('forbids a non-shared member from opening or downloading', function () {
    $this->actingAs($this->member);

    $this->get(route('documents.show', ['company' => $this->company->slug, 'folder' => $this->folder->id]))
        ->assertStatus(403);

    $this->get(downloadUrl($this->company, $this->attachment))->assertStatus(403);
});

it('lets owner and admin see every folder without sharing', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::documents.index', ['company' => $this->company])
        ->assertSee('Incorporation');

    $this->get(downloadUrl($this->company, $this->attachment))->assertOk();
});

it('grants access once a member is shared on the folder', function () {
    $this->actingAs($this->owner);
    Livewire::test('pages::documents.show', ['company' => $this->company, 'folder' => $this->folder])
        ->set('shareMemberIds', [$this->memberMembership->id])
        ->call('saveSharing')
        ->assertHasNoErrors();

    expect($this->folder->fresh()->viewer_member_ids)->toContain($this->memberMembership->id);

    $this->actingAs($this->member);

    Livewire::test('pages::documents.index', ['company' => $this->company])
        ->assertSee('Incorporation');

    $this->get(route('documents.show', ['company' => $this->company->slug, 'folder' => $this->folder->id]))
        ->assertOk();

    $this->get(downloadUrl($this->company, $this->attachment))->assertOk();
});

it('blocks a member without the Documents section at the middleware', function () {
    $stranger = User::factory()->create();
    $this->company->members()->attach($stranger, [
        'role' => CompanyRole::Custom->value,
        'sections' => json_encode([Section::Customers->value]),
    ]);

    $this->actingAs($stranger);

    $this->get(route('documents.index', ['company' => $this->company->slug]))->assertStatus(403);
    $this->get(route('documents.attached-index', ['company' => $this->company->slug]))->assertStatus(403);
});

it('isolates folders across tenants', function () {
    $otherUser = User::factory()->create();
    $otherCompany = Company::factory()->create();
    $otherCompany->members()->attach($otherUser, ['role' => CompanyRole::Owner->value]);

    // A member of another company cannot reach this company's documents at all.
    $this->actingAs($otherUser);
    $this->get(route('documents.show', ['company' => $this->company->slug, 'folder' => $this->folder->id]))
        ->assertStatus(403);

    // And this company's owner cannot resolve a foreign folder id under their own tenant.
    app()->instance('current_company', $otherCompany);
    $this->actingAs($otherUser);
    $foreignFolderId = $this->folder->id;
    $this->get(route('documents.show', ['company' => $otherCompany->slug, 'folder' => $foreignFolderId]))
        ->assertStatus(404);
});
