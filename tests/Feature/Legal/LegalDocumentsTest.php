<?php

use App\Models\User;
use App\Support\Legal\LegalDocuments;

beforeEach(function () {
    $this->legal = app(LegalDocuments::class);
});

test('only documents flagged as requiring acceptance are required', function () {
    expect($this->legal->requiring()->pluck('key')->all())
        ->toEqualCanonicalizing(['terms', 'privacy']);
});

test('an established user has nothing outstanding', function () {
    $user = User::factory()->create();

    expect($this->legal->outstanding($user))->toBeEmpty();
    expect($this->legal->hasOutstanding($user))->toBeFalse();
});

test('a missing acceptance row is outstanding', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->where('document_key', 'privacy')->delete();

    expect($this->legal->outstanding($user)->pluck('key')->all())->toBe(['privacy']);
});

test('a stale acceptance version is outstanding', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->where('document_key', 'terms')->update(['version' => '2000-01-01']);

    expect($this->legal->outstanding($user)->pluck('key')->all())->toBe(['terms']);
});

test('recording acceptance is idempotent per version', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->delete();

    $this->legal->record($user, ['terms']);
    $this->legal->record($user, ['terms']);

    expect($user->legalAcceptances()->where('document_key', 'terms')->count())->toBe(1);
});

test('reference documents are never required even without acceptance', function () {
    $user = User::factory()->create();
    $user->legalAcceptances()->delete();

    expect($this->legal->outstanding($user)->pluck('key')->all())
        ->not->toContain('dpa')
        ->not->toContain('security');
});

test('the marketing url joins the regional base with the document slug', function () {
    config(['app.region' => 'US']);

    expect($this->legal->url('terms'))->toBe('https://lineledger.com/terms');

    config(['app.region' => 'CA']);

    expect($this->legal->url('privacy'))->toBe('https://lineledger.ca/privacy');
});
