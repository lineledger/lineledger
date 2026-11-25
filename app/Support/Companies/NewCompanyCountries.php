<?php

namespace App\Support\Companies;

use App\Enums\Country;
use App\Services\Restore\BundleInspector;
use InvalidArgumentException;

/**
 * The countries a new organization may be created in on this installation:
 * `companies.countries` (COMPANY_COUNTRIES), or every supported country when
 * that is empty.
 *
 * Decided once, here, like a picker's eligibility: the setup wizard and the
 * organizations page offer only these countries and validate against the same
 * list, and a restore refuses a backup of an organization in any other
 * ({@see BundleInspector}). Organizations already on the
 * installation are never affected, and CreateCompany itself isn't limited, so
 * internal callers such as the proof scenarios keep working on any site.
 *
 * An operator running one site per country lists each in APP_URL_CA / _US /
 * _GB; {@see self::elsewhere()} then names the sibling site that hosts a
 * country this one doesn't.
 */
final class NewCompanyCountries
{
    /**
     * In the enum's order, so the pickers list them the usual way.
     *
     * @return list<Country>
     *
     * @throws InvalidArgumentException When COMPANY_COUNTRIES names an unsupported country.
     */
    public static function all(): array
    {
        $codes = array_values(array_filter(array_map(
            static fn (string $code): string => mb_strtoupper(trim($code)),
            explode(',', (string) config('companies.countries', '')),
        )));

        if ($codes === []) {
            return Country::cases();
        }

        foreach ($codes as $code) {
            if (Country::tryFrom($code) === null) {
                throw new InvalidArgumentException(sprintf(
                    'COMPANY_COUNTRIES lists %s, which is not a supported country. Use any of %s.',
                    $code,
                    implode(', ', self::codes(Country::cases())),
                ));
            }
        }

        return array_values(array_filter(
            Country::cases(),
            static fn (Country $country): bool => in_array($country->value, $codes, true),
        ));
    }

    public static function allows(Country $country): bool
    {
        return in_array($country, self::all(), true);
    }

    /**
     * Whether this installation leaves out any supported country.
     */
    public static function restricted(): bool
    {
        return count(self::all()) < count(Country::cases());
    }

    /**
     * The country codes, for a `Rule::in()`.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return self::codes(self::all());
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (Country $country): array => ['value' => $country->value, 'label' => $country->label()],
            self::all(),
        );
    }

    /**
     * The sibling site that hosts organizations in `$country`, from
     * APP_URL_CA / _US / _GB. Null when this installation accepts the country
     * itself, no sibling is set for it, or the URL is this installation's own.
     */
    public static function elsewhere(Country $country): ?string
    {
        if (self::allows($country)) {
            return null;
        }

        $url = trim((string) config('app.app_urls.'.$country->value, ''));
        $host = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $ownHost = mb_strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        return $host === '' || $host === $ownHost ? null : $url;
    }

    /**
     * Every country this installation leaves out that a sibling site hosts.
     *
     * @return list<array{country: Country, url: string, host: string}>
     */
    public static function siblings(): array
    {
        $siblings = [];

        foreach (Country::cases() as $country) {
            $url = self::elsewhere($country);

            if ($url !== null) {
                $siblings[] = ['country' => $country, 'url' => $url, 'host' => (string) parse_url($url, PHP_URL_HOST)];
            }
        }

        return $siblings;
    }

    /**
     * The allowed countries as a list for a sentence: "🇨🇦 Canada".
     */
    public static function describe(): string
    {
        return implode(', ', array_map(
            static fn (Country $country): string => $country->flag().' '.$country->label(),
            self::all(),
        ));
    }

    /**
     * Why a backup of an organization in `$country` can't be restored here,
     * and where it can be. The pickers never offer such a country, so only a
     * restore needs to say so.
     */
    public static function restoreRefusal(Country $country): string
    {
        $url = self::elsewhere($country);
        $replace = ['countries' => self::describe(), 'country' => $country->label()];

        return $url === null
            ? __('This site creates organizations in: :countries. The organization in this backup is in another country (:country), so it can’t be restored here.', $replace)
            : __('This site creates organizations in: :countries. The organization in this backup is in another country (:country), so restore it on that country’s site: :url', [...$replace, 'url' => $url]);
    }

    /**
     * @param  list<Country>  $countries
     * @return list<string>
     */
    private static function codes(array $countries): array
    {
        return array_map(static fn (Country $country): string => $country->value, $countries);
    }
}
