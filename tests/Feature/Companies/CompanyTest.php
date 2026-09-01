<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

test('companies index page can be rendered', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('companies.index'));

    $response->assertOk();
});

test('companies can be created', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::companies.index')
        ->set('name', 'Test Company')
        ->call('createCompany')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('companies', [
        'name' => 'Test Company',
        'is_personal' => false,
    ]);
});

test('company slug uses next available suffix', function () {
    $user = User::factory()->create();

    Company::factory()->create(['name' => 'Acme', 'slug' => 'acme']);
    Company::factory()->create(['name' => 'Acme One', 'slug' => 'acme-1']);
    Company::factory()->create(['name' => 'Acme Ten', 'slug' => 'acme-10']);

    $this->actingAs($user);

    Livewire::test('pages::companies.index')
        ->set('name', 'Acme')
        ->call('createCompany')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('companies', [
        'name' => 'Acme',
        'slug' => 'acme-11',
    ]);
});

test('company edit page can be rendered', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('companies.edit', $company));

    $response->assertOk();
});

test('companies can be updated by owners', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Original Name']);

    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('companyName', 'Updated Name')
        ->call('updateCompany')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'name' => 'Updated Name',
    ]);
});

test('owners can save branding fields and upload a logo', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = Company::factory()->create(['name' => 'Just Cremation']);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('brandName', 'Just Cremation')
        ->set('brandInitials', 'jc')
        ->set('brandTextColor', '#ffffff')
        ->set('brandBackgroundColor', '#00aa55')
        ->set('logo', UploadedFile::fake()->image('logo.png', 200, 200))
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->brand_name)->toEqual('Just Cremation');
    expect($company->brand_initials)->toEqual('JC');
    expect($company->brand_text_color)->toEqual('#ffffff');
    expect($company->brand_background_color)->toEqual('#00aa55');
    expect($company->logo_path)->not->toBeNull();

    Storage::disk('public')->assertExists($company->logo_path);
});

test('owners can remove an existing logo', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $existingPath = UploadedFile::fake()->image('old.png')->store('company-logos', 'public');
    $company = Company::factory()->create(['logo_path' => $existingPath]);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('removeLogo', true)
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->fresh()->logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($existingPath);
});

test('owners can set and clear the legal name', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSet('legalName', '')
        ->set('legalName', 'Personal Alternative Funeral Services Limited')
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->refresh()->legal_name)->toEqual('Personal Alternative Funeral Services Limited');

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->assertSet('legalName', 'Personal Alternative Funeral Services Limited')
        ->set('legalName', '   ')
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->refresh()->legal_name)->toBeNull();
});

test('owners can save contact info fields', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('addressLine1', '123 Main St')
        ->set('addressCity', 'Calgary')
        ->set('phone', '403-555-0100')
        ->set('website', 'https://example.com')
        ->set('email', 'hello@example.com')
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->address_line1)->toEqual('123 Main St');
    expect($company->address_city)->toEqual('Calgary');
    expect($company->phone)->toEqual('403-555-0100');
    expect($company->website)->toEqual('https://example.com');
    expect($company->email)->toEqual('hello@example.com');
});

test('owners can upload a document logo and set its print height', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('documentLogo', UploadedFile::fake()->image('doc-logo.png', 300, 120))
        ->set('documentLogoMaxHeight', 90)
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->document_logo_path)->not->toBeNull();
    expect($company->document_logo_max_height)->toEqual(90);
    Storage::disk('public')->assertExists($company->document_logo_path);
});

test('owners can remove an existing document logo', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $existingPath = UploadedFile::fake()->image('old-doc.png')->store('company-logos', 'public');
    $company = Company::factory()->create(['document_logo_path' => $existingPath]);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('removeDocumentLogo', true)
        ->call('updateCompany')
        ->assertHasNoErrors();

    expect($company->fresh()->document_logo_path)->toBeNull();
    Storage::disk('public')->assertMissing($existingPath);
});

test('document logo height is bounded', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('documentLogoMaxHeight', 500)
        ->call('updateCompany')
        ->assertHasErrors('documentLogoMaxHeight');
});

test('website must be a valid URL', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('website', 'not-a-url')
        ->call('updateCompany')
        ->assertHasErrors(['website']);
});

