<?php

namespace App\Support\Reporting;

use App\Enums\CustomerStatementType;
use Carbon\CarbonImmutable;

/**
 * The columns a customer-facing statement can show, per statement type. The
 * on-screen preview (customers.statement) and the PDF
 * (pdf.statements.customer-statement) both read this one registry and format
 * every cell through {@see self::cell()}, so the two can't drift.
 *
 * Fixed columns always print. Optional columns are what the Columns menu
 * toggles, what `?cols=` carries, and what invoice_settings.statement_columns
 * saves as the company default — always a list of OPTIONAL keys; fixed keys
 * are never stored or passed around.
 *
 * Keys are unique across both types (a key shared by both, like `memo`, means
 * the same field formatted the same way), so a cell formats from its key alone.
 *
 * The sales rep is deliberately absent: it is internal, and stays off every
 * customer-facing statement surface.
 */
final class StatementColumns
{
    /**
     * The `?cols=` value for "every optional column off" — an empty list can't
     * survive a query string, and an absent one means "the saved default".
     */
    public const NONE = 'none';

    /**
     * Every column for a type, in display order. `weight` sizes the PDF column
     * (see {@see self::widths()}); the defaults reproduce the original fixed
     * layout exactly.
     *
     * @return array<string, array{label: string, field: string, format: 'text'|'date'|'money'|'money_or_blank'|'days', fixed: bool, default: bool, weight: int}>
     */
    public static function definitions(CustomerStatementType $type): array
    {
        return match ($type) {
            CustomerStatementType::OpenInvoices => [
                'invoice_date' => self::column(__('Date'), 'invoice_date', 'date', 11, fixed: true),
                'invoice_no' => self::column(__('Invoice #'), 'invoice_no', 'text', 15, fixed: true),
                'po' => self::column(__('P.O. #'), 'customer_po', 'text', 12),
                'memo' => self::column(__('Memo'), 'memo', 'text', 32, default: true),
                'terms' => self::column(__('Terms'), 'terms', 'text', 10),
                'due_date' => self::column(__('Due Date'), 'due_date', 'date', 11, default: true),
                'days_past_due' => self::column(__('Days Past Due'), 'days_past_due', 'days', 9),
                'original' => self::column(__('Original Amount'), 'total', 'money', 17, default: true),
                'paid' => self::column(__('Amount Paid'), 'paid', 'money', 13),
                'balance' => self::column(__('Balance'), 'balance', 'money', 14, fixed: true),
            ],
            CustomerStatementType::Activity => [
                'date' => self::column(__('Date'), 'date', 'date', 12, fixed: true),
                'type' => self::column(__('Type'), 'type', 'text', 13, default: true),
                'doc_no' => self::column(__('Doc #'), 'doc_no', 'text', 13, fixed: true),
                'po' => self::column(__('P.O. #'), 'customer_po', 'text', 12),
                'memo' => self::column(__('Memo'), 'memo', 'text', 23, default: true),
                'charges' => self::column(__('Charges'), 'debit', 'money_or_blank', 13, fixed: true),
                'payments' => self::column(__('Payments'), 'credit', 'money_or_blank', 13, fixed: true),
                'running' => self::column(__('Balance'), 'running', 'money', 13, fixed: true),
            ],
        };
    }

    /**
     * The toggleable columns, key => label, in display order.
     *
     * @return array<string, string>
     */
    public static function optional(CustomerStatementType $type): array
    {
        $optional = [];

        foreach (self::definitions($type) as $key => $column) {
            if (! $column['fixed']) {
                $optional[$key] = $column['label'];
            }
        }

        return $optional;
    }

