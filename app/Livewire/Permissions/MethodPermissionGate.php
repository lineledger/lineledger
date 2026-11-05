<?php

namespace App\Livewire\Permissions;

use App\Enums\PermissionAction;
use App\Livewire\Attributes\PermissionExempt;
use App\Livewire\Attributes\Requires;
use App\Livewire\Attributes\RequiresSave;
use App\Models\Company;
use App\Models\User;
use App\Support\Access\Requirement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\Features\SupportEvents\SupportEvents;
use ReflectionMethod;

/**
 * Checks a Livewire method call against what the method declares it needs
 * ({@see Requires}, {@see RequiresSave}) before anything else runs — ahead of
 * the edit-lock guard, the trait hooks and the method itself.
 *
 * Page loads and every update already pass the route gate (an edit page needs
 * Modify, a create page Create). This adds what a route can't say: voiding
 * from a show page needs Delete, exporting needs Print, saving a dialog on a
 * list page needs Create or Modify. An undeclared method relies on the route
 * gate alone; the method-permission coverage test fails when one that writes
 * is left undeclared.
 *
 * Event listeners (`__dispatch`) are checked as the listener method they run.
 */
final class MethodPermissionGate
{
    /** @var array<string, list<Requires|RequiresSave>> class::method => declarations */
    private static array $declarations = [];

    /**
     * @param  array<int, mixed>  $params
     */
    public function authorize(Component $component, string $method, array $params): void
    {
        if ($method === '__dispatch') {
            $method = $this->listenerFor($component, $params[0] ?? null);
        }

        abort_unless($this->allows($component, (string) $method), 403);
    }

    /**
     * Whether the signed-in member may call the method — what authorize()
     * enforces, without throwing, so a page can leave out a button whose
     * action would be refused (@callable('voidInvoice')).
     */
    public function allows(Component $component, string $method): bool
    {
        if ($method === '' || str_starts_with($method, '$') || ! method_exists($component, $method)) {
            return true;
        }

        $declarations = $this->declarationsFor($component, $method);

        if ($declarations === []) {
            return true;
        }

        $user = Auth::user();
        $company = $this->company($component);

        if (! $user instanceof User || ! $company instanceof Company) {
            return false;
        }

        $permissions = $user->permissions($company);

        foreach ($declarations as $declaration) {
            $requirements = $this->requirements($component, $declaration);

            if ($requirements === [] || ! $permissions->allowsAny($requirements)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The requirement lists a method declares (each must be met), or none.
     *
     * @return list<Requires|RequiresSave>
     */
    public function declarationsFor(object $component, string $method): array
    {
        $key = $component::class.'::'.$method;

        if (! array_key_exists($key, self::$declarations)) {
            $reflection = new ReflectionMethod($component, $method);

            self::$declarations[$key] = $reflection->getAttributes(PermissionExempt::class) !== []
                ? []
                : array_map(
                    fn ($attribute) => $attribute->newInstance(),
                    [
                        ...$reflection->getAttributes(Requires::class),
                        ...$reflection->getAttributes(RequiresSave::class),
                    ],
                );
        }

        return self::$declarations[$key];
    }

    /**
     * Any one of these satisfies the declaration.
     *
     * @return list<Requirement>
     */
    private function requirements(Component $component, Requires|RequiresSave $declaration): array
    {
        $activities = $declaration->activities !== []
            ? $declaration->activities
            : ComponentActivities::for($component->getName());

        $actions = $declaration instanceof RequiresSave
            ? [$this->isEditing($component, $declaration->record) ? PermissionAction::Modify : PermissionAction::Create]
            : $declaration->actions;

        $requirements = [];

        foreach ($activities as $activity) {
            foreach ($actions as $action) {
                $requirements[] = new Requirement($activity, $action);
            }
        }

        return $requirements;
    }

    /**
     * Whether the form is editing an existing record: the named property
     * holds a saved model or a non-empty id.
     */
    private function isEditing(Component $component, string $property): bool
    {
        $value = data_get($component, $property);

        if ($value instanceof Model) {
            return $value->exists;
        }

        return $value !== null && $value !== '' && $value !== 0 && $value !== '0' && $value !== [];
    }

    private function listenerFor(Component $component, mixed $event): ?string
    {
        if (! is_string($event)) {
            return null;
        }

        try {
            return SupportEvents::getListenerMethodName($component, $event);
        } catch (\Throwable) {
            return null;
        }
    }

    private function company(Component $component): ?Company
    {
        $company = data_get($component, 'company');

        if ($company instanceof Company) {
            return $company;
        }

        return app()->bound('current_company') ? app('current_company') : null;
    }
}