test('brand color must be a valid hex', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('brandBackgroundColor', 'notahex')
        ->call('updateCompany')
        ->assertHasErrors(['brandBackgroundColor']);
});

test('feature toggles default to enabled and can be saved', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    expect($company->fresh()->features_employees)->toBeTrue();
    expect($company->fresh()->features_inventory)->toBeTrue();
    expect($company->fresh()->features_fixed_assets)->toBeTrue();
    expect($company->fresh()->features_estimates)->toBeTrue();
    expect($company->fresh()->features_sales_orders)->toBeTrue();
    expect($company->fresh()->features_recurring_invoices)->toBeTrue();
    expect($company->fresh()->features_recurring_bills)->toBeTrue();
    expect($company->fresh()->features_budgets)->toBeTrue();

    $this->actingAs($user);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('featuresEmployees', false)
        ->set('featuresInventory', false)
        ->set('featuresFixedAssets', false)
        ->set('featuresEstimates', false)
        ->set('featuresSalesOrders', false)
        ->set('featuresRecurringInvoices', false)
        ->set('featuresRecurringBills', false)
        ->set('featuresBudgets', false)
        ->call('updateCompany')
        ->assertHasNoErrors();

    $company->refresh();

    expect($company->features_employees)->toBeFalse();
    expect($company->features_inventory)->toBeFalse();
    expect($company->features_fixed_assets)->toBeFalse();
    expect($company->features_estimates)->toBeFalse();
    expect($company->features_sales_orders)->toBeFalse();
    expect($company->features_recurring_invoices)->toBeFalse();
    expect($company->features_recurring_bills)->toBeFalse();
    expect($company->features_budgets)->toBeFalse();
});

test('sidebar hides modules when feature toggles are disabled', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create([
        'features_employees' => false,
        'features_inventory' => false,
        'features_fixed_assets' => false,
        'features_estimates' => false,
        'features_sales_orders' => false,
        'features_recurring_invoices' => false,
        'features_recurring_bills' => false,
        'features_budgets' => false,
    ]);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $response = $this
        ->actingAs($user)
        ->get('/'.$company->slug.'/dashboard');

    $response->assertOk();
    $response->assertDontSee('/'.$company->slug.'/employees', escape: false);
    $response->assertDontSee('/'.$company->slug.'/reimbursements', escape: false);
    $response->assertDontSee('/'.$company->slug.'/inventory', escape: false);
    $response->assertDontSee('/'.$company->slug.'/assets', escape: false);
    $response->assertDontSee('/'.$company->slug.'/settings/lists/asset-categories', escape: false);
    $response->assertDontSee('/'.$company->slug.'/estimates', escape: false);
    $response->assertDontSee('/'.$company->slug.'/sales-orders', escape: false);
    // Trailing quote pins this to the recurring documents link; the always-on
    // recurring-journal link shares the /recurring prefix but not the boundary.
    $response->assertDontSee('/'.$company->slug.'/recurring"', escape: false);
    $response->assertDontSee('/'.$company->slug.'/budgets', escape: false);
});

test('companies cannot be updated by members', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($member);

    Livewire::test('pages::companies.edit', ['company' => $company])
        ->set('companyName', 'Updated Name')
        ->call('updateCompany')
        ->assertForbidden();
});

test('companies can be deleted by owners', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $company])
        ->set('deleteName', $company->name)
        ->call('deleteCompany')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('companies', [
        'id' => $company->id,
    ]);
});

test('company deletion requires name confirmation', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $this->actingAs($user);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $company])
        ->set('deleteName', 'Wrong Name')
        ->call('deleteCompany')
        ->assertHasErrors(['deleteName']);

    $this->assertDatabaseHas('companies', [
        'id' => $company->id,
        'deleted_at' => null,
    ]);
});

test('deleting current company switches to alphabetically first remaining company', function () {
    $user = User::factory()->create(['name' => 'Mike']);

    $zulu = Company::factory()->create(['name' => 'Zulu Company']);
    $zulu->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $alpha = Company::factory()->create(['name' => 'Alpha Company']);
    $alpha->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $beta = Company::factory()->create(['name' => 'Beta Company']);
    $beta->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $user->forceFill(['current_company_id' => $zulu->id])->save();

    $this->actingAs($user);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $zulu])
        ->set('deleteName', $zulu->name)
        ->call('deleteCompany')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('companies', [
        'id' => $zulu->id,
    ]);

    expect($user->fresh()->current_company_id)->toEqual($alpha->id);
});

