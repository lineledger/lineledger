<?php

namespace App\Enums;

use App\Models\Company;
use App\Support\Defaults\AmericanDefaults;
use App\Support\Defaults\BritishDefaults;
use App\Support\Defaults\CanadianDefaults;
use App\Support\Defaults\CompanyDefaults;

/**
 * The legal jurisdiction a company operates under. Set once at creation and
 * immutable afterward. Drives currency defaults, terminology (cheque/check,
 * postal/zip, province/state), and the seeded chart of accounts / tax codes /
 * payment methods.
 *
 * Add new cases here when expanding internationally. Each case must implement
 * every method below — there is no fallback.
 */
enum Country: string
{
    case Canada = 'CA';
    case UnitedStates = 'US';
    case UnitedKingdom = 'GB';

    public function label(): string
    {
        return match ($this) {
            self::Canada => 'Canada',
            self::UnitedStates => 'United States',
            self::UnitedKingdom => 'United Kingdom',
        };
    }

    /**
     * Regional-indicator flag emoji, used in country-switching UI.
     */
    public function flag(): string
    {
        return match ($this) {
            self::Canada => '🇨🇦',
            self::UnitedStates => '🇺🇸',
            self::UnitedKingdom => '🇬🇧',
        };
    }

    /**
     * Resolve the deployment's country from its request host: a `.ca` apex maps
     * to Canada, a `.uk` apex (including `.co.uk`) to the United Kingdom, and
     * anything else (`.com`, `localhost`, …) to the United States.
     */
    public static function fromHost(?string $host): self
    {
        $host = mb_strtolower(trim((string) $host));

        if (str_ends_with($host, '.ca')) {
            return self::Canada;
        }

        if (str_ends_with($host, '.uk')) {
            return self::UnitedKingdom;
        }

        return self::UnitedStates;
    }

    public function defaultCurrencyCode(): string
    {
        return match ($this) {
            self::Canada => 'CAD',
            self::UnitedStates => 'USD',
            self::UnitedKingdom => 'GBP',
        };
    }

    /**
     * Best-guess default IANA timezone for a new company, refined by province/
     * state when known. These jurisdictions span several zones, so this is only
     * a sensible starting point — owners can change it in company settings.
     *
     * Returns ids drawn from {@see Company::timezoneOptions()} so the
     * default always resolves to a friendly option in the settings picker. The
     * shared North American zones (Mountain/Central/Eastern) use their
     * canonical US-city ids, which change their clocks on the same dates as the
     * Canadian-city equivalents. BC and Yukon get their own ids: both stay on
     * UTC−7 all year (Yukon since 2020, BC from 2026-11-01), so US Pacific time
     * would put "today" an hour behind them every winter.
     */
    public function defaultTimezone(?string $regionCode = null): string
    {
        $region = $regionCode !== null ? mb_strtoupper($regionCode) : null;

        return match ($this) {
            self::Canada => match ($region) {
                'BC' => 'America/Vancouver',
                'YT' => 'America/Whitehorse',
                'AB', 'NT' => 'America/Denver',
                'SK', 'MB' => 'America/Chicago',
                'NB', 'NS', 'PE' => 'America/Halifax',
                'NL' => 'America/St_Johns',
                default => 'America/New_York',
            },
            self::UnitedStates => match ($region) {
                'CA', 'WA', 'OR', 'NV' => 'America/Los_Angeles',
                'AZ' => 'America/Phoenix',
                'CO', 'UT', 'NM', 'MT', 'WY', 'ID' => 'America/Denver',
                'TX', 'IL', 'MN', 'MO', 'WI', 'IA', 'KS', 'NE', 'OK', 'AR', 'LA', 'ND', 'SD' => 'America/Chicago',
                'AK' => 'America/Anchorage',
                'HI' => 'Pacific/Honolulu',
                default => 'America/New_York',
            },
            self::UnitedKingdom => 'Europe/London',
        };
    }

