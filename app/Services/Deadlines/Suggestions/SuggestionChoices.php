<?php

namespace App\Services\Deadlines\Suggestions;

/**
 * Facts the suggestions need that LineLedger doesn't store: how often the
 * organization files GST/HST or PST, which instalment method it uses, its VAT
 * stagger, and so on. Each has a safe default; the suggestions page lets the
 * user change them.
 */
final readonly class SuggestionChoices
{
    public const array GST_FREQUENCIES = ['monthly', 'quarterly', 'annual'];

    public const array PST_FREQUENCIES = ['monthly', 'quarterly', 'semi_annual', 'annual'];

    public const array INSTALMENT_METHODS = ['monthly', 'quarterly'];

    public const array PARTNERSHIP_MEMBERS = ['individuals', 'corporations'];

    public const array VAT_STAGGERS = ['1', '2', '3'];

    public const array US_CORPORATION_TYPES = ['c_corp', 's_corp'];

    public const array SALES_TAX_FREQUENCIES = ['monthly', 'quarterly', 'annual'];

    public function __construct(
        public string $gstFrequency = 'quarterly',
        public string $pstFrequency = 'monthly',
        public bool $ccpcThreeMonthBalance = false,
        public string $instalmentMethod = 'monthly',
        public string $partnershipMembers = 'individuals',
        public string $vatStagger = '1',
        public string $usCorporationType = 'c_corp',
        public string $stateSalesTaxFrequency = 'monthly',
        public ?int $stateSalesTaxDay = null,
        public ?string $confirmationStatementDate = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $pick = fn (string $key, array $allowed, string $default): string => in_array((string) ($input[$key] ?? ''), $allowed, true)
            ? (string) $input[$key]
            : $default;

        $day = (int) ($input['state_sales_tax_day'] ?? 0);
        $date = (string) ($input['confirmation_statement_date'] ?? '');

        return new self(
            gstFrequency: $pick('gst_frequency', self::GST_FREQUENCIES, 'quarterly'),
            pstFrequency: $pick('pst_frequency', self::PST_FREQUENCIES, 'monthly'),
            ccpcThreeMonthBalance: (bool) ($input['ccpc_three_month_balance'] ?? false),
            instalmentMethod: $pick('instalment_method', self::INSTALMENT_METHODS, 'monthly'),
            partnershipMembers: $pick('partnership_members', self::PARTNERSHIP_MEMBERS, 'individuals'),
            vatStagger: $pick('vat_stagger', self::VAT_STAGGERS, '1'),
            usCorporationType: $pick('us_corporation_type', self::US_CORPORATION_TYPES, 'c_corp'),
            stateSalesTaxFrequency: $pick('state_sales_tax_frequency', self::SALES_TAX_FREQUENCIES, 'monthly'),
            stateSalesTaxDay: $day >= 1 && $day <= 31 ? $day : null,
            confirmationStatementDate: preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null,
        );
    }
}
