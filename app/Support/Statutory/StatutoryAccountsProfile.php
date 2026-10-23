<?php

namespace App\Support\Statutory;

use App\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The narrative and company details that statutory accounts carry beyond the
 * numbers: directors and their shareholdings, the legal regime the accounts are
 * prepared under, accounting policies, approval and signatories. Stored on the
 * company (settings.statutory_accounts) and edited on the report page.
 */
final class StatutoryAccountsProfile
{
    public const FRAMEWORK_FRS102_1A = 'frs102_1a';

    public const FRAMEWORK_FRS105 = 'frs105';

    public const REGIME_UK_2006 = 'uk_2006';

    public const REGIME_IOM_1931 = 'iom_1931';

    public const REGIME_IOM_2006 = 'iom_2006';

    /**
     * @param  list<array{name: string, shares_current: string, shares_prior: string}>  $directors
     * @param  list<array{class: string, rate: string, method: string}>  $depreciationRates
     * @param  list<string>  $signatories
     */
    public function __construct(
        public string $framework,
        public string $regime,
        public int $yearEndDay,
        public int $yearEndMonth,
        public string $companyNumber,
        public string $registeredOffice,
        public string $incorporatedIn,
        public array $directors,
        public string $secretary,
        public string $principalActivity,
        public array $depreciationRates,
        public string $judgementsNote,
        public string $taxationNote,
        public string $authorisedShares,
        public string $issuedShares,
        public string $relatedParties,
        public string $creditorsAfterNarrative,
        public string $directorsAdvances,
        public string $guarantees,
        public ?int $averageEmployees,
        public ?string $approvalDate,
        public array $signatories,
        public string $directorsReportSignatory,
        public string $directorsReportSignatoryRole,
        public bool $includeDirectorsReport,
        public bool $includeProfitAndLossForMicro,
    ) {}

