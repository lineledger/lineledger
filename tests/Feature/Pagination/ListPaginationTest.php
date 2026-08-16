<?php

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

/**
 * A Livewire list that paginates without the WithPagination trait renders its
 * pagination as plain <a href> links built from the *current request URL*. After
 * any AJAX update that URL is the POST-only /livewire-<hash>/update endpoint, so
 * the freshly-rendered "page 2" link points there — clicking it GETs a POST-only
 * route and returns 405. WithPagination renders wire:click buttons instead, so
 * there is no such href. These tests guard the whole class of bug.
 */
test('every paginating Livewire component uses the WithPagination trait', function () {
    $offenders = [];

    foreach (File::allFiles(resource_path('views/pages')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $src = $file->getContents();

        $paginates = preg_match('/->(?:simplePaginate|cursorPaginate|paginate)\(/', $src) === 1;

        if ($paginates && ! str_contains($src, 'use WithPagination')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], sprintf(
        'These components paginate but lack the WithPagination trait, so their pagination '.
        'links 405 after a Livewire update: %s',
        implode(', ', $offenders)
    ));
});

test('the items list paginates through Livewire rather than a full-page link', function () {
    $user = User::factory()->create();
    $company = Company::factory()->create();
    $company->members()->attach($user, ['role' => CompanyRole::Owner->value]);
    $this->actingAs($user);
    app()->instance('current_company', $company);

    // Page size is 25; 30 zero-padded names sort so page 2 holds 26–30.
    foreach (range(1, 30) as $n) {
        Item::factory()->create(['name' => sprintf('Pageitem %02d', $n)]);
    }

    Livewire::test('pages::settings.lists.items', ['company' => $company])
        ->assertSee('Pageitem 01')
        ->assertDontSee('Pageitem 30')
        // nextPage() exists only because WithPagination is applied; advancing it
        // re-renders the component to the second page in-place (no navigation).
        ->call('nextPage')
        ->assertSee('Pageitem 30')
        ->assertDontSee('Pageitem 01');

    app()->forgetInstance('current_company');
});
