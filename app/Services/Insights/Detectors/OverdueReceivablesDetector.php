<?php

namespace App\Services\Insights\Detectors;

use App\Enums\InsightCategory;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\Insights\Contracts\InsightDetector;
use App\Services\Insights\Detectors\Concerns\FormatsInsightFacts;
use App\Services\Insights\InsightCandidate;
use App\Services\Reporting\OpenDocumentAgingBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Flags meaningful overdue AR: at least one open invoice past its due date
 * and at least $100 overdue per the AR aging buckets, so the owner is nudged
 * toward a payment reminder before balances drift into the 90+ bucket.
 */
final class OverdueReceivablesDetector implements InsightDetector
{
    use FormatsInsightFacts;

    private const MIN_OVERDUE_CENTS = 10_000;

    public function __construct(private readonly OpenDocumentAgingBuilder $aging) {}

    public function key(): string
    {
        return 'overdue-receivables';
    }

    public function category(): InsightCategory
    {
        return InsightCategory::Hygiene;
    }

    public function detect(Company $company, CarbonImmutable $today): array
    {
        // Cheap gate first: one indexed count over open invoices decides
        // whether the (heavier) aging build is worth running at all.
        $invoiceCount = $this->overdueInvoices($company, $today)->count();

        if ($invoiceCount < 1) {
            return [];
        }

        $totals = $this->aging->summary($company, 'ar', $today, owingOnly: true)['totals'];

        $overdueCents = $totals['b1_30'] + $totals['b31_60'] + $totals['b61_90'] + $totals['b90_plus'];

        if ($overdueCents < self::MIN_OVERDUE_CENTS) {
            return [];
        }

        $over90Cents = $totals['b90_plus'];
        $totalArCents = $totals['total'];

        $oldestDueDate = $this->overdueInvoices($company, $today)->min('due_date');

        if ($oldestDueDate === null) {
            return [];
        }

        $oldestDaysOverdue = (int) CarbonImmutable::parse($oldestDueDate)
            ->startOfDay()
            ->diffInDays($today->startOfDay());

        $score = 70;
        if ($over90Cents > 0) {
            $score += 10;
        }
        if ($overdueCents * 100 > $totalArCents * 25) {
            $score += 10;
        }

        $headline = trans_choice(
            '{1} 1 invoice is overdue — :overdue outstanding|[2,*] :count invoices are overdue — :overdue outstanding',
            $invoiceCount,
            ['count' => $invoiceCount, 'overdue' => $this->formatWhole($overdueCents)],
        );

        $body = $this->mostlyMemberDues($company, $today, $invoiceCount)
            ? __('The oldest is :days days past due. Most are member dues — a gentle reminder usually does it.', ['days' => $oldestDaysOverdue])
            : __('The oldest is :days days past due. A friendly payment reminder usually does the trick.', ['days' => $oldestDaysOverdue]);

        return [new InsightCandidate(
            key: $this->key(),
            category: $this->category(),
            score: min($score, 90),
            facts: [
                'overdue_cents' => $overdueCents,
                'overdue_display' => $this->formatWhole($overdueCents),
                'invoice_count' => $invoiceCount,
                'oldest_days_overdue' => $oldestDaysOverdue,
                'over90_cents' => $over90Cents,
                'over90_display' => $this->formatWhole($over90Cents),
                'total_ar_cents' => $totalArCents,
                'total_ar_display' => $this->formatWhole($totalArCents),
            ],
            headline: $headline,
            body: $body,
        )];
    }

    /**
     * @return array{route: string, label: string}
     */
    public function cta(Company $company): array
    {
        return ['route' => 'reports.ar-aging', 'label' => __('View AR aging')];
    }

    /**
     * Open invoices past due with a balance still outstanding — the balance
     * arithmetic mirrors {@see Invoice::balanceCents()} (paid + reconciled),
     * kept as portable SQL so MySQL and SQLite agree.
     *
     * @return Builder<Invoice>
     */
    private function overdueInvoices(Company $company, CarbonImmutable $today): Builder
    {
        return Invoice::query()
            ->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereNull('deleted_at')
            ->openWithBalance()
            ->where('due_date', '<', $today->toDateString());
    }

    /**
     * Membership variant gate: dues invoices carry members.id on
     * invoices.member_id (stamped by BillMemberDues), so one cheap count
     * tells us whether at least half the overdue invoices are member dues.
     */
    private function mostlyMemberDues(Company $company, CarbonImmutable $today, int $invoiceCount): bool
    {
        if (! $company->tracksMembership()) {
            return false;
        }

        $memberDuesCount = $this->overdueInvoices($company, $today)
            ->whereNotNull('member_id')
            ->count();

        return $memberDuesCount * 2 >= $invoiceCount;
    }
}