test('deleting current company falls back to personal company when alphabetically first', function () {
    $user = User::factory()->create();
    $personalCompany = $user->personalCompany();
    $company = Company::factory()->create(['name' => 'Zulu Company']);
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $user->forceFill(['current_company_id' => $company->id])->save();

    $this->actingAs($user);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $company])
        ->set('deleteName', $company->name)
        ->call('deleteCompany')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('companies', [
        'id' => $company->id,
    ]);

    expect($user->fresh()->current_company_id)->toEqual($personalCompany->id);
});

test('deleting non current company leaves current company unchanged', function () {
    $user = User::factory()->create();
    $personalCompany = $user->personalCompany();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);

    $user->forceFill(['current_company_id' => $personalCompany->id])->save();

    $this->actingAs($user);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $company])
        ->set('deleteName', $company->name)
        ->call('deleteCompany')
        ->assertHasNoErrors();

    $this->assertSoftDeleted('companies', [
        'id' => $company->id,
    ]);

    expect($user->fresh()->current_company_id)->toEqual($personalCompany->id);
});

test('deleting company switches other affected users to their personal company', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $owner->forceFill(['current_company_id' => $company->id])->save();
    $member->forceFill(['current_company_id' => $company->id])->save();

    $this->actingAs($owner);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $company])
        ->set('deleteName', $company->name)
        ->call('deleteCompany')
        ->assertHasNoErrors();

    expect($member->fresh()->current_company_id)->toEqual($member->personalCompany()->id);
});

test('deleting a company keeps its membership rows so it can be restored', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    $company = Company::factory()->create();
    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($owner);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $company])
        ->set('deleteName', $company->name)
        ->call('deleteCompany')
        ->assertHasNoErrors();

    // Soft-deleted companies are already hidden by the SoftDeletes scope
    // everywhere it matters, so the pivot rows can survive — and must, or a
    // site admin restoring the company would hand back an ownerless shell.
    $this->assertDatabaseHas('company_members', [
        'company_id' => $company->id,
        'user_id' => $owner->id,
    ]);
    $this->assertDatabaseHas('company_members', [
        'company_id' => $company->id,
        'user_id' => $member->id,
    ]);

    // It is still invisible to both of them.
    expect($owner->fresh()->companies()->pluck('companies.id'))->not->toContain($company->id)
        ->and($member->fresh()->companies()->pluck('companies.id'))->not->toContain($company->id);
});

test('personal companies cannot be deleted', function () {
    $user = User::factory()->create();

    $personalCompany = $user->personalCompany();

    $this->actingAs($user);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $personalCompany])
        ->set('deleteName', $personalCompany->name)
        ->call('deleteCompany')
        ->assertForbidden();

    $this->assertDatabaseHas('companies', [
        'id' => $personalCompany->id,
        'deleted_at' => null,
    ]);
});

test('companies cannot be deleted by non owners', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $company = Company::factory()->create();

    $company->members()->attach($owner, ['role' => CompanyRole::Owner->value]);
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);

    $this->actingAs($member);

    Livewire::test('pages::companies.delete-company-modal', ['company' => $company])
        ->set('deleteName', $company->name)
        ->call('deleteCompany')
        ->assertForbidden();
});

test('guests cannot access companies', function () {
    $response = $this->get(route('companies.index'));

    $response->assertRedirect(route('login'));
});

test('user companies are listed alphabetically by display name', function () {
    $user = User::factory()->create();

    foreach (['Zebra Holdings', 'acme Supplies', 'Pacific Crematorium Limited'] as $name) {
        Company::factory()->create(['name' => $name])
            ->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    }

    $names = $user->toUserCompanies(includeCurrent: true)->pluck('displayName');

    expect($names->all())->toBe($names->sortBy(fn (string $name) => Str::lower($name), SORT_NATURAL)->values()->all())
        ->and($names->intersect(['Zebra Holdings', 'acme Supplies', 'Pacific Crematorium Limited'])->values()->all())
        ->toBe(['acme Supplies', 'Pacific Crematorium Limited', 'Zebra Holdings']);
});
