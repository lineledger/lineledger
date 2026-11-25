<?php

use App\Enums\CompanyRole;
use App\Enums\Country;
use App\Models\Company;
use App\Models\User;
use App\Services\Restore\BundleInspector;
use App\Services\Restore\Exceptions\BundleValidationException;
use App\Services\Restore\UserRemapBuilder;
use App\Support\Companies\NewCompanyCountries;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// COMPANY_COUNTRIES limits the countries a new organization can be created in,
// for an operator who runs one site per country. Every path that lets a person
// pick a country is covered: the setup wizard, the organizations page, and a
// restore (whose bundle carries its own country).

/**
 * One site per country, the way an operator would configure it: this site is
 * Canada's, with the US and UK siblings listed.
 */
function companyCountriesSiblingSites(string $countries = 'CA'): void
{
    config([
        'companies.countries' => $countries,
        'app.url' => 'https://ca.example.test',
        'app.app_urls' => [
            'CA' => 'https://ca.example.test',
            'US' => 'https://us.example.test',
            'GB' => 'https://uk.example.test',
        ],
    ]);
}

/**
 * A minimal backup the inspector accepts, of an organization in `$country`
 * (or with no country at all when null).
 */
function companyCountriesBackupZip(?string $country): string
{
    $path = tempnam(sys_get_temp_dir(), 'company-countries-').'.zip';
    $row = ['id' => 7, 'name' => 'Elsewhere Co', 'slug' => 'elsewhere-co', 'currency_code' => 'USD'];

    if ($country !== null) {
        $row['address_country'] = $country;
    }

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('manifest.json', json_encode([
        'schema_version' => (int) config('version.schema'),
        'app_version' => (string) config('version.app'),
        'exported_at' => '2026-11-25T00:00:00Z',
        'company' => ['id' => 7, 'name' => 'Elsewhere Co', 'slug' => 'elsewhere-co'],
        'tables' => ['accounts' => ['rows' => 0, 'sha256' => str_repeat('a', 64)]],
        'files' => ['count' => 0, 'total_bytes' => 0, 'missing_count' => 0],
        'exclusions' => [],
    ], JSON_THROW_ON_ERROR));
    $zip->addFromString('users.json', '[]');
    $zip->addFromString('data/companies.jsonl', json_encode($row, JSON_THROW_ON_ERROR)."\n");
    $zip->addFromString('data/accounts.jsonl', '');
    $zip->close();

    return $path;
}

function companyCountriesBackupUpload(?string $country): File
{
    $path = companyCountriesBackupZip($country);

    try {
        return UploadedFile::fake()->createWithContent('backup.zip', (string) file_get_contents($path));
    } finally {
        @unlink($path);
    }
}

describe('the setting', function () {
    test('empty offers every supported country, as before', function () {
        config(['companies.countries' => '']);

        expect(NewCompanyCountries::all())->toBe(Country::cases())
            ->and(NewCompanyCountries::restricted())->toBeFalse()
            ->and(NewCompanyCountries::siblings())->toBe([]);
    });

    test('lists the named countries in the usual order, ignoring case and spaces', function () {
        config(['companies.countries' => ' gb , CA ']);

        expect(NewCompanyCountries::all())->toBe([Country::Canada, Country::UnitedKingdom])
            ->and(NewCompanyCountries::values())->toBe(['CA', 'GB'])
            ->and(NewCompanyCountries::restricted())->toBeTrue()
            ->and(NewCompanyCountries::allows(Country::UnitedStates))->toBeFalse();
    });

    test('an unsupported code is an error that names it', function () {
        config(['companies.countries' => 'CA,UK']);

        expect(fn () => NewCompanyCountries::all())
            ->toThrow(InvalidArgumentException::class, 'COMPANY_COUNTRIES lists UK, which is not a supported country. Use any of CA, US, GB.');
    });

    test('points each other country to its sibling site, never to this one', function () {
        companyCountriesSiblingSites();

        expect(NewCompanyCountries::elsewhere(Country::Canada))->toBeNull()
            ->and(NewCompanyCountries::elsewhere(Country::UnitedStates))->toBe('https://us.example.test')
            ->and(array_column(NewCompanyCountries::siblings(), 'host'))->toBe(['us.example.test', 'uk.example.test']);

        config(['app.app_urls.US' => '', 'app.app_urls.GB' => 'https://ca.example.test']);

        expect(NewCompanyCountries::elsewhere(Country::UnitedStates))->toBeNull()
            ->and(NewCompanyCountries::elsewhere(Country::UnitedKingdom))->toBeNull();
    });
});