    /**
     * Best-guess region code for a browser-reported IANA timezone, or null when
     * the zone spans several of this country's regions (so the owner picks).
     *
     * Most operating systems report a province/state-specific zone id
     * (America/Vancouver, America/Halifax…), which pins the region exactly.
     * Zones shared by several regions (America/New_York, America/Chicago,
     * America/Los_Angeles, America/Denver) are deliberately left unmapped: a
     * wrong tax jurisdiction is worse than an empty field, and region — unlike
     * country — stays editable after setup.
     *
     * @return string|null one of the {@see self::regions()} keys
     */
    public function regionForTimezone(string $tz): ?string
    {
        $region = match ($this) {
            self::Canada => match ($tz) {
                'America/St_Johns', 'America/Goose_Bay' => 'NL',
                'America/Halifax', 'America/Glace_Bay' => 'NS',
                'America/Moncton' => 'NB',
                'America/Toronto', 'America/Nipigon', 'America/Thunder_Bay', 'America/Atikokan' => 'ON',
                'America/Montreal', 'America/Blanc-Sablon' => 'QC',
                'America/Winnipeg', 'America/Rainy_River' => 'MB',
                'America/Regina', 'America/Swift_Current' => 'SK',
                'America/Edmonton' => 'AB',
                'America/Yellowknife', 'America/Inuvik' => 'NT',
                'America/Iqaluit', 'America/Rankin_Inlet', 'America/Cambridge_Bay', 'America/Resolute' => 'NU',
                'America/Vancouver', 'America/Dawson_Creek', 'America/Fort_Nelson', 'America/Creston' => 'BC',
                'America/Whitehorse', 'America/Dawson' => 'YT',
                default => null,
            },
            self::UnitedStates => match ($tz) {
                'America/Phoenix' => 'AZ',
                'Pacific/Honolulu' => 'HI',
                'America/Anchorage', 'America/Juneau', 'America/Sitka', 'America/Nome', 'America/Yakutat', 'America/Adak' => 'AK',
                'America/Detroit', 'America/Menominee' => 'MI',
                'America/Boise' => 'ID',
                default => null,
            },
            // Europe/London covers all four nations — do not guess. The Isle of
            // Man has its own zone id, and some systems still report Belfast's.
            self::UnitedKingdom => match ($tz) {
                'Europe/Isle_of_Man' => 'IM',
                'Europe/Belfast' => 'NIR',
                default => null,
            },
        };

        // Never return a code we don't actually offer as a selectable option.
        return $region !== null && array_key_exists($region, $this->regions()) ? $region : null;
    }

    public function regionLabel(): string
    {
        return match ($this) {
            self::Canada => __('Province'),
            self::UnitedStates => __('State'),
            self::UnitedKingdom => __('Nation'),
        };
    }

    public function postalCodeLabel(): string
    {
        return match ($this) {
            self::Canada => __('Postal Code'),
            self::UnitedStates => __('ZIP Code'),
            self::UnitedKingdom => __('Postcode'),
        };
    }

    public function taxLabel(): string
    {
        return match ($this) {
            self::Canada => __('GST/HST'),
            self::UnitedStates => __('Sales Tax'),
            self::UnitedKingdom => __('VAT'),
        };
    }

    /**
     * Form label for the company's own sales-tax registration number — the
     * number stored on {@see Company::$tax_number} and on the default agency.
     */
    public function taxNumberLabel(): string
    {
        return match ($this) {
            self::Canada => __('GST/HST account number'),
            self::UnitedStates => __('Sales tax account number'),
            self::UnitedKingdom => __('VAT registration number'),
        };
    }

    /**
     * Short name for tax paid on purchases that a return claims back: an
     * input tax credit (ITC) in Canada — the term the app has always used, so
     * the US keeps it too — and input VAT in the UK. Untranslated: it heads
     * CSV/XLSX exports and report PDFs, which stay English. On screen, use the
     * translated labels below.
     */
    public function inputTaxLabel(): string
    {
        return match ($this) {
            self::Canada, self::UnitedStates => 'ITC',
            self::UnitedKingdom => 'Input VAT',
        };
    }

    /**
     * Column heading for the tax paid on purchases ("Paid (ITC)").
     */
    public function paidInputTaxLabel(): string
    {
        return match ($this) {
            self::Canada, self::UnitedStates => __('Paid (ITC)'),
            self::UnitedKingdom => __('Paid (Input VAT)'),
        };
    }

    /**
     * Heading for the line-by-line breakdown behind {@see paidInputTaxLabel()}.
     */
    public function paidInputTaxBreakdownLabel(): string
    {
        return match ($this) {
            self::Canada, self::UnitedStates => __('Paid (ITC) breakdown'),
            self::UnitedKingdom => __('Paid (Input VAT) breakdown'),
        };
    }

    /**
     * Tile heading for the tax paid on purchases on a tax return ("Paid (ITCs)").
     */
    public function paidInputTaxTotalLabel(): string
    {
        return match ($this) {
            self::Canada, self::UnitedStates => __('Paid (ITCs)'),
            self::UnitedKingdom => __('Paid (Input VAT)'),
        };
    }

