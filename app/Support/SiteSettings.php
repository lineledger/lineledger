<?php

namespace App\Support;

use App\Enums\Section;
use App\Models\SiteSetting;
use App\Support\Navigation\SidebarNavCatalog;
use Illuminate\Support\Facades\Cache;

/**
 * Typed, cached accessor for the platform-wide settings the site admin controls
 * (registrations, per-section kill switches, maintenance mode). A single source
 * of truth so the nav ({@see SidebarNavCatalog}), the
 * route middleware, and the admin settings page never drift.
 *
 * Reads are served from a forever cache built once per request set; every
 * {@see self::set()} invalidates it. Keys are plain strings, so adding a new
 * toggle never needs a migration.
 */
class SiteSettings
{
    private const CACHE_KEY = 'site_settings';

    /**
     * Whether new users may register. Default open.
     */
    public static function registrationsEnabled(): bool
    {
        return (bool) self::get('registrations_enabled', true);
    }

    /**
     * Whether the app is in operator-controlled maintenance mode. Default off.
     */
    public static function maintenanceMode(): bool
    {
        return (bool) self::get('maintenance_mode', false);
    }

    /**
     * Whether a main section is available platform-wide. Settings can never be
     * disabled (the admin would lock themselves out), and unknown/never-set
     * sections default to enabled.
     */
    public static function sectionEnabled(Section $section): bool
    {
        if ($section === Section::Settings) {
            return true;
        }

        return ! in_array($section->value, self::disabledSections(), true);
    }

    /**
     * The section values currently switched off platform-wide.
     *
     * @return list<string>
     */
    public static function disabledSections(): array
    {
        $disabled = self::get('disabled_sections', []);

        return is_array($disabled) ? array_values(array_filter($disabled, 'is_string')) : [];
    }

    /**
     * Read a raw setting, falling back to the given default when unset.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    /**
     * Persist a setting and invalidate the cache.
     */
    public static function set(string $key, mixed $value): void
    {
        SiteSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The full key => value map, cached for the lifetime of the cache entry.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => SiteSetting::query()->pluck('value', 'key')->all(),
        );
    }
}
