<?php

use App\Enums\PermissionAction;
use App\Livewire\Attributes\PermissionExempt;
use App\Livewire\Attributes\Requires;
use App\Livewire\Attributes\RequiresSave;
use App\Livewire\Permissions\ComponentActivities;
use App\Support\Access\RoutePermissionMap;
use Livewire\Attributes\Computed;

/**
 * Guard rail for the Livewire method gate. A company page's route says what
 * loading it needs (a create page needs Create, an edit page Modify), and
 * every update replays that check. What a route can't say is what a method
 * on a view-level page (a show page, a list, a report) does: void, delete,
 * export, save a dialog. So:
 *
 *  - every public method on a view-level page that writes — saves, deletes,
 *    posts, sends, downloads, or calls an Action or poster, directly or
 *    through its own helpers — must declare #[Requires], #[RequiresSave] or
 *    #[PermissionExempt(reason)];
 *  - on create and edit pages, so must the methods that reach into another
 *    activity: exporting, emailing, saving as a template, quick-adding a
 *    contact.
 *
 * Writes are found lexically, like LivewireTenantKeyGuardTest finds queries.
 */
$writes = '/(->save\(|->update\(|->delete\(|::create\(|->create\(|->handle\(|->post\(|->void\(|->repost\(|forceDelete\(|->restore\(|updateOrCreate\(|firstOrCreate\(|->increment\(|->decrement\(|->attach\(|->detach\(|->sync\(|syncWithoutDetaching\(|Mail::|Notification::|->notify\(|dispatch\(|streamDownload\(|->download\(|Storage::|->store\(|->insert\(|->forceFill\(|->reverse\(|->merge\(|->send\(|->generate\(|->generateOne\(|->remove\(|->issue\(|->reissue\(|->recognize\(|->postAward\(|->stream\(|XlsxExporter|CsvExporter|PdfExporter)/';
$crossActivity = '/^(export|download|print|email|send|saveAsTemplate|createOtherName|createLine|quickAdd|create[A-Z].*Contact)/';
$lifecycle = '/^(mount|boot|booted|hydrate|dehydrate|render|rendering|rendered|exception|updating|updated|placeholder)/';

/**
 * @return array<string, array{component: string, action: PermissionAction, routes: list<string>}>
 */
$pages = function (): array {
    $pages = [];

    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        $component = $route->action['livewire_component'] ?? null;

        if (! str_starts_with($route->uri(), '{company}/') || ! is_string($component)) {
            continue;
        }

        $action = RoutePermissionMap::actionFor((string) $route->getName());
        $pages[$component] ??= ['component' => $component, 'action' => $action, 'routes' => []];
        $pages[$component]['routes'][] = (string) $route->getName();

        // A component behind any view-level route is held to the stricter rule.
        if ($action === PermissionAction::View) {
            $pages[$component]['action'] = PermissionAction::View;
        }
    }

    return $pages;
};

it('declares what every writing method on a company page needs', function () use ($pages, $writes, $crossActivity, $lifecycle) {
    $undeclared = [];

    foreach ($pages() as $page) {
        $class = app('livewire.factory')->resolveComponentClass($page['component']);
        $reflection = new ReflectionClass($class);

        $bodies = [];

        foreach ($reflection->getMethods() as $method) {
            $file = $method->getFileName();

            if ($file !== false) {
                $lines = file($file);
                $bodies[$method->getName()] = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
            }
        }

        $isWriting = function (string $name, array $seen = []) use (&$isWriting, $bodies, $reflection, $writes): bool {
            if (isset($seen[$name]) || ! isset($bodies[$name])) {
                return false;
            }

            $seen[$name] = true;

            if (preg_match($writes, $bodies[$name])) {
                return true;
            }

            foreach ($reflection->getMethod($name)->getParameters() as $parameter) {
                if (preg_match('/^App\\\\(Actions|Services\\\\Posting)\\\\/', ltrim((string) $parameter->getType(), '?'))) {
                    return true;
                }
            }

            preg_match_all('/\$this->([a-zA-Z_]+)\(/', $bodies[$name], $calls);

            foreach ($calls[1] as $callee) {
                if ($isWriting($callee, $seen)) {
                    return true;
                }
            }

            return false;
        };

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            $declaring = $method->getDeclaringClass();

            if ($method->isStatic() || str_starts_with($name, '_') || preg_match($lifecycle, $name)
                || (! $declaring->isAnonymous() && str_starts_with($declaring->getName(), 'Livewire\\'))
                || $method->getAttributes(Computed::class) !== []) {
                continue;
            }

            if ($page['action'] !== PermissionAction::View && ! preg_match($crossActivity, $name)) {
                continue;
            }

            if (! $isWriting($name)) {
                continue;
            }

            $declared = $method->getAttributes(Requires::class) !== []
                || $method->getAttributes(RequiresSave::class) !== []
                || $method->getAttributes(PermissionExempt::class) !== [];

            if (! $declared) {
                $undeclared[] = $page['component'].'::'.$name.' ('.basename((string) $method->getFileName()).')';
            }
        }
    }

    sort($undeclared);

    expect(array_values(array_unique($undeclared)))->toBe([]);
});

it('only leaves the activity to the page where the page has one', function () use ($pages) {
    $orphans = [];

    foreach ($pages() as $page) {
        $class = app('livewire.factory')->resolveComponentClass($page['component']);

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ([...$method->getAttributes(Requires::class), ...$method->getAttributes(RequiresSave::class)] as $attribute) {
                if ($attribute->newInstance()->activities === [] && ComponentActivities::for($page['component']) === []) {
                    $orphans[] = $page['component'].'::'.$method->getName();
                }
            }
        }
    }

    expect($orphans)->toBe([]);
});

it('only declares actions an activity offers', function () use ($pages) {
    $impossible = [];

    foreach ($pages() as $page) {
        $class = app('livewire.factory')->resolveComponentClass($page['component']);

        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes(Requires::class) as $attribute) {
                $requires = $attribute->newInstance();
                $activities = $requires->activities !== [] ? $requires->activities : ComponentActivities::for($page['component']);

                foreach ($activities as $activity) {
                    if (array_filter($requires->actions, fn (PermissionAction $action) => $activity->supports($action)) === []) {
                        $impossible[] = $page['component'].'::'.$method->getName().' → '.$activity->value;
                    }
                }
            }
        }
    }

    expect($impossible)->toBe([]);
});