    /**
     * Row label for the claimed input tax in a return's reconciliation.
     */
    public function inputTaxTotalLabel(): string
    {
        return match ($this) {
            self::Canada, self::UnitedStates => __('ITCs'),
            self::UnitedKingdom => __('Input VAT'),
        };
    }

    /**
     * The jurisdiction of the company bound to the current request, or Canada
     * outside one (console, queued jobs) — the app's historical wording.
     */
    public static function current(): self
    {
        $company = app()->bound('current_company') ? app('current_company') : null;

        return $company instanceof Company ? $company->jurisdiction : self::Canada;
    }

    /**
     * Normalize a sales-tax registration number as typed (spaces, dashes and
     * case) into the stored form. UK VAT numbers are kept with their "GB"
     * prefix; other jurisdictions are only trimmed, so existing data is
     * untouched.
     */
    public function normalizeTaxNumber(?string $number): ?string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        if ($this !== self::UnitedKingdom) {
            return $number;
        }

        $compact = mb_strtoupper((string) preg_replace('/[\s.\-]+/', '', $number));

        // A bare 9- or 12-digit number gets the GB prefix it is always quoted with.
        return preg_match('/^\d{9}(\d{3})?$/', $compact) === 1 ? 'GB'.$compact : $compact;
    }

    /**
     * Whether a (normalized) registration number has a valid shape for this
     * jurisdiction. Only the United Kingdom is checked: a VAT number is "GB"
     * plus 9 digits (or 12 for a branch/group member), or "GBGD"/"GBHA" plus
     * 3 digits for government departments and health authorities. Isle of Man
     * businesses are on the same register and use the same GB format.
     * Northern Ireland traders quoting an "XI" number are accepted too.
     */
    public function isValidTaxNumber(?string $number): bool
    {
        if ($this !== self::UnitedKingdom) {
            return true;
        }

        $number = $this->normalizeTaxNumber($number);

        if ($number === null) {
            return true;
        }

        return preg_match('/^(GB|XI)(\d{9}|\d{12}|GD\d{3}|HA\d{3})$/', $number) === 1;
    }

    /**
     * Short numeric date format printed on customer and vendor documents
     * (invoices, bills, statements). North America writes month first; the
     * UK writes day first, so 03/04/2026 is 3 April there.
     */
    public function shortDateFormat(): string
    {
        return match ($this) {
            self::Canada, self::UnitedStates => 'n/j/Y',
            self::UnitedKingdom => 'd/m/Y',
        };
    }

    /**
     * The fiscal-year start month a new company is given unless the owner
     * picks one. Canadian and US businesses mostly run calendar years; UK
     * sole traders and partnerships are taxed on the 6 April tax year, which
     * HMRC lets them align to an April–March year.
     */
    public function defaultFiscalYearStartMonth(): int
    {
        return match ($this) {
            self::Canada, self::UnitedStates => 1,
            self::UnitedKingdom => 4,
        };
    }

    /**
     * Name of the authority that administers sales tax for a region of this
     * country, when it differs from the national default the jurisdiction's
     * {@see CompanyDefaults::taxAgencies()} seeds. The Isle of Man is inside
     * the UK VAT area but its VAT is administered by its own Customs and
     * Excise Division rather than HMRC.
     */
    public function taxAgencyNameForRegion(?string $regionCode): ?string
    {
        return match (true) {
            $this === self::UnitedKingdom && mb_strtoupper((string) $regionCode) === 'IM' => 'Isle of Man Customs and Excise',
            default => null,
        };
    }

    /**
     * Label for the corporate registry number field (e.g. Companies House), or
     * null when this jurisdiction does not collect one on company setup.
     */
    public function companyRegistrationNumberLabel(): ?string
    {
        return match ($this) {
            self::UnitedKingdom => __('Company number'),
            default => null,
        };
    }

    /**
     * Whether a company of this organization type typically has a corporate
     * registry number in this jurisdiction (UK Ltd/PLC and LLP do; sole traders
     * do not).
     */
    public function usesCompanyRegistrationNumber(?OrganizationType $organizationType): bool
    {
        if ($this->companyRegistrationNumberLabel() === null || $organizationType === null) {
            return false;
        }

        return match ($this) {
            self::UnitedKingdom => in_array($organizationType, [
                OrganizationType::Corporation,
                OrganizationType::Partnership,
            ], true),
            default => false,
        };
    }

    /**
     * Normalize a corporate registry number as typed: spaces removed and
     * upper-cased. A purely numeric UK number is left-padded to the eight
     * digits Companies House always shows (1234567 → 01234567); prefixed ones
     * (SC…, NI…, OC… for LLPs) and Isle of Man numbers (e.g. 123456C) are
     * kept as entered.
     */
    public function normalizeCompanyRegistrationNumber(?string $number): ?string
    {
        $compact = mb_strtoupper((string) preg_replace('/\s+/', '', (string) $number));

        if ($compact === '') {
            return null;
        }

        if ($this === self::UnitedKingdom && preg_match('/^\d{1,7}$/', $compact) === 1) {
            return str_pad($compact, 8, '0', STR_PAD_LEFT);
        }

        return $compact;
    }

    /**
     * Short label printed on invoices/PDFs next to {@see Company::$tax_number}.
     */
    public function taxNumberDocumentLabel(): string
    {
        return match ($this) {
            self::Canada => __('GST/HST No.'),
            self::UnitedStates => __('Tax No.'),
            self::UnitedKingdom => __('VAT No.'),
        };
    }

    /**
     * @return array<string, string> code => name (e.g. 'BC' => 'British Columbia')
     */
    public function regions(): array
    {
        return match ($this) {
            self::Canada => [
                'AB' => 'Alberta',
                'BC' => 'British Columbia',
                'MB' => 'Manitoba',
                'NB' => 'New Brunswick',
                'NL' => 'Newfoundland and Labrador',
                'NS' => 'Nova Scotia',
                'NT' => 'Northwest Territories',
                'NU' => 'Nunavut',
                'ON' => 'Ontario',
                'PE' => 'Prince Edward Island',
                'QC' => 'Quebec',
                'SK' => 'Saskatchewan',
                'YT' => 'Yukon',
            ],
            self::UnitedStates => [
                'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
                'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
                'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
                'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
                'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
                'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
                'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
                'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
                'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
                'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
                'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
                'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
                'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
            ],
            self::UnitedKingdom => [
                'ENG' => 'England',
                'SCT' => 'Scotland',
                'WLS' => 'Wales',
                'NIR' => 'Northern Ireland',
                'IM' => 'Isle of Man',
            ],
        };
    }

    /**
     * Singular or plural noun for cheque/check, sentence-cased.
     * Pass 'singular' (default) or 'plural'.
     */
    public function cheque(string $form = 'singular'): string
    {
        $singular = match ($this) {
            self::Canada, self::UnitedKingdom => 'Cheque',
            self::UnitedStates => 'Check',
        };

        return $form === 'plural' ? $singular.'s' : $singular;
    }

    /**
     * Pre-baked labels used in cheque-related forms and buttons. Keys:
     *   - "number"      e.g. "Cheque #" / "Check #"
     *   - "edit"        e.g. "Edit cheque" / "Edit check"
     *   - "write"       e.g. "Write cheque" / "Write check"
     *   - "post"        e.g. "Post cheque" / "Post check"
     *   - "print"       e.g. "Print cheque" / "Print check"
     *   - "ref"         e.g. "Cheque # or txn ref"
     *   - "section"     e.g. "Cheques and Payments"
     *   - "checkbook"   e.g. "Chequebook view" / "Checkbook view"
     *   - "method"      e.g. "Cheque method" / "Check method"
     */
    public function chequeLabel(string $key): string
    {
        $singular = $this->cheque('singular');
        $plural = $this->cheque('plural');
        $lower = mb_strtolower($singular);

        return match ($key) {
            'number' => $singular.' #',
            'edit' => 'Edit '.$lower,
            'write' => 'Write '.$lower,
            'post' => 'Post '.$lower,
            'print' => 'Print '.$lower,
            'ref' => $singular.' # or txn ref',
            'section' => $plural.' and Payments',
            'checkbook' => match ($this) {
                self::Canada, self::UnitedKingdom => 'Chequebook view',
                self::UnitedStates => 'Checkbook view',
            },
            'method' => $singular.' method',
            'plural' => $plural,
            'singular' => $singular,
            default => $singular,
        };
    }

    public function defaults(): CompanyDefaults
    {
        return match ($this) {
            self::Canada => new CanadianDefaults,
            self::UnitedStates => new AmericanDefaults,
            self::UnitedKingdom => new BritishDefaults,
        };
    }

    /**
     * Country cases offered as form options, in declaration order
     * (Canada, United States, United Kingdom).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $c) => ['value' => $c->value, 'label' => $c->label()],
            self::cases(),
        );
    }
}