describe('the setup wizard', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->user->companies()->detach();
        $this->user->forceFill(['current_company_id' => null])->save();
        $this->actingAs($this->user);
    });

    test('offers only this site’s country, picks it, and points a visitor from elsewhere to their site', function () {
        companyCountriesSiblingSites();

        Livewire::withHeaders(['CF-IPCountry' => 'US'])
            ->test('pages::welcome.setup-wizard')
            ->assertSet('country', 'CA')
            ->assertSet('currency', 'CAD')
            ->assertSet('visitorCountry', 'US')
            ->assertSeeHtml('>Canada</option>')
            ->assertDontSeeHtml('>United States</option>')
            ->assertDontSeeHtml('>United Kingdom</option>')
            ->assertSee('This site creates organizations in: 🇨🇦 Canada.')
            ->assertSeeHtml('href="https://us.example.test"')
            ->assertSeeHtml('href="https://uk.example.test"');
    });

    test('refuses a country this site doesn’t create organizations in', function () {
        companyCountriesSiblingSites();

        Livewire::test('pages::welcome.setup-wizard')
            ->set('companyName', 'Elsewhere Co')
            ->set('country', 'US')
            ->set('region', 'NY')
            ->set('currency', 'USD')
            ->call('createCompany')
            ->assertHasErrors(['country']);

        expect(Company::query()->where('name', 'Elsewhere Co')->exists())->toBeFalse();
    });

    test('still creates an organization in this site’s country', function () {
        companyCountriesSiblingSites();

        Livewire::test('pages::welcome.setup-wizard')
            ->set('companyName', 'Maple Co')
            ->set('region', 'ON')
            ->call('createCompany')
            ->assertHasNoErrors();

        expect(Company::query()->where('name', 'Maple Co')->value('address_country'))->toBe('CA');
    });

    test('a site that allows several countries prefills only one it allows', function () {
        companyCountriesSiblingSites('CA,GB');

        Livewire::withHeaders(['CF-IPCountry' => 'GB'])
            ->test('pages::welcome.setup-wizard')
            ->assertSet('country', 'GB')
            ->assertSet('visitorCountry', '');

        Livewire::withHeaders(['CF-IPCountry' => 'US'])
            ->test('pages::welcome.setup-wizard')
            ->assertSet('country', '')
            ->assertSet('visitorCountry', 'US');
    });

    test('says nothing about other sites when every country is allowed', function () {
        config(['companies.countries' => '']);

        Livewire::withHeaders(['CF-IPCountry' => 'US'])
            ->test('pages::welcome.setup-wizard')
            ->assertSet('country', 'US')
            ->assertSet('visitorCountry', '')
            ->assertSeeHtml('>United Kingdom</option>')
            ->assertDontSee('This site creates organizations in:');
    });
});

describe('the organizations page', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $company = Company::factory()->create();
        $company->members()->attach($this->user, ['role' => CompanyRole::Owner->value]);
        $this->actingAs($this->user);
    });

    test('starts on, offers and accepts only this site’s country', function () {
        companyCountriesSiblingSites('US');

        Livewire::test('pages::companies.index')
            ->assertSet('country', 'US')
            ->assertSet('currency', 'USD')
            ->assertSeeHtml('>United States</option>')
            ->assertDontSeeHtml('>Canada</option>')
            ->set('name', 'Maple Co')
            ->set('country', 'CA')
            ->call('createCompany')
            ->assertHasErrors(['country']);

        expect(Company::query()->where('name', 'Maple Co')->exists())->toBeFalse();
    });
});

describe('a restore', function () {
    test('refuses a backup of an organization in another country, naming its site', function () {
        companyCountriesSiblingSites();
        $path = companyCountriesBackupZip('US');

        try {
            expect(fn () => (new BundleInspector(new UserRemapBuilder))->inspect($path, User::factory()->create()))
                ->toThrow(
                    BundleValidationException::class,
                    'This site creates organizations in: 🇨🇦 Canada. The organization in this backup is in another country (United States), so restore it on that country’s site: https://us.example.test',
                );
        } finally {
            @unlink($path);
        }
    });

    test('refuses a backup with no country while countries are limited', function () {
        companyCountriesSiblingSites();
        $path = companyCountriesBackupZip(null);

        try {
            expect(fn () => (new BundleInspector(new UserRemapBuilder))->inspect($path, User::factory()->create()))
                ->toThrow(BundleValidationException::class, 'has no country this site recognizes');
        } finally {
            @unlink($path);
        }
    });

    test('accepts a backup from this site’s country, and any backup when countries are not limited', function () {
        $user = User::factory()->create();
        $inspector = new BundleInspector(new UserRemapBuilder);

        companyCountriesSiblingSites();
        $canadian = companyCountriesBackupZip('CA');

        config(['companies.countries' => '']);
        $american = companyCountriesBackupZip('US');

        try {
            companyCountriesSiblingSites();
            expect($inspector->inspect($canadian, $user)['company']['name'])->toBe('Elsewhere Co');

            config(['companies.countries' => '']);
            expect($inspector->inspect($american, $user)['company']['name'])->toBe('Elsewhere Co');
        } finally {
            @unlink($canadian);
            @unlink($american);
        }
    });

    test('the restore page shows why the backup was refused', function () {
        Storage::fake('local');
        companyCountriesSiblingSites();
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::companies.restore')
            ->set('upload', companyCountriesBackupUpload('GB'))
            ->call('inspect')
            ->assertSet('mode', 'upload')
            ->assertSet('uploadError', 'This site creates organizations in: 🇨🇦 Canada. The organization in this backup is in another country (United Kingdom), so restore it on that country’s site: https://uk.example.test')
            ->assertSee('This bundle cannot be restored');
    });
});

describe('the signed-out country banner', function () {
    beforeEach(function () {
        config(['app.app_urls' => [
            'CA' => 'https://books.example.ca',
            'US' => 'https://books.example.com',
            'GB' => 'https://books.example.co.uk',
        ]]);
    });

    test('lists first the sibling where the visitor seems to be, and says why', function () {
        $this->get('https://books.example.ca/login', ['CF-IPCountry' => 'GB'])
            ->assertOk()
            ->assertSee('Your location suggests the 🇬🇧 United Kingdom site', false)
            ->assertSee('suggested: \'gb\'', false)
            ->assertSeeInOrder(['books.example.co.uk', 'books.example.com'], false);
    });

    test('keeps its usual wording for a visitor in this site’s country or anywhere else', function () {
        foreach (['CA', 'AU'] as $country) {
            $this->get('https://books.example.ca/login', ['CF-IPCountry' => $country])
                ->assertOk()
                ->assertSee('Prefer a different country version?', false)
                ->assertDontSee('Your location suggests', false);
        }
    });
});
