<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->company = Company::factory()->create();
    $this->company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
    app()->instance('current_company', $this->company);
    $this->actingAs($this->user);
});

afterEach(function () {
    app()->forgetInstance('current_company');
});

function itemCategoriesCsvUpload(string $body): UploadedFile
{
    return UploadedFile::fake()->createWithContent('item-categories.csv', $body);
}

it('streams a downloadable item-categories template', function () {
    $response = $this->get(route('lists.template', ['company' => $this->company->slug, 'list' => 'item-categories']));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    expect($response->streamedContent())->toContain('name,parent_name,is_active');
});

it('imports categories and nests a child under a parent named in the file', function () {
    $csv = <<<'CSV'
    name,parent_name,is_active
    Caskets,,yes
    Wood Caskets,Caskets,yes
    CSV;

    $component = Livewire::test('pages::settings.lists.item-categories', ['company' => $this->company])
        ->set('importFile', itemCategoriesCsvUpload($csv))
        ->call('previewImport');

    expect($component->get('importErrors'))->toBe([]);
    expect(collect($component->get('importPreviewRows'))->where('action', 'create'))->toHaveCount(2);

    $component->call('runImport');

    $parent = ItemCategory::query()->where('name', 'Caskets')->first();
    $child = ItemCategory::query()->where('name', 'Wood Caskets')->first();

    expect($parent)->not->toBeNull();
    expect($child)->not->toBeNull();
    expect($child->parent_id)->toBe($parent->id);
});

it('skips a category whose name already exists', function () {
    ItemCategory::create(['name' => 'Hardware']);

    $csv = "name,parent_name,is_active\nHardware,,yes\nSoftware,,yes\n";

    $component = Livewire::test('pages::settings.lists.item-categories', ['company' => $this->company])
        ->set('importFile', itemCategoriesCsvUpload($csv))
        ->call('previewImport');

    expect(collect($component->get('importPreviewRows'))->where('action', 'create'))->toHaveCount(1);
    expect($component->get('importSummary')['skipped_existing'])->toBe(1);

    $component->call('runImport');

    expect(ItemCategory::query()->where('name', 'Hardware')->count())->toBe(1);
    expect(ItemCategory::query()->where('name', 'Software')->exists())->toBeTrue();
});

it('errors and creates nothing when a parent is named before it exists', function () {
    $csv = <<<'CSV'
    name,parent_name,is_active
    Orphan,Nonexistent Parent,yes
    CSV;

    $component = Livewire::test('pages::settings.lists.item-categories', ['company' => $this->company])
        ->set('importFile', itemCategoriesCsvUpload($csv))
        ->call('runImport');

    expect($component->get('importErrors'))->not->toBe([]);
    expect(ItemCategory::query()->where('name', 'Orphan')->exists())->toBeFalse();
});
