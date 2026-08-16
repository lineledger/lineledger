<?php

use App\Enums\CompanyRole;
use App\Enums\SecurityEvent;
use App\Models\Company;
use App\Models\CompanyApiKey;
use App\Models\SecurityLog;
use App\Models\User;
use Livewire\Livewire;

afterEach(function () {
    app()->forgetInstance('current_company');
});

it('lets an owner create an API key via Livewire', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->set('label', 'Storefront sync')
        ->call('create')
        ->assertSet('plaintext', fn (?string $value) => is_string($value) && str_starts_with($value, 'll_live_'));

    $key = CompanyApiKey::query()->withoutGlobalScopes()->firstOrFail();
    expect($key->label)->toBe('Storefront sync')
        ->and($key->company_id)->toBe($user->currentCompany->id)
        ->and($key->expires_at)->toBeNull();

    expect(SecurityLog::query()->where('event', SecurityEvent::ApiKeyCreated->value)->exists())->toBeTrue();
});

it('creates an API key with a chosen expiry', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->set('label', 'Expiring key')
        ->set('expiresIn', '30')
        ->call('create')
        ->assertHasNoErrors();

    $key = CompanyApiKey::query()->withoutGlobalScopes()->where('label', 'Expiring key')->firstOrFail();

    expect($key->expires_at)->not->toBeNull()
        ->and($key->expires_at->isFuture())->toBeTrue()
        ->and($key->expires_at->diffInDays(now()))->toBeLessThanOrEqual(30)
        ->and($key->isActive())->toBeTrue();
});

it('creates a scoped API key with selected abilities', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->set('label', 'Read-only sync')
        ->set('abilities', ['sales:read', 'accounting:read'])
        ->call('create')
        ->assertSet('plaintext', fn (?string $value) => is_string($value) && str_starts_with($value, 'll_live_'));

    $key = CompanyApiKey::query()->withoutGlobalScopes()->firstOrFail();
    expect($key->abilities)->toBe(['sales:read', 'accounting:read'])
        ->and($key->hasAbility('sales:read'))->toBeTrue()
        ->and($key->hasAbility('sales:write'))->toBeFalse();
});

it('creates a key scoped to a single resource', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->set('label', 'Invoices only')
        ->set('abilities', ['invoices:write'])
        ->call('create')
        ->assertSet('plaintext', fn (?string $value) => is_string($value));

    $key = CompanyApiKey::query()->withoutGlobalScopes()->firstOrFail();
    expect($key->abilities)->toBe(['invoices:write'])
        ->and($key->hasAbility('invoices:write'))->toBeTrue()
        ->and($key->hasAbility('invoices:read'))->toBeTrue()
        ->and($key->hasAbility('receipts:write'))->toBeFalse()
        ->and($key->hasAbility('sales:write'))->toBeFalse();
});

