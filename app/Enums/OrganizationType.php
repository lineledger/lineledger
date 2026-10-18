<?php

namespace App\Enums;

/**
 * How a company is legally organized, captured during the setup wizard. Used to
 * relabel a few equity/income lines when seeding the chart of accounts (e.g.
 * non-profits use "Net Assets" rather than "Retained Earnings"). Stored for
 * reference; does not affect posting behaviour.
 *
 * Labels and help copy can vary by jurisdiction so owners see familiar terms
 * (e.g. UK "Sole trader" / "Limited company (Ltd)" / "Partnership / LLP") while
 * the backing enum values stay stable across countries.
 */
enum OrganizationType: string
{
    case SoleProprietorship = 'sole_proprietorship';
    case Partnership = 'partnership';
    case Corporation = 'corporation';
    case Club = 'club';
    case NonProfit = 'non_profit';
    case Charity = 'charity';
    case Other = 'other';

    public function label(?Country $country = null): string
    {
        if ($country === Country::UnitedKingdom) {
            return match ($this) {
                self::SoleProprietorship => 'Sole trader',
                self::Partnership => 'Partnership / LLP',
                self::Corporation => 'Limited company (Ltd)',
                self::Club => 'Club / Association',
                self::NonProfit => 'Non-profit',
                self::Charity => 'Charity',
                self::Other => 'Other / None',
            };
        }

        return match ($this) {
            self::SoleProprietorship => __('Sole proprietorship'),
            self::Partnership => __('Partnership'),
            self::Corporation => __('Corporation'),
            self::Club => __('Club / Association'),
            self::NonProfit => __('Non-profit'),
            self::Charity => __('Charity'),
            self::Other => __('Other / None'),
        };
    }

    public function description(?Country $country = null): string
    {
        if ($country === Country::UnitedKingdom) {
            return match ($this) {
                self::SoleProprietorship => 'An unincorporated business run by one person (a sole trader).',
                self::Partnership => 'A general partnership or a limited liability partnership (LLP) owned by two or more members.',
                self::Corporation => 'A limited company (Ltd or PLC) with one or more shareholders, registered at Companies House.',
                self::Club => 'An unincorporated club or association funded mainly by member dues.',
                self::NonProfit => 'A not-for-profit organization.',
                self::Charity => 'A registered charity.',
                self::Other => 'Anything else, or not sure yet.',
            };
        }

        return match ($this) {
            self::SoleProprietorship => __('An unincorporated business with one owner.'),
            self::Partnership => __('An unincorporated business owned by two or more partners.'),
            self::Corporation => __('A formal entity with one or more shareholders.'),
            self::Club => __('An unincorporated club or association funded mainly by member dues.'),
            self::NonProfit => __('A not-for-profit organization.'),
            self::Charity => __('A registered charity.'),
            self::Other => __('Anything else, or not sure yet.'),
        };
    }

