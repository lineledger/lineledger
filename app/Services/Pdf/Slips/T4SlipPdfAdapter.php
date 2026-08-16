<?php

namespace App\Services\Pdf\Slips;

use App\Actions\Payroll\FinalizeSlipFiling;
use App\Models\Company;
use App\Models\Contact;
use App\Models\EmployeePayrollProfile;
use App\Services\Pdf\PdfMerger;
use App\Services\Reporting\T4SlipCalculator;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Renders one employee's T4 onto the OFFICIAL CRA template (two employee
 * copies per page, as the form is laid out). Returns null when no template or
 * field map exists for the year — callers fall back to the labelled facsimile.
 *
 * Consumes the same slip array {@see T4SlipCalculator}
 * builds (and {@see FinalizeSlipFiling} snapshots), so
 * live and finalized renders can't diverge. The full SIN is deliberately NOT
 * in that snapshot; it is decrypted from the employee's payroll profile at
 * render time (least-privilege — a missing profile or undecryptable SIN falls
 * back to the masked last-4). The employer's account number is intentionally
 * absent: CRA's distribution rules keep it off employee copies. More than six
 * Other Information amounts overflow onto an additional slip page, per CRA's
 * "issue an additional slip" rule.
 */
final class T4SlipPdfAdapter
{
    /** Boxes CRA expects completed even when the amount is zero. */
    private const ALWAYS_PRINT = ['box14', 'box22', 'box24', 'box26'];

    public function __construct(
        private readonly SlipTemplateRegistry $registry,
        private readonly SlipTemplateRenderer $renderer,
        private readonly PdfMerger $merger,
    ) {}

    /**
     * @param  array<string, mixed>  $slip
     * @param  ?int  $contactId  Overrides $slip['contact_id'] — finalized
     *                           snapshots keep their original id, which goes
     *                           stale after a contact merge; the filing LINE's
     *                           contact_id is remapped and authoritative.
     */
    public function render(Company $company, array $slip, int $year, ?int $contactId = null): ?string
    {
        $template = $this->registry->path(SlipTemplateRegistry::T4, $year);
        $map = SlipFieldMaps::for(SlipTemplateRegistry::T4, $year);

        if ($template === null || $map === null) {
            return null;
        }

        $pages = [];

        foreach ($this->valuePages($company, $slip, $year, $contactId ?? (int) ($slip['contact_id'] ?? 0)) as $values) {
            $pages[] = $this->renderer->render($template, $map, $values);
        }

        return count($pages) === 1 ? $pages[0] : $this->merger->merge(...$pages);
    }

    /**
     * One field-value set per output page: the first carries every box; extra
     * pages carry only the identity fields plus the overflowing Other
     * Information codes (six slots per slip).
     *
     * @param  array<string, mixed>  $slip
     * @return list<array<string, string>>
     */
    private function valuePages(Company $company, array $slip, int $year, int $contactId): array
    {
        $money = fn (int $cents): string => number_format($cents / 100, 2);

        $contact = Contact::query()->withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->find($contactId);

        [$firstName, $lastName] = $this->names($contact, (string) ($slip['name'] ?? ''));

        $identity = [
            'employer_name' => $this->employerBlock($company),
            'year' => (string) $year,
            'sin' => $this->sin($company, $contactId, $slip['sin_last4'] ?? null),
            'box10' => (string) ($slip['province'] ?? ''),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'address' => $this->addressBlock($contact),
        ];

        $values = $identity;

        foreach (['box14', 'box16', 'box16a', 'box17', 'box17a', 'box18', 'box22', 'box24', 'box26', 'box55', 'box56'] as $box) {
            $cents = (int) ($slip[$box] ?? 0);

            if ($cents > 0 || in_array($box, self::ALWAYS_PRINT, true)) {
                $values[$box] = $money($cents);
            }
        }

        // Boxes with a dedicated home on the form come out of the "other"
        // bag; whatever remains fills the six Other Information slots, six
        // per page (CRA: issue an additional slip beyond six).
        $other = array_map('intval', (array) ($slip['other'] ?? []));

        foreach (['20', '44', '46', '52'] as $dedicated) {
            if (($other[$dedicated] ?? 0) !== 0) {
                $values['box'.$dedicated] = $money($other[$dedicated]);
            }

            unset($other[$dedicated]);
        }

        $otherInfo = [];

        foreach ($other as $code => $cents) {
            if ($cents !== 0) {
                $otherInfo[] = ['code' => (string) $code, 'amount' => $money($cents)];
            }
        }

        $pages = [];

        foreach (array_chunk($otherInfo, 6) as $pageIndex => $chunk) {
            $page = $pageIndex === 0 ? $values : $identity;

            foreach ($chunk as $slot => $info) {
                $page['other'.($slot + 1).'_code'] = $info['code'];
                $page['other'.($slot + 1).'_amount'] = $info['amount'];
            }

            $pages[] = $page;
        }

        return $pages === [] ? [$values] : $pages;
    }

    private function employerBlock(Company $company): string
    {
        return implode("\n", array_filter([
            $company->name,
            $company->address_line1 ?? null,
            trim(implode(' ', array_filter([$company->address_city ?? null, $company->address_region ?? null, $company->address_postal_code ?? null]))) ?: null,
        ]));
    }

    private function addressBlock(?Contact $contact): string
    {
        if ($contact === null) {
            return '';
        }

        return implode("\n", array_filter([
            $contact->billing_line1,
            $contact->billing_line2,
            trim(implode(' ', array_filter([$contact->billing_city, $contact->billing_region, $contact->billing_postal_code]))) ?: null,
        ]));
    }

    /**
     * The full SIN, decrypted from the profile at render time and formatted
     * XXX XXX XXX; the masked last-4 when the profile is gone, the SIN was
     * never set, or the ciphertext no longer decrypts (rotated APP_KEY).
     */
    private function sin(Company $company, int $contactId, ?string $last4): string
    {
        try {
            $sin = EmployeePayrollProfile::query()->withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('contact_id', $contactId)
                ->first()
                ?->sin_encrypted;
        } catch (DecryptException) {
            $sin = null;
        }

        if (is_string($sin) && preg_match('/^\d{9}$/', $sin) === 1) {
            return implode(' ', str_split($sin, 3));
        }

        return $last4 ? '••• ••• '.$last4 : '';
    }

    /**
     * The employee's name boxes — from the Contact's real first/last columns
     * when present (the same source the CRA XML files, so the paper and the
     * filing can't disagree), else split from the display name.
     *
     * @return array{0: string, 1: string}
     */
    private function names(?Contact $contact, string $displayName): array
    {
        if ($contact !== null && (string) $contact->last_name !== '') {
            return [(string) $contact->first_name, (string) $contact->last_name];
        }

        // "Last, First" display names keep their declared order.
        if (str_contains($displayName, ',')) {
            [$last, $first] = array_pad(array_map('trim', explode(',', $displayName, 2)), 2, '');

            return [$first, $last];
        }

        $parts = preg_split('/\s+/', trim($displayName)) ?: [];

        if (count($parts) <= 1) {
            return ['', $displayName];
        }

        $last = (string) array_pop($parts);

        return [implode(' ', $parts), $last];
    }
}