    /**
     * @return array<string, string>
     */
    public static function frameworks(): array
    {
        return [
            self::FRAMEWORK_FRS102_1A => 'FRS 102 Section 1A (small entities)',
            self::FRAMEWORK_FRS105 => 'FRS 105 (micro-entities)',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function regimes(): array
    {
        return [
            self::REGIME_UK_2006 => 'UK — Companies Act 2006',
            self::REGIME_IOM_1931 => 'Isle of Man — Companies Acts 1931 to 2004',
            self::REGIME_IOM_2006 => 'Isle of Man — Companies Act 2006',
        ];
    }

    public static function forCompany(Company $company): self
    {
        $saved = (array) (($company->settings ?? [])['statutory_accounts'] ?? []);
        $defaults = self::defaultsFor($company);

        return self::fromArray(array_merge($defaults, array_filter($saved, fn ($v) => $v !== null)));
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsFor(Company $company): array
    {
        $number = trim((string) ($company->company_registration_number ?? ''));
        $manx = mb_strtoupper((string) $company->address_region) === 'IM'
            || preg_match('/^\d+[CV]$/i', $number) === 1;

        $regime = match (true) {
            preg_match('/^\d+V$/i', $number) === 1 => self::REGIME_IOM_2006,
            $manx => self::REGIME_IOM_1931,
            default => self::REGIME_UK_2006,
        };

        // The year ends the day before the fiscal year starts: an April start
        // means a 31 March year end. Companies on the old tax year (6 April –
        // 5 April) set 5 April here.
        $startMonth = max(1, min(12, (int) ($company->fiscal_year_start_month ?? 1)));
        $endMonth = $startMonth === 1 ? 12 : $startMonth - 1;
        $endDay = CarbonImmutable::create(2001, $endMonth, 1)->endOfMonth()->day;

        $office = collect([
            $company->address_line1 ?? null,
            $company->address_line2 ?? null,
            $company->address_city ?? null,
            $manx ? 'Isle of Man' : null,
            $company->address_postal_code ?? null,
        ])->filter(fn ($v) => trim((string) $v) !== '')->implode("\n");

        return [
            'framework' => self::FRAMEWORK_FRS102_1A,
            'regime' => $regime,
            'year_end_day' => $endDay,
            'year_end_month' => $endMonth,
            'company_number' => $number,
            'registered_office' => $office,
            'incorporated_in' => $manx ? 'the Isle of Man' : 'England and Wales',
            'directors' => [],
            'secretary' => '',
            'principal_activity' => '',
            'depreciation_rates' => [],
            'judgements_note' => 'No significant judgements have had to be made by the directors in preparing these financial statements.',
            'taxation_note' => $manx ? 'Taxation for companies in the Isle of Man is 0%.' : '',
            'authorised_shares' => '',
            'issued_shares' => '',
            'related_parties' => '',
            'creditors_after_narrative' => '',
            'directors_advances' => '',
            'guarantees' => '',
            'average_employees' => null,
            'approval_date' => null,
            'signatories' => [],
            'directors_report_signatory' => '',
            'directors_report_signatory_role' => 'Director',
            'include_directors_report' => true,
            'include_profit_and_loss_for_micro' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     */
    public static function fromArray(array $a): self
    {
        $directors = collect((array) ($a['directors'] ?? []))
            ->map(fn ($d) => [
                'name' => trim((string) ($d['name'] ?? '')),
                'shares_current' => trim((string) ($d['shares_current'] ?? '')),
                'shares_prior' => trim((string) ($d['shares_prior'] ?? '')),
            ])
            ->filter(fn ($d) => $d['name'] !== '')
            ->values()
            ->all();

        $rates = collect((array) ($a['depreciation_rates'] ?? []))
            ->map(fn ($r) => [
                'class' => trim((string) ($r['class'] ?? '')),
                'rate' => trim((string) ($r['rate'] ?? '')),
                'method' => trim((string) ($r['method'] ?? '')),
            ])
            ->filter(fn ($r) => $r['class'] !== '')
            ->values()
            ->all();

        $framework = array_key_exists((string) ($a['framework'] ?? ''), self::frameworks()) ? (string) $a['framework'] : self::FRAMEWORK_FRS102_1A;
        $regime = array_key_exists((string) ($a['regime'] ?? ''), self::regimes()) ? (string) $a['regime'] : self::REGIME_UK_2006;
        $month = max(1, min(12, (int) ($a['year_end_month'] ?? 12)));
        $day = max(1, min(CarbonImmutable::create(2001, $month, 1)->endOfMonth()->day, (int) ($a['year_end_day'] ?? 31)));
        $employees = ($a['average_employees'] ?? null);
        $approval = trim((string) ($a['approval_date'] ?? ''));

        return new self(
            framework: $framework,
            regime: $regime,
            yearEndDay: $day,
            yearEndMonth: $month,
            companyNumber: trim((string) ($a['company_number'] ?? '')),
            registeredOffice: trim((string) ($a['registered_office'] ?? '')),
            incorporatedIn: trim((string) ($a['incorporated_in'] ?? '')),
            directors: $directors,
            secretary: trim((string) ($a['secretary'] ?? '')),
            principalActivity: trim((string) ($a['principal_activity'] ?? '')),
            depreciationRates: $rates,
            judgementsNote: trim((string) ($a['judgements_note'] ?? '')),
            taxationNote: trim((string) ($a['taxation_note'] ?? '')),
            authorisedShares: trim((string) ($a['authorised_shares'] ?? '')),
            issuedShares: trim((string) ($a['issued_shares'] ?? '')),
            relatedParties: trim((string) ($a['related_parties'] ?? '')),
            creditorsAfterNarrative: trim((string) ($a['creditors_after_narrative'] ?? '')),
            directorsAdvances: trim((string) ($a['directors_advances'] ?? '')),
            guarantees: trim((string) ($a['guarantees'] ?? '')),
            averageEmployees: $employees === null || $employees === '' ? null : max(0, (int) $employees),
            approvalDate: $approval !== '' && strtotime($approval) !== false ? CarbonImmutable::parse($approval)->toDateString() : null,
            signatories: collect((array) ($a['signatories'] ?? []))->map(fn ($s) => trim((string) $s))->filter()->values()->all(),
            directorsReportSignatory: trim((string) ($a['directors_report_signatory'] ?? '')),
            directorsReportSignatoryRole: trim((string) ($a['directors_report_signatory_role'] ?? '')) ?: 'Director',
            includeDirectorsReport: (bool) ($a['include_directors_report'] ?? true),
            includeProfitAndLossForMicro: (bool) ($a['include_profit_and_loss_for_micro'] ?? true),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'framework' => $this->framework,
            'regime' => $this->regime,
            'year_end_day' => $this->yearEndDay,
            'year_end_month' => $this->yearEndMonth,
            'company_number' => $this->companyNumber,
            'registered_office' => $this->registeredOffice,
            'incorporated_in' => $this->incorporatedIn,
            'directors' => $this->directors,
            'secretary' => $this->secretary,
            'principal_activity' => $this->principalActivity,
            'depreciation_rates' => $this->depreciationRates,
            'judgements_note' => $this->judgementsNote,
            'taxation_note' => $this->taxationNote,
            'authorised_shares' => $this->authorisedShares,
            'issued_shares' => $this->issuedShares,
            'related_parties' => $this->relatedParties,
            'creditors_after_narrative' => $this->creditorsAfterNarrative,
            'directors_advances' => $this->directorsAdvances,
            'guarantees' => $this->guarantees,
            'average_employees' => $this->averageEmployees,
            'approval_date' => $this->approvalDate,
            'signatories' => $this->signatories,
            'directors_report_signatory' => $this->directorsReportSignatory,
            'directors_report_signatory_role' => $this->directorsReportSignatoryRole,
            'include_directors_report' => $this->includeDirectorsReport,
            'include_profit_and_loss_for_micro' => $this->includeProfitAndLossForMicro,
        ];
    }

    public function save(Company $company): void
    {
        $settings = $company->settings ?? [];
        $settings['statutory_accounts'] = $this->toArray();
        $company->forceFill(['settings' => $settings])->save();
    }

    public function isMicro(): bool
    {
        return $this->framework === self::FRAMEWORK_FRS105;
    }

    public function isIsleOfMan(): bool
    {
        return $this->regime !== self::REGIME_UK_2006;
    }

    /**
     * The most recent year end on or before $today, and the year it closes.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function lastCompletedYear(CarbonImmutable $today): array
    {
        $end = $this->yearEndIn($today->year);

        if ($end->greaterThanOrEqualTo($today->startOfDay())) {
            $end = $this->yearEndIn($today->year - 1);
        }

        return [$this->yearEndIn($end->year - 1)->addDay(), $end];
    }

    /**
     * The whole statutory years inside a date range, oldest first: each runs
     * from the day after one year end to the next, and both ends fall in the
     * range. 6 April 2023 – 5 April 2026 on a 5 April year end is three years.
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function yearsWithin(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $start = $start->startOfDay();
        $end = $end->startOfDay();
        $years = [];

        for ($y = $start->year; $y <= $end->year; $y++) {
            $yearEnd = $this->yearEndIn($y);
            $yearStart = $this->yearEndIn($y - 1)->addDay();

            if ($yearStart->greaterThanOrEqualTo($start) && $yearEnd->lessThanOrEqualTo($end)) {
                $years[] = [$yearStart, $yearEnd];
            }
        }

        return $years;
    }

    /**
     * The period the accounts documents cover for a chosen range: the range
     * itself, unless it spans more than one statutory year — accounts are for
     * a single year — in which case the latest of them.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function documentPeriod(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $years = $this->yearsWithin($start, $end);

        return count($years) > 1 ? $years[count($years) - 1] : [$start->startOfDay(), $end->startOfDay()];
    }

    public function yearEndIn(int $year): CarbonImmutable
    {
        $last = CarbonImmutable::create($year, $this->yearEndMonth, 1)->endOfMonth()->day;

        return CarbonImmutable::create($year, $this->yearEndMonth, min($this->yearEndDay, $last))->startOfDay();
    }

    /**
     * The legislation the directors' responsibilities refer to.
     */
    public function legislation(): string
    {
        return match ($this->regime) {
            self::REGIME_IOM_1931 => 'Isle of Man Companies Acts 1931 to 2004',
            self::REGIME_IOM_2006 => 'Isle of Man Companies Act 2006',
            default => 'Companies Act 2006',
        };
    }
}
