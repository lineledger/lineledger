<?php

namespace App\Services\Migration;

use App\Enums\AccountSubtype;

/**
 * Describes one required control/system account role and how an account is
 * recognised as fulfilling it. Consumed by SystemAccountMapper.
 */
final class SystemAccountRole
{
    /**
     * @param  'is_system'|'name'|'is_system+name'  $matchBy  how the live account fulfilling this role is identified
     * @param  ?string  $requiredName  exact name the fulfilling account must carry (name-based roles); also the name commit() writes onto the chosen account
     * @param  ?string  $companyColumn  Company column to point at the chosen account (e.g. default_inventory_asset_account_id)
     * @param  ?list<string>  $acceptedNames  when a role's account may carry one of several names (e.g. opening-balance equity vs. net assets), every name that counts as fulfilling it; detection matches any. Defaults to [$requiredName].
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public AccountSubtype $subtype,
        public string $matchBy,
        public ?string $requiredName = null,
        public ?string $companyColumn = null,
        public ?array $acceptedNames = null,
    ) {}

    public function usesIsSystem(): bool
    {
        return $this->matchBy === 'is_system' || $this->matchBy === 'is_system+name';
    }

    public function usesName(): bool
    {
        return $this->matchBy === 'name' || $this->matchBy === 'is_system+name';
    }

    /**
     * Every name that counts as fulfilling this role. Falls back to the single
     * required name when no broader set is given.
     *
     * @return list<string>
     */
    public function matchNames(): array
    {
        return $this->acceptedNames ?? ($this->requiredName !== null ? [$this->requiredName] : []);
    }
}
