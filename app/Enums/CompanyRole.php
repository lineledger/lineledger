<?php

namespace App\Enums;

enum CompanyRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Accountant = 'accountant';
    case Custom = 'custom';

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => __('Owner'),
            self::Admin => __('Admin'),
            self::Accountant => __('Accountant'),
            self::Custom => __('Custom'),
        };
    }

    /**
     * A short description of what the role can do, for the member-management UI.
     */
    public function description(): string
    {
        return match ($this) {
            self::Owner => __('Full access, including company settings and ownership.'),
            self::Admin => __('Full access to every section, including settings.'),
            self::Accountant => __('Access to every section except settings.'),
            self::Custom => __('Access only to the sections you select.'),
        };
    }

    /**
     * Get all the management permissions for this role.
     *
     * These gate company and member management only — section access is
     * handled separately via {@see self::sections()}.
     *
     * @return array<CompanyPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => CompanyPermission::cases(),
            self::Admin => [
                CompanyPermission::UpdateCompany,
                CompanyPermission::CreateInvitation,
                CompanyPermission::CancelInvitation,
            ],
            self::Accountant, self::Custom => [],
        };
    }

    /**
     * The sections this role grants access to.
     *
     * Owner and Admin reach every section; Accountant reaches everything except
     * Settings. Custom has no fixed set — its effective sections come from the
     * membership's stored selection (see Membership::effectiveSections()).
     *
     * @return array<int, Section>
     */
    public function sections(): array
    {
        return match ($this) {
            self::Owner, self::Admin => Section::cases(),
            self::Accountant => self::sectionsExcept(Section::Settings),
            self::Custom => [],
        };
    }

    /**
     * Every section except the given one, as a re-indexed list. A foreach build
     * (rather than array_values(array_filter(...))) keeps it a genuine list for
     * static analysis without the "array_values has no effect" false positive.
     *
     * @return list<Section>
     */
    private static function sectionsExcept(Section $excluded): array
    {
        $sections = [];

        foreach (Section::cases() as $section) {
            if ($section !== $excluded) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * Whether this role's section access is defined per-member rather than fixed.
     */
    public function usesCustomSections(): bool
    {
        return $this === self::Custom;
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(CompanyPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 4,
            self::Admin => 3,
            self::Accountant => 2,
            self::Custom => 1,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(CompanyRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to company members (excludes Owner).
     *
     * @return array<array{value: string, label: string, description: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->filter(fn (self $role) => $role !== self::Owner)
            ->map(fn (self $role) => [
                'value' => $role->value,
                'label' => $role->label(),
                'description' => $role->description(),
            ])
            ->values()
            ->toArray();
    }
}
