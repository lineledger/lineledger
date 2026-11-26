<?php

namespace App\Support\Http;

use App\Enums\Country;

/**
 * The project's own per-country sites (APP_URL_CA / _US / _GB, in
 * config('app.app_urls')), as seen from the current request.
 *
 * Both answers are empty on a host that isn't one of them, so the country UI
 * built on this (the signed-out country banner and the country label under the
 * sign-in logo) is self-configuring: a self-hosted installation on its own
 * domain never offers to send its users to someone else's app. APP_REGION has
 * no say here, or every self-host that set it to get the right legal documents
 * would get the country UI along with them.
 */
final class SiblingSites
{
    /**
     * The country whose site this request is on, or null when the host isn't
     * one of the project's sites.
     */
    public static function current(): ?Country
    {
        $host = mb_strtolower(request()->getHost());

        foreach (self::urls() as $code => $url) {
            if (mb_strtolower((string) parse_url($url, PHP_URL_HOST)) === $host) {
                return Country::tryFrom((string) $code);
            }
        }

        return null;
    }

    /**
     * The other countries' sites, in the configured order. Empty when this
     * host isn't one of the project's sites; a country whose URL is empty (a
     * site not live yet) is left out.
     *
     * @return list<array{country: Country, url: string}>
     */
    public static function others(): array
    {
        $current = self::current();

        if ($current === null) {
            return [];
        }

        $others = [];

        foreach (self::urls() as $code => $url) {
            $country = Country::tryFrom((string) $code);

            if ($country !== null && $country !== $current) {
                $others[] = ['country' => $country, 'url' => rtrim($url, '/')];
            }
        }

        return $others;
    }

    /**
     * The link to the same page on another country's site. `ll` records the
     * choice there, so its country banner doesn't ask again.
     *
     * @param  array{country: Country, url: string}  $site
     */
    public static function link(array $site): string
    {
        return $site['url'].request()->getPathInfo().'?ll='.mb_strtolower($site['country']->value);
    }

    /**
     * @return array<string, string>
     */
    private static function urls(): array
    {
        return array_filter(
            array_map('strval', (array) config('app.app_urls', [])),
            static fn (string $url): bool => $url !== '',
        );
    }
}