    /**
     * Plain-language definition shown in the setup wizard's help tooltip, so an
     * owner unsure of the legal terms can pick the right structure with
     * confidence. Longer and more explanatory than description().
     */
    public function helpText(?Country $country = null): string
    {
        if ($country === Country::UnitedKingdom) {
            return match ($this) {
                self::SoleProprietorship => 'An unincorporated business owned and run by one person (a sole trader), with no legal separation between the owner and the business. The owner keeps all profits and is personally responsible for all debts and liabilities.',
                self::Partnership => 'Choose this for a traditional partnership or a limited liability partnership (LLP). A general partnership has no separate legal personality; an LLP is a body corporate with members, but both use partners’/members’ capital accounts rather than share capital.',
                self::Corporation => 'A limited company registered at Companies House — usually a private company limited by shares (Ltd), sometimes a public limited company (PLC). It is separate from its shareholders and generally limits their personal liability. Pick this for Ltd, not for an LLP.',
                self::Club => 'An unincorporated group of members organized around a shared purpose or activity, funded mainly through member dues rather than commercial sales or profit.',
                self::NonProfit => 'An organization that operates for a purpose other than generating profit for owners. Any surplus is reinvested into its mission rather than distributed to members.',
                self::Charity => 'A registered charity, able to issue Gift Aid receipts and subject to Charity Commission requirements.',
                self::Other => 'Anything else, or not sure yet — you can refine this later in settings.',
            };
        }

        return match ($this) {
            self::SoleProprietorship => __('An unincorporated business owned and run by one person, with no legal separation between the owner and the business. The owner keeps all profits and is personally responsible for all debts and liabilities.'),
            self::Partnership => __('An unincorporated business owned by two or more people who share ownership, profits, and liability. Each partner is typically personally responsible for the business\'s debts.'),
            self::Corporation => __('A formal legal entity that\'s separate from its owners (shareholders). It can have one or more shareholders, exists independently of them, and generally limits owners\' personal liability.'),
            self::Club => __('An unincorporated group of members organized around a shared purpose or activity, funded mainly through member dues rather than commercial sales or profit.'),
            self::NonProfit => __('An organization that operates for a purpose other than generating profit for owners. Any surplus is reinvested into its mission rather than distributed to members.'),
            self::Charity => __('A registered charity recognized by Canada Revenue Agency, able to issue official donation receipts and subject to specific regulatory requirements.'),
            self::Other => __('Anything else, or not sure yet — you can refine this later in settings.'),
        };
    }

    public function isNonProfit(): bool
    {
        return in_array($this, [self::Club, self::NonProfit, self::Charity], true);
    }

    /**
     * The heading for the equity section of the chart of accounts, named to match
     * the entity type the way a reader of its financial statements would expect:
     * non-profits report "Net Assets", partnerships "Partners' Equity",
     * corporations "Shareholders' Equity", and so on. The accounts keep the
     * AccountType::Equity classification — this only relabels the section header;
     * Asset/Liability/Income/Expense are untouched.
     */
    public function equitySectionLabel(): string
    {
        return match ($this) {
            self::SoleProprietorship => __('Owner\'s Equity'),
            self::Partnership => __('Partners\' Equity'),
            self::Corporation => __('Shareholders\' Equity'),
            self::Club, self::NonProfit, self::Charity => __('Net Assets'),
            self::Other => __('Equity'),
        };
    }

    /**
     * Which equity model the for-profit chart should use, naming the capital and
     * draw/distribution accounts to match the entity type. Returns null for the
     * non-profit tiers, which take the net-asset path instead. "Other" falls back
     * to the proprietor model so its equity section still reads sensibly.
     *
     * @return 'proprietor'|'partnership'|'corporation'|null
     */
    public function equityModel(): ?string
    {
        return match ($this) {
            self::SoleProprietorship, self::Other => 'proprietor',
            self::Partnership => 'partnership',
            self::Corporation => 'corporation',
            self::Club, self::NonProfit, self::Charity => null,
        };
    }

    /**
     * Whether this organization type is selectable for the given jurisdiction.
     * Charity is a Canada Revenue Agency concept (official donation receipts,
     * CRA registration), so it is hidden outside Canada.
     */
    public function availableIn(?Country $country): bool
    {
        return $this !== self::Charity
            || $country === null
            || $country === Country::Canada;
    }

    /**
     * A member-dues-funded club or association — the lightest non-profit tier
     * (unincorporated, deferral method, no charity receipting or fund accounting).
     */
    public function isClub(): bool
    {
        return $this === self::Club;
    }

    /**
     * Wizard radio options, optionally scoped to a jurisdiction so unavailable
     * types (e.g. Charity outside Canada) are filtered out and labels use
     * jurisdiction-appropriate wording.
     *
     * @return array<array{value: string, label: string, description: string, help: string}>
     */
    public static function options(?Country $country = null): array
    {
        return array_values(array_map(
            fn (self $t) => [
                'value' => $t->value,
                'label' => $t->label($country),
                'description' => $t->description($country),
                'help' => $t->helpText($country),
            ],
            array_filter(self::cases(), fn (self $t) => $t->availableIn($country)),
        ));
    }
}
