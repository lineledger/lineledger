<?php

namespace App\Livewire\Permissions;

use App\Enums\Activity;
use App\Support\Access\RoutePermissionMap;
use Illuminate\Routing\Router;

/**
 * The activities a full-page Livewire component belongs to, read from the
 * routes that render it (create and edit share a form component, so one
 * component can sit behind several routes of the same activity). A method
 * declared with a null activity takes these.
 */
final class ComponentActivities
{
    /** @var array<string, list<Activity>>|null component name => activities */
    private static ?array $index = null;

    /**
     * @return list<Activity>
     */
    public static function for(string $componentName): array
    {
        self::$index ??= self::build();

        return self::$index[$componentName] ?? [];
    }

    /**
     * Forget the index (routes changed, e.g. in a test).
     */
    public static function flush(): void
    {
        self::$index = null;
    }

    /**
     * @return array<string, list<Activity>>
     */
    private static function build(): array
    {
        $index = [];

        foreach (app(Router::class)->getRoutes()->getRoutes() as $route) {
            $component = $route->action['livewire_component'] ?? null;

            if (! is_string($component)) {
                continue;
            }

            foreach (RoutePermissionMap::activitiesFor($route->getName()) as $activity) {
                if (! in_array($activity, $index[$component] ?? [], true)) {
                    $index[$component][] = $activity;
                }
            }
        }

        return $index;
    }
}
