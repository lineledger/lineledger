<?php

namespace App\Support\Mcp;

use App\Models\BillLine;
use App\Models\InvoiceLine;
use App\Models\TaxCode;
use App\Support\Money;

/**
 * Presents one invoice or bill line for the MCP tools, as a text row and as
 * an array for JSON output. Invoice and bill lines share these columns, so
 * the invoice and bill tools render lines identically.
 *
 * The line's own description wins over the item name — it is what was typed
 * on the document ("Airfare for the family" on a generic disbursement item).
 * Amounts are in the document's currency; JSON amounts are exact decimal
 * strings ("3495.00"), never floats.
 */
final class DocumentLinePresenter
{
    /**
     * The relations present() reads; eager-load them with the lines.
     *
     * @var list<string>
     */
    public const RELATIONS = ['item', 'account', 'taxCode', 'secondaryTaxCode', 'classification', 'location'];

    public function __construct(private readonly string $currency) {}

    /**
     * @return array{
     *   line: int, description: string, item: ?string, item_id: ?int, sku: ?string,
     *   quantity: string, unit_price: string, discount: ?string, amount: string,
     *   tax_codes: list<string>, tax_flags: string, tax: string, total: string,
     *   account: ?string, account_id: ?int, section: string, class: ?string, location: ?string,
     * }
     */
    public function toArray(InvoiceLine|BillLine $line, int $number): array
    {
        $discount = (int) $line->line_discount_cents;

        return [
            'line' => $number,
            'description' => $this->description($line),
            'item' => $line->item?->name,
            'item_id' => $line->item_id,
            'sku' => $line->item?->sku,
            'quantity' => self::trimDecimal($line->quantity),
            'unit_price' => $this->decimal((int) $line->unit_price_cents),
            'discount' => $discount !== 0 ? $this->decimal($discount) : null,
            'amount' => $this->decimal((int) $line->line_subtotal_cents),
            'tax_codes' => $this->taxCodes($line),
            'tax_flags' => TaxFlags::for($line->taxCode, $line->secondaryTaxCode),
            'tax' => $this->decimal($this->taxCents($line)),
            'total' => $this->decimal((int) $line->line_total_cents),
            'account' => $line->account !== null ? trim("{$line->account->code} {$line->account->name}") : null,
            'account_id' => $line->account_id,
            'section' => StatementSections::for($line)->value,
            'class' => $line->classification?->name,
            'location' => $line->location?->name,
        ];
    }

    /**
     * "3. Urn (Oak) — 1 × $350.00 = $350.00 | GST, PST-BC (GP) | item: Urn - Oak (API id 12) | account: 4530 Urns | services"
     */
    public function toText(InvoiceLine|BillLine $line, int $number): string
    {
        $row = $this->toArray($line, $number);

        $parts = [sprintf(
            '%d. %s — %s × %s = %s',
            $number,
            $row['description'],
            $row['quantity'],
            $this->format((int) $line->unit_price_cents),
            $this->format((int) $line->line_subtotal_cents),
        )];

        if ($row['discount'] !== null) {
            $parts[0] .= ' (after a '.$this->format((int) $line->line_discount_cents).' discount)';
        }

        $parts[] = ($row['tax_codes'] === [] ? 'no tax' : implode(', ', $row['tax_codes'])).' ('.$row['tax_flags'].')';

        if ($row['item'] !== null) {
            $parts[] = 'item: '.$row['item'].($row['sku'] ? " [{$row['sku']}]" : '')." (API id {$row['item_id']})";
        }

        if ($row['account'] !== null) {
            $parts[] = 'account: '.$row['account'];
        }

        $parts[] = $row['section'];

        foreach (['class', 'location'] as $tag) {
            if ($row[$tag] !== null) {
                $parts[] = "{$tag}: {$row[$tag]}";
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * What the line says: its own description, else the item's name.
     */
    public function description(InvoiceLine|BillLine $line): string
    {
        $text = trim((string) $line->description);

        if ($text !== '') {
            return $text;
        }

        return $line->item->name ?? '(no description)';
    }

    /**
     * The line's tax in the document currency: the primary tax plus any
     * secondary tax (GST + PST on one line).
     */
    public function taxCents(InvoiceLine|BillLine $line): int
    {
        return (int) $line->line_tax_cents + (int) $line->secondary_tax_cents;
    }

    /**
     * @return list<string>
     */
    private function taxCodes(InvoiceLine|BillLine $line): array
    {
        return array_values(array_filter(
            [$line->taxCode?->code, $line->secondaryTaxCode?->code],
            fn (?string $code): bool => $code !== null && $code !== '',
        ));
    }

    public function format(int $cents): string
    {
        return Money::fromCents($cents, $this->currency)->format();
    }

    public function decimal(int $cents): string
    {
        return Money::fromCents($cents, $this->currency)->toDecimalString();
    }

    /**
     * "2", "1.5", "1.35" — a stored decimal (quantity, FX rate) without trailing zeros.
     */
    public static function trimDecimal(mixed $quantity): string
    {
        $text = (string) ($quantity ?? '0');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /**
     * Rate of a tax code as a percentage string: 500 basis points → "5".
     */
    public static function ratePercent(TaxCode $code): string
    {
        $bp = (int) $code->rate_basis_points;
        $whole = intdiv($bp, 100);
        $fraction = rtrim(str_pad((string) ($bp % 100), 2, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : "{$whole}.{$fraction}";
    }
}
