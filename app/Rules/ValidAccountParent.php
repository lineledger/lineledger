<?php

namespace App\Rules;

use App\Enums\AccountSubtype;
use App\Models\Account;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A chart-of-accounts parent must be the same account *type* as the child (an
 * Expense can't nest under a Bank), must not be the account itself, and must not
 * create a circular parent chain. The companion Rule::exists check handles
 * tenancy/existence; this rule layers on the structural constraints. Shared by the
 * Livewire Chart of Accounts form and the REST API so neither path can store an
 * invalid hierarchy.
 */
class ValidAccountParent implements ValidationRule
{
    /**
     * @param  string|null  $childSubtype  the submitted AccountSubtype value
     * @param  int|null  $childAccountId  the account being edited (null on create)
     */
    public function __construct(
        private int $companyId,
        private ?string $childSubtype,
        private ?int $childAccountId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $parentId = (int) $value;

        if ($this->childAccountId !== null && $parentId === $this->childAccountId) {
            $fail(__('An account cannot be its own parent.'));

            return;
        }

        $parent = Account::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->find($parentId);

        if ($parent === null) {
            return; // existence is enforced by the accompanying Rule::exists
        }

        $childType = AccountSubtype::tryFrom((string) $this->childSubtype)?->type();

        // Compare the raw stored type value (a string) against the child type's
        // value so the check is independent of how the attribute is cast.
        if ($childType !== null && $parent->getRawOriginal('type') !== $childType->value) {
            $fail(__('The parent account must be the same account type.'));

            return;
        }

        // Cycle guard: walk the chosen parent's ancestor chain — the account being
        // edited must not appear, or setting this parent would create a loop.
        if ($this->childAccountId !== null) {
            $seen = [];
            $ancestor = $parent;

            while ($ancestor !== null) {
                if ($ancestor->id === $this->childAccountId) {
                    $fail(__('That parent would create a circular relationship.'));

                    return;
                }

                if (in_array($ancestor->id, $seen, true)) {
                    return; // defensive: pre-existing loop, stop walking
                }

                $seen[] = $ancestor->id;
                $ancestor = $ancestor->parent_id !== null
                    ? Account::withoutGlobalScopes()->where('company_id', $this->companyId)->find($ancestor->parent_id)
                    : null;
            }
        }
    }
}