it('rejects an unknown scope', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->set('label', 'Bad scope')
        ->set('abilities', ['not-a-resource:write'])
        ->call('create')
        ->assertHasErrors('abilities.0');

    expect(CompanyApiKey::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('persists no abilities as full access', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->set('label', 'Full access')
        ->call('create');

    $key = CompanyApiKey::query()->withoutGlobalScopes()->firstOrFail();
    expect($key->abilities)->toBeNull()
        ->and($key->hasAbility('sales:write'))->toBeTrue();
});

it('edits an existing key\'s label and scopes without changing the token', function () {
    $user = User::factory()->create();
    $company = $user->currentCompany;

    ['key' => $key, 'plaintext' => $plain] = CompanyApiKey::mint($company, 'Storefront', $user->id, ['sales:read']);

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->call('openEditModal', $key->id)
        ->assertSet('editingKeyId', $key->id)
        ->assertSet('label', 'Storefront')
        ->assertSet('abilities', ['sales:read'])
        ->set('label', 'Storefront sync')
        ->set('abilities', ['sales:write', 'accounting:read'])
        ->call('update')
        ->assertSet('showKeyModal', false)
        ->assertSet('editingKeyId', null);

    $key->refresh();

    expect($key->label)->toBe('Storefront sync')
        ->and($key->abilities)->toBe(['sales:write', 'accounting:read'])
        ->and($key->token_hash)->toBe(hash('sha256', $plain))
        ->and($key->revoked_at)->toBeNull();

    expect(SecurityLog::query()->where('event', SecurityEvent::ApiKeyUpdated->value)->exists())->toBeTrue();

    // The same secret still authenticates, now with the widened scope.
    $this->postJson('/api/v1/customers', ['display_name' => 'Edited scope'], [
        'Authorization' => "Bearer {$plain}",
    ])->assertSuccessful();
});

it('clears all scopes back to full access when editing', function () {
    $user = User::factory()->create();
    ['key' => $key] = CompanyApiKey::mint($user->currentCompany, 'Storefront', $user->id, ['sales:read']);

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->call('openEditModal', $key->id)
        ->set('abilities', [])
        ->call('update');

    expect($key->fresh()->abilities)->toBeNull();
});

it('rejects an invalid scope when editing', function () {
    $user = User::factory()->create();
    ['key' => $key] = CompanyApiKey::mint($user->currentCompany, 'Storefront', $user->id, ['sales:read']);

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->call('openEditModal', $key->id)
        ->set('abilities', ['not-a-resource:write'])
        ->call('update')
        ->assertHasErrors('abilities.0');

    expect($key->fresh()->abilities)->toBe(['sales:read']);
});

it('will not edit a revoked key', function () {
    $user = User::factory()->create();
    ['key' => $key] = CompanyApiKey::mint($user->currentCompany, 'Storefront', $user->id, ['sales:read']);
    $key->revoke();

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->call('openEditModal', $key->id)
        ->assertSet('editingKeyId', null)
        ->assertSet('showKeyModal', false);

    expect($key->fresh()->abilities)->toBe(['sales:read']);
});

it('will not let another company\'s member edit a key', function () {
    $owner = User::factory()->create();
    ['key' => $key] = CompanyApiKey::mint($owner->currentCompany, 'Storefront', $owner->id, ['sales:read']);

    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)
        ->test('pages::settings.api-keys')
        ->call('openEditModal', $key->id)
        ->assertSet('editingKeyId', null);

    expect($key->fresh()->label)->toBe('Storefront');
});

it('does not let a non-admin member edit a key', function () {
    $owner = User::factory()->create();
    $company = $owner->currentCompany;
    ['key' => $key] = CompanyApiKey::mint($company, 'Storefront', $owner->id, ['sales:read']);

    $member = User::factory()->create();
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);
    $member->switchCompany($company);

    Livewire::actingAs($member)
        ->test('pages::settings.api-keys')
        ->call('openEditModal', $key->id)
        ->assertSet('editingKeyId', null);

    expect($key->fresh()->abilities)->toBe(['sales:read']);
});

it('rotates an existing API key and revokes the old one', function () {
    $user = User::factory()->create();
    $company = $user->currentCompany;

    ['key' => $original] = CompanyApiKey::mint($company, 'Storefront');

    $component = Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->call('rotate', $original->id);

    $component->assertSet('plaintext', fn (?string $v) => is_string($v) && str_starts_with($v, 'll_live_'));

    expect($original->fresh()->revoked_at)->not->toBeNull();
    expect(CompanyApiKey::query()->withoutGlobalScopes()->whereNull('revoked_at')->count())->toBe(1);
    expect(SecurityLog::query()->where('event', SecurityEvent::ApiKeyRotated->value)->exists())->toBeTrue();
});

it('revokes an API key and stops it from authenticating', function () {
    $user = User::factory()->create();
    $company = $user->currentCompany;
    ['key' => $key, 'plaintext' => $plain] = CompanyApiKey::mint($company, 'Storefront');

    Livewire::actingAs($user)
        ->test('pages::settings.api-keys')
        ->call('revoke', $key->id);

    expect($key->fresh()->revoked_at)->not->toBeNull();
    expect(SecurityLog::query()->where('event', SecurityEvent::ApiKeyRevoked->value)->exists())->toBeTrue();

    $this->postJson('/api/v1/customers', ['display_name' => 'X'], [
        'Authorization' => "Bearer {$plain}",
    ])->assertStatus(401);
});

it('hides management actions from non-admin members', function () {
    // Owner creates the company; a Member is invited.
    $owner = User::factory()->create();
    $company = $owner->currentCompany;

    $member = User::factory()->create();
    $company->members()->attach($member, ['role' => CompanyRole::Accountant->value]);
    $member->switchCompany($company);

    Livewire::actingAs($member)
        ->test('pages::settings.api-keys')
        ->assertSet('canManage', false)
        ->set('label', 'attempt')
        ->call('create')
        ->assertSet('plaintext', null);

    expect(CompanyApiKey::query()->withoutGlobalScopes()->count())->toBe(0);
});
