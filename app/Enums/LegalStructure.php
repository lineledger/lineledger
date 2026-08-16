<?php

namespace App\Enums;

/**
 * A non-profit's legal tier, captured during the setup wizard. Orthogonal to
 * {@see OrganizationType} (which stays the user-facing "what are you"): the tier
 * refines which compliance surfaces — an unincorporated association files little,
 * a non-profit corporation files a T2/GIFI, and only a registered charity issues
 * official donation receipts and a T3010. Stored for reference; does not affect
 * posting behaviour.
 */
enum LegalStructure: string
{
    case UnincorporatedAssociation = 'unincorporated_association';
    case NonProfitCorporation = 'non_profit_corporation';
    case RegisteredCharity = 'registered_charity';

    public function label(): string
    {
        return match ($this) {
            self::UnincorporatedAssociation => 'Unincorporated association',
            self::NonProfitCorporation => 'Non-profit corporation',
            self::RegisteredCharity => 'Registered charity',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::UnincorporatedAssociation => 'A club or group with no separate legal incorporation (e.g. a photography club).',
            self::NonProfitCorporation => 'An incorporated not-for-profit; files a corporate return even though tax-exempt.',
            self::RegisteredCharity => 'A CRA-registered charity that can issue official donation receipts and files the T3010.',
        };
    }

    /**
     * Only a registered charity needs a CRA registration (BN/RR) number.
     */
    public function requiresCharityRegistration(): bool
    {
        return $this === self::RegisteredCharity;
    }

    /**
     * Whether the T3010 Registered Charity Information Return applies.
     */
    public function surfacesT3010(): bool
    {
        return $this === self::RegisteredCharity;
    }

    /**
     * A sensible default tier for a freshly chosen organization type, used to
     * pre-select the wizard radio. Returns null when the org type isn't a
     * non-profit (so no tier should be assumed).
     */
    public static function fromOrganizationType(OrganizationType $type): ?self
    {
        return match ($type) {
            OrganizationType::Charity => self::RegisteredCharity,
            OrganizationType::NonProfit => self::NonProfitCorporation,
            OrganizationType::Club => self::UnincorporatedAssociation,
            default => null,
        };
    }

    /**
     * The organization type this tier corresponds to. The wizard collapses the
     * three non-profit org types into one choice, then uses this tier to recover
     * the precise type that drives the chart of accounts (a club gets the lighter
     * member-dues chart; a charity gets an endowment account).
     */
    public function toOrganizationType(): OrganizationType
    {
        return match ($this) {
            self::UnincorporatedAssociation => OrganizationType::Club,
            self::NonProfitCorporation => OrganizationType::NonProfit,
            self::RegisteredCharity => OrganizationType::Charity,
        };
    }

    /**
     * Whether this tier is selectable for the given jurisdiction. A registered
     * charity is a Canada Revenue Agency concept (official donation receipts, the
     * T3010), so it is hidden for the United States.
     */
    public function availableIn(?Country $country): bool
    {
        return ! ($this === self::RegisteredCharity && $country === Country::UnitedStates);
    }

    /**
     * Wizard radio options, optionally scoped to a jurisdiction so unavailable
     * tiers (e.g. registered charity in the US) are filtered out.
     *
     * @return array<array{value: string, label: string, description: string}>
     */
    public static function options(?Country $country = null): array
    {
        return array_values(array_map(
            fn (self $s) => ['value' => $s->value, 'label' => $s->label(), 'description' => $s->description()],
            array_filter(self::cases(), fn (self $s) => $s->availableIn($country)),
        ));
    }
}
