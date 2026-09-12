<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Contact;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Facades\App;

/**
 * Application locales. UI language is per-user (or a guest cookie); customer
 * documents use the contact's locale, then the organization's, then English.
 *
 * Stored codes are short (`en`, `fr`). ICU / NumberFormatter / Carbon get
 * the regional tag (`en_CA`, `fr_CA`) via {@see icu()}.
 */
final class Locales
{
    public const COOKIE = 'll_locale';

    public const QUERY = 'lang';

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        /** @var list<string> $codes */
        $codes = config('app.supported_locales', ['en', 'fr']);

        return $codes;
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && $locale !== '' && in_array($locale, self::codes(), true);
    }

    public static function canonicalize(?string $locale): string
    {
        return self::isSupported($locale) ? $locale : (string) config('app.fallback_locale', 'en');
    }

    /**
     * Native-script label for pickers (not wrapped in __() — the name of a
     * language should stay in that language).
     */
    public static function label(string $locale): string
    {
        return match ($locale) {
            'en' => 'English',
            'fr' => 'Français',
            default => $locale,
        };
    }

    /**
     * @return array<string, string> code => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::codes() as $code) {
            $options[$code] = self::label($code);
        }

        return $options;
    }

    public static function icu(string $locale): string
    {
        return match (self::canonicalize($locale)) {
            'fr' => 'fr_CA',
            default => 'en_CA',
        };
    }

    /**
     * Contact override → organization default → fallback.
     */
    public static function forDocument(?Contact $contact, ?Company $company): string
    {
        foreach ([$contact?->locale, $company?->locale] as $locale) {
            if (self::isSupported($locale)) {
                return $locale;
            }
        }

        if ($company?->address_region === 'QC') {
            return 'fr';
        }

        return self::canonicalize(App::getLocale());
    }

    public static function apply(string $locale): void
    {
        $locale = self::canonicalize($locale);

        App::setLocale($locale);
        Carbon::setLocale(self::icu($locale));
        CarbonImmutable::setLocale(self::icu($locale));
    }

    /**
     * Render a customer/vendor document in that contact's document language.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function forContactDocument(?Contact $contact, ?Company $company, Closure $callback): mixed
    {
        return self::using(self::forDocument($contact, $company), $callback);
    }

    /**
     * Render staff mail in the recipient's UI language when they have one.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function forRecipient(mixed $notifiable, Closure $callback, ?Company $company = null): mixed
    {
        $locale = null;
        if (is_object($notifiable) && isset($notifiable->locale) && self::isSupported((string) $notifiable->locale)) {
            $locale = (string) $notifiable->locale;
        } elseif ($notifiable instanceof Contact) {
            $locale = self::forDocument($notifiable, $company);
        } elseif ($company !== null) {
            $locale = self::forDocument(null, $company);
        }

        return self::using(self::canonicalize($locale), $callback);
    }

    /**
     * Run $callback with $locale active, then restore the previous locale.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function using(string $locale, Closure $callback): mixed
    {
        $previous = App::getLocale();

        self::apply($locale);

        try {
            return $callback();
        } finally {
            self::apply($previous);
        }
    }

    public static function formatDate(?CarbonInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        $immutable = CarbonImmutable::parse($date);
        $code = App::getLocale();

        if ($code === 'en') {
            return $immutable->format('n/j/Y');
        }

        return $immutable->locale(self::icu($code))->isoFormat('D MMM YYYY');
    }

    public static function formatMoney(int $cents, string $currency): string
    {
        return Money::fromCents($cents, $currency)->format();
    }
}
