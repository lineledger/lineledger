<?php

namespace App\Enums;

/**
 * Which ASNPO (CPA Handbook Part III §4410) method a non-profit uses to account
 * for restricted contributions. Chosen per company in the setup wizard / company
 * settings.
 *
 *  - Deferral: restricted contributions sit in a deferred-revenue liability and
 *    are recognized as revenue as the matching expense is incurred. Simplest;
 *    fits clubs through mid-size NPOs. The default.
 *  - RestrictedFund: true fund accounting — every transaction can be tagged with
 *    a fund, and each fund is its own self-balancing set of accounts.
 *
 * Switching method is presentation/gating only — it never rewrites posted GL.
 */
enum ContributionMethod: string
{
    case Deferral = 'deferral';
    case RestrictedFund = 'restricted_fund';

    public function label(): string
    {
        return match ($this) {
            self::Deferral => 'Deferral method',
            self::RestrictedFund => 'Restricted fund method',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Deferral => 'Restricted contributions are deferred and recognized as the related expense occurs. No per-transaction fund tag.',
            self::RestrictedFund => 'Fund accounting: tag transactions to a fund (General, Restricted, Endowment) with per-fund statements.',
        };
    }

    public static function default(): self
    {
        return self::Deferral;
    }

    /**
     * @return array<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $m) => ['value' => $m->value, 'label' => $m->label(), 'description' => $m->description()],
            self::cases(),
        );
    }
}