    /**
     * The optional columns shown when nothing is requested or saved.
     *
     * @return list<string>
     */
    public static function defaults(CustomerStatementType $type): array
    {
        $keys = [];

        foreach (self::definitions($type) as $key => $column) {
            if (! $column['fixed'] && $column['default']) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Keep only this type's known optional keys, in display order, once each.
     *
     * @param  array<int|string, mixed>  $keys
     * @return list<string>
     */
    public static function sanitize(CustomerStatementType $type, array $keys): array
    {
        $wanted = array_map(fn (mixed $key): string => is_scalar($key) ? trim((string) $key) : '', $keys);

        return array_values(array_filter(
            array_keys(self::optional($type)),
            fn (string $key): bool => in_array($key, $wanted, true),
        ));
    }

    /**
     * The optional columns in effect: the requested ones when given, else the
     * company's saved default, else the registry defaults.
     *
     * @param  array<int|string, mixed>|null  $requested
     * @param  array<int|string, mixed>|null  $saved
     * @return list<string>
     */
    public static function chosen(CustomerStatementType $type, ?array $requested, ?array $saved): array
    {
        if ($requested !== null) {
            return self::sanitize($type, $requested);
        }

        if ($saved !== null) {
            return self::sanitize($type, $saved);
        }

        return self::defaults($type);
    }

    /**
     * Every column to render — fixed plus chosen optional — in display order.
     *
     * @param  array<int|string, mixed>|null  $requested
     * @param  array<int|string, mixed>|null  $saved
     * @return list<string>
     */
    public static function visible(CustomerStatementType $type, ?array $requested, ?array $saved): array
    {
        $chosen = self::chosen($type, $requested, $saved);

        $keys = [];

        foreach (self::definitions($type) as $key => $column) {
            if ($column['fixed'] || in_array($key, $chosen, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Parse a `?cols=` value: null when absent or blank (use the saved
     * default), [] for {@see self::NONE}, else the comma-separated keys. Not
     * sanitized against a type — pass the result through {@see self::chosen()}.
     *
     * @return list<string>|null
     */
    public static function parse(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (trim($raw) === self::NONE) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw)), fn (string $key): bool => $key !== ''));
    }

    /**
     * The `?cols=` value for a list of optional keys (inverse of {@see self::parse()}).
     *
     * @param  list<string>  $keys
     */
    public static function encode(array $keys): string
    {
        return $keys === [] ? self::NONE : implode(',', $keys);
    }

    public static function label(CustomerStatementType $type, string $key): string
    {
        return self::definitions($type)[$key]['label'] ?? $key;
    }

    /**
     * Whether the column holds a number (right-aligned, monospaced).
     */
    public static function isNumeric(string $key): bool
    {
        return in_array(self::definition($key)['format'] ?? 'text', ['money', 'money_or_blank', 'days'], true);
    }

    /**
     * PDF column widths for the visible columns, key => CSS width. Memo is
     * left null so it absorbs whatever the fixed columns leave over; the rest
     * share 100% in proportion to their weights.
     *
     * @param  list<string>  $visible
     * @return array<string, ?string>
     */
    public static function widths(CustomerStatementType $type, array $visible): array
    {
        $definitions = self::definitions($type);
        $total = array_sum(array_map(fn (string $key): int => $definitions[$key]['weight'] ?? 0, $visible));

        $widths = [];

        foreach ($visible as $key) {
            if ($key === 'memo' || $total === 0) {
                $widths[$key] = null;

                continue;
            }

            $percent = round(($definitions[$key]['weight'] ?? 0) / $total * 100, 1);
            $widths[$key] = (floor($percent) === $percent ? (string) (int) $percent : number_format($percent, 1, '.', '')).'%';
        }

        return $widths;
    }

    /**
     * One formatted cell of a statement row — the single formatter the preview
     * and the PDF share. Dates print n/j/Y, money as a plain 2-decimal amount.
     *
     * @param  array<string, mixed>  $row
     */
    public static function cell(string $key, array $row): string
    {
        $definition = self::definition($key);

        if ($definition === null) {
            return '';
        }

        $value = $row[$definition['field']] ?? null;

        return match ($definition['format']) {
            'date' => is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->format('n/j/Y') : '—',
            'money' => self::money((int) $value),
            'money_or_blank' => (int) $value !== 0 ? self::money((int) $value) : '',
            'days' => (int) $value > 0 ? (string) (int) $value : '',
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    public static function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }

    /**
     * A key's definition from whichever type declares it.
     *
     * @return array{label: string, field: string, format: string, fixed: bool, default: bool, weight: int}|null
     */
    private static function definition(string $key): ?array
    {
        foreach (CustomerStatementType::cases() as $type) {
            $definitions = self::definitions($type);

            if (isset($definitions[$key])) {
                return $definitions[$key];
            }
        }

        return null;
    }

    /**
     * @param  'text'|'date'|'money'|'money_or_blank'|'days'  $format
     * @return array{label: string, field: string, format: 'text'|'date'|'money'|'money_or_blank'|'days', fixed: bool, default: bool, weight: int}
     */
    private static function column(string $label, string $field, string $format, int $weight, bool $fixed = false, bool $default = false): array
    {
        return [
            'label' => $label,
            'field' => $field,
            'format' => $format,
            'fixed' => $fixed,
            'default' => $fixed || $default,
            'weight' => $weight,
        ];
    }
}
