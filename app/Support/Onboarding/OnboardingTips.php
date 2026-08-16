<?php

namespace App\Support\Onboarding;

use App\Models\Company;

/**
 * The catalog of "getting started" tips shown to new companies on the dashboard
 * (see resources/views/components/⚡onboarding-tips.blade.php). A single source
 * of truth so the carousel and the per-company completion state never drift.
 *
 * Each tip carries a stable string `key`. Completion state is persisted against
 * these keys in `companies.settings['onboarding']['completed']`, so the keys
 * must never change once shipped. Adding a new tip (a new key) naturally
 * re-opens the box for companies that had completed every earlier tip, because
 * the new key won't be in their completed list.
 *
 * The optional `cta` points at a named route. Routes are resolved in the blade
 * (so the URL stays correct) — keep `route` a plain route name here.
 *
 * @phpstan-type OnboardingTip array{key: string, icon: string, title: string, body: string, cta?: array{route: string, label: string}}
 */
class OnboardingTips
{
    /**
     * The ordered list of tips, shown one at a time.
     *
     * @return list<OnboardingTip>
     */
    public static function all(?Company $company = null): array
    {
        $tips = [
            [
                'key' => 'customize-sidebar',
                'icon' => 'bars-3',
                'title' => __('Customize your sidebar'),
                'body' => __("Did you know you can customize the sidebar and hide features you're not using? Tidy the navigation to match how you work."),
                'cta' => [
                    'route' => 'navigation.edit',
                    'label' => __('Open navigation settings'),
                ],
            ],
            [
                'key' => 'bank-register',
                'icon' => 'bars-3',
                'title' => __('Bank register'),
                'body' => __('The bank register is where you can view and manage your bank transactions. You can import transactions from your bank, categorize them, and reconcile them with your bank statement.'),
                'cta' => [
                    'route' => 'banking.register',
                    'label' => __('Open bank register'),
                ],
            ],
        ];

        if ($company?->tracksMembership()) {
            $tips[] = [
                'key' => 'members',
                'icon' => 'bars-3',
                'title' => __('Members'),
                'body' => __("Manage your organization's membership - add members, choose their membership level and create invoices for them."),
                'cta' => [
                    'route' => 'members.index',
                    'label' => __('Open members'),
                ],
            ];

            $tips[] = [
                'key' => 'membership-levels',
                'icon' => 'bars-3',
                'title' => __('Membership Levels'),
                'body' => __("Manage your organization's membership levels - create different tiers with different benefits and pricing."),
                'cta' => [
                    'route' => 'lists.membership-levels',
                    'label' => __('Open membership levels'),
                ],
            ];
        }

        return $tips;
    }

    /**
     * Every tip key, in order. Used to decide when all tips are complete.
     *
     * @return list<string>
     */
    public static function keys(?Company $company = null): array
    {
        return array_map(static fn (array $tip): string => $tip['key'], self::all($company));
    }
}
