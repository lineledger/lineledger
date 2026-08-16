<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * One step of a company's automated payment-reminder schedule: an offset
 * (relative to an invoice's due date) at which an email goes out, with the
 * subject/body to use. Companies start from a sensible four-tier default the
 * first time reminders run; the rows are then editable per company.
 */
#[Fillable([
    'company_id',
    'offset_days',
    'subject',
    'body',
    'is_active',
    'tier_order',
])]
class ReminderTier extends Model
{
    use BelongsToCompany;

    /**
     * The default four-tier ladder: a heads-up before the due date, then three
     * escalating overdue notices. Subjects/bodies accept :invoice, :amount,
     * :due, and :company placeholders.
     *
     * @var list<array{offset_days: int, tier_order: int, subject: string, body: string}>
     */
    public const DEFAULTS = [
        ['offset_days' => -3, 'tier_order' => 0, 'subject' => 'Invoice :invoice is due soon', 'body' => 'This is a friendly reminder that invoice :invoice for :amount is due on :due.'],
        ['offset_days' => 1, 'tier_order' => 1, 'subject' => 'Invoice :invoice is past due', 'body' => 'Invoice :invoice for :amount was due on :due. If you have already paid, please disregard this notice.'],
        ['offset_days' => 7, 'tier_order' => 2, 'subject' => 'Second reminder: invoice :invoice', 'body' => 'Invoice :invoice for :amount is now overdue. We would appreciate payment at your earliest convenience.'],
        ['offset_days' => 14, 'tier_order' => 3, 'subject' => 'Final notice: invoice :invoice', 'body' => 'Invoice :invoice for :amount remains unpaid and is significantly overdue. Please arrange payment promptly.'],
    ];

    protected function casts(): array
    {
        return [
            'offset_days' => 'integer',
            'is_active' => 'boolean',
            'tier_order' => 'integer',
        ];
    }

    /**
     * The company's active tiers (ordered), seeding the defaults the first time
     * a company has none. Idempotent — only creates when the company is empty.
     *
     * @return Collection<int, ReminderTier>
     */
    public static function ensureDefaultsFor(Company $company): Collection
    {
        $existing = self::query()->where('company_id', $company->id)->count();

        if ($existing === 0) {
            foreach (self::DEFAULTS as $tier) {
                self::create($tier + ['company_id' => $company->id, 'is_active' => true]);
            }
        }

        return self::query()
            ->where('company_id', $company->id)
            ->where('is_active', true)
            ->orderBy('tier_order')
            ->get();
    }

    public function renderSubject(Invoice $invoice): string
    {
        return $this->fillPlaceholders($this->subject, $invoice);
    }

    public function renderBody(Invoice $invoice): string
    {
        return $this->fillPlaceholders($this->body, $invoice);
    }

    private function fillPlaceholders(string $template, Invoice $invoice): string
    {
        $company = $invoice->company;
        $amount = trim(number_format($invoice->balanceCents() / 100, 2).' '.($company->currency_code ?? ''));
        $due = $invoice->due_date ? CarbonImmutable::parse($invoice->due_date)->toDateString() : __('on receipt');

        return strtr($template, [
            ':invoice' => (string) $invoice->invoice_no,
            ':amount' => $amount,
            ':due' => $due,
            ':company' => (string) ($company->brand_name ?: $company->name),
        ]);
    }
}
