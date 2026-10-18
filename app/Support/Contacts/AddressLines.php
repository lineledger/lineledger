<?php

namespace App\Support\Contacts;

use App\Enums\Country;
use App\Models\Company;
use App\Models\Contact;

/**
 * Formats a postal address into the lines a document shows, one string per
 * printed line:
 *
 *   line1
 *   line2
 *   City, Region  PostalCode
 *   Country              ← only when it differs from the company's own
 *
 * A UK address leaves its region out: the stored region there is the nation
 * (ENG, SCT, …), which British addresses never print.
 *
 * Blank parts collapse, so a contact with only a street gets one line and a
 * contact with nothing gets none.
 *
 * The country line is the one judgement call. It is stored as a two-letter code,
 * and "CA" under a Canadian company's cheque is noise, so it prints only for a
 * payee outside the company's jurisdiction — and as the country's name, never
 * the code. Every other address block in the app drops the country entirely.
 */
final class AddressLines
{
    /**
     * @param  array<string, ?string>  $parts  keys: line1, line2, city, region, postal_code, country
     * @return list<string>
     */
    public static function format(array $parts, ?Company $company = null): array
    {
        $value = static fn (string $key): string => trim((string) ($parts[$key] ?? ''));

        $cityLine = collect([$value('city'), self::region($value('region'), $value('country'), $company)])
            ->filter()
            ->implode(', ');

        // Two spaces before the postal code is the usual Canada Post / USPS
        // convention on the locality line.
        $cityLine = trim($cityLine.'  '.$value('postal_code'));

        return collect([
            $value('line1'),
            $value('line2'),
            $cityLine,
            self::countryLine($value('country'), $company),
        ])->filter()->values()->all();
    }

    /**
     * The same address on a single line, for a compact summary where a block
     * would take too much room: every part comma-separated, blanks dropped —
     * "12 Old Street, Unit 4, Winnipeg, MB, R3C 1A1". The country follows the
     * same rule as format(). An empty string when there is nothing to show.
     *
     * @param  array<string, ?string>  $parts  keys: line1, line2, city, region, postal_code, country
     */
    public static function oneLine(array $parts, ?Company $company = null): string
    {
        $value = static fn (string $key): string => trim((string) ($parts[$key] ?? ''));

        return collect([
            $value('line1'),
            $value('line2'),
            $value('city'),
            self::region($value('region'), $value('country'), $company),
            $value('postal_code'),
            self::countryLine($value('country'), $company),
        ])->reject(static fn (string $part): bool => $part === '')->implode(', ');
    }

    /**
     * The locality line a document prints under the street — "Toronto, ON, M5V 2T6",
     * or "Reading, RG1 2EQ" for a UK address — with blank parts dropped.
     */
    public static function locality(?string $city, ?string $region, ?string $postalCode, ?string $country, ?Company $company, string $glue = ', '): string
    {
        return collect([
            trim((string) $city),
            self::region(trim((string) $region), trim((string) $country), $company),
            trim((string) $postalCode),
        ])->reject(static fn (string $part): bool => $part === '')->implode($glue);
    }

    /**
     * The region as it prints in an address: blank for a UK address, whose
     * stored region is its nation. An address with no country of its own takes
     * the company's.
     */
    public static function region(?string $region, ?string $country, ?Company $company): string
    {
        $region = trim((string) $region);
        $code = mb_strtoupper(trim((string) $country));

        if ($code === '' && $company !== null) {
            $code = $company->jurisdiction->value;
        }

        return $code === Country::UnitedKingdom->value ? '' : $region;
    }

    /**
     * The same, read off a contact's billing address.
     *
     * @return list<string>
     */
    public static function forContact(?Contact $contact, ?Company $company = null): array
    {
        if ($contact === null) {
            return [];
        }

        return self::format([
            'line1' => $contact->billing_line1,
            'line2' => $contact->billing_line2,
            'city' => $contact->billing_city,
            'region' => $contact->billing_region,
            'postal_code' => $contact->billing_postal_code,
            'country' => $contact->billing_country,
        ], $company ?? $contact->company);
    }

    /**
     * Whether every part is blank — the caller's cue to fall back to another
     * source (a cheque with no stored address reads its payee's instead).
     *
     * @param  array<string, ?string>  $parts
     */
    public static function isEmpty(array $parts): bool
    {
        foreach ($parts as $part) {
            if (trim((string) $part) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function countryLine(string $code, ?Company $company): string
    {
        if ($code === '') {
            return '';
        }

        $code = mb_strtoupper($code);

        // Domestic: the country is implied by the cheque itself.
        if ($company !== null && $code === $company->jurisdiction->value) {
            return '';
        }

        return Country::tryFrom($code)?->label() ?? $code;
    }
}
