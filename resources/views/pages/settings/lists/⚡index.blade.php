<?php

use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Attachment;
use App\Models\Classification;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\FormStyle;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Location;
use App\Models\MembershipLevel;
use App\Models\PaymentMethod;
use App\Models\PaymentTerm;
use App\Models\RecurringDocument;
use App\Models\RecurringJournalEntry;
use App\Models\TaxCode;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('All lists')] class extends Component {
    public Company $company;

    public function mount(Company $company): void
    {
        $this->company = $company;
    }

    /**
     * Every list shown on the hub page. Counts are company-scoped via the
     * BelongsToCompany global scope. Rows mirror the feature flags used by
     * the settings layout's Lists navlist group.
     *
     * @return list<array{key: string, label: string, description: string, href: string, count: int}>
     */
    #[Computed]
    public function rows(): array
    {
        $company = $this->company;

        $fixedAssets = (bool) ($company->features_fixed_assets ?? true);
        $classes = (bool) ($company->features_classes ?? false);
        $locations = (bool) ($company->features_locations ?? false);
        $membership = (bool) ($company->features_membership ?? false);

        $rows = [
            [
                'key' => 'accounts',
                'label' => __('Chart of accounts'),
                'description' => __('The accounts every transaction on your books posts to.'),
                'href' => route('accounts.index', ['company' => $company]),
                'count' => Account::query()->count(),
            ],
            [
                'key' => 'recurring',
                'label' => __('Recurring transactions'),
                'description' => __('Templates that create invoices, bills and other documents on a schedule.'),
                'href' => route('recurring.index', ['company' => $company]),
                'count' => RecurringDocument::query()->count(),
            ],
            [
                'key' => 'recurring-journal',
                'label' => __('Recurring journal entries'),
                'description' => __('Journal entry templates that post on a schedule.'),
                'href' => route('recurring-journal.index', ['company' => $company]),
                'count' => RecurringJournalEntry::query()->count(),
            ],
            [
                'key' => 'items',
                'label' => __('Items'),
                'description' => __('Reusable products and services that prefill invoice lines.'),
                'href' => route('lists.items', ['company' => $company]),
                'count' => Item::query()->count(),
            ],
            [
                'key' => 'item-categories',
                'label' => __('Item categories'),
                'description' => __('Group products and services for faster item picking and reporting.'),
                'href' => route('lists.item-categories', ['company' => $company]),
                'count' => ItemCategory::query()->count(),
            ],
            [
                'key' => 'tax-codes',
                'label' => __('Tax codes'),
                'description' => __('Sales tax codes applied to invoice and bill lines.'),
                'href' => route('lists.tax-codes', ['company' => $company]),
                'count' => TaxCode::query()->count(),
            ],
            [
                'key' => 'payment-terms',
                'label' => __('Payment terms'),
                'description' => __('Used to compute invoice and bill due dates.'),
                'href' => route('lists.payment-terms', ['company' => $company]),
                'count' => PaymentTerm::query()->count(),
            ],
            [
                'key' => 'payment-methods',
                'label' => __('Payment methods'),
                'description' => __('Used on receipts and bill payments.'),
                'href' => route('lists.payment-methods', ['company' => $company]),
                'count' => PaymentMethod::query()->count(),
            ],
        ];

        if ($classes) {
            $rows[] = [
                'key' => 'classifications',
                'label' => __('Classes'),
                'description' => __('Tag transaction lines by segment or program, then filter reports.'),
                'href' => route('lists.classifications', ['company' => $company]),
                'count' => Classification::query()->count(),
            ];
        }

        if ($locations) {
            $rows[] = [
                'key' => 'locations',
                'label' => __('Locations'),
                'description' => __('Tag transaction lines by location or branch, then filter reports.'),
                'href' => route('lists.locations', ['company' => $company]),
                'count' => Location::query()->count(),
            ];
        }

        if ($fixedAssets) {
            $rows[] = [
                'key' => 'asset-categories',
                'label' => __('Asset categories'),
                'description' => __('Group fixed assets and define default accounts for new asset records.'),
                'href' => route('lists.asset-categories', ['company' => $company]),
                'count' => AssetCategory::query()->count(),
            ];
        }

        if ($membership) {
            $rows[] = [
                'key' => 'membership-levels',
                'label' => __('Membership levels'),
                'description' => __('Membership tiers and the default dues billed to members.'),
                'href' => route('lists.membership-levels', ['company' => $company]),
                'count' => MembershipLevel::query()->count(),
            ];
        }

        $rows[] = [
            'key' => 'form-styles',
            'label' => __('Form styles'),
            'description' => __('Named invoice templates with logo, accent colour and footer overrides.'),
            'href' => route('lists.form-styles', ['company' => $company]),
            'count' => FormStyle::query()->count(),
        ];

        $rows[] = [
            'key' => 'currencies',
            'label' => __('Currencies'),
            'description' => __('Foreign currencies enabled for customers and vendors.'),
            'href' => route('settings.currencies', ['company' => $company]),
            'count' => CompanyCurrency::query()->count(),
        ];

        $rows[] = [
            'key' => 'attachments',
            'label' => __('Attachments'),
            'description' => __('Files attached to transactions and stored in the document repository.'),
            'href' => route('documents.index', ['company' => $company]),
            'count' => Attachment::query()->count(),
        ];

        return $rows;
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout :heading="__('All lists')" :subheading="__('Every list your company uses, with links and record counts.')" contentClass="max-w-3xl">
        <div class="overflow-hidden rounded-lg border border-border">
            <ul class="divide-y divide-border">
                @foreach ($this->rows as $row)
                    <li class="flex items-center justify-between gap-4 px-4 py-3" data-test="all-lists-row">
                        <div class="min-w-0">
                            <flux:link :href="$row['href']" wire:navigate class="text-sm font-medium">{{ $row['label'] }}</flux:link>
                            <div class="mt-0.5 text-sm text-muted-foreground">{{ $row['description'] }}</div>
                        </div>
                        <flux:badge size="sm" class="shrink-0 tabular-nums" data-test="all-lists-count-{{ $row['key'] }}">{{ number_format($row['count']) }}</flux:badge>
                    </li>
                @endforeach
            </ul>
        </div>
    </x-pages::settings.layout>
</section>
