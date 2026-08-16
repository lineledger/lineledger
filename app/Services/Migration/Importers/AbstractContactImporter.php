<?php

namespace App\Services\Migration\Importers;

use App\Models\Contact;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use Illuminate\Support\Facades\DB;
use Throwable;

abstract class AbstractContactImporter implements Importer
{
    /** Rows returned for on-screen preview; the rest are still counted/imported. */
    protected const PREVIEW_LIMIT = 200;

    abstract protected function role(): string; // 'customer' or 'vendor'

    public function __construct(protected CsvParser $parser) {}

    public function templateHeaders(): array
    {
        return [
            'display_name', 'company_name', 'first_name', 'last_name',
            'email', 'phone', 'tax_number',
            'billing_line1', 'billing_line2', 'billing_city', 'billing_region', 'billing_postal_code', 'billing_country',
            'shipping_line1', 'shipping_line2', 'shipping_city', 'shipping_region', 'shipping_postal_code', 'shipping_country',
            'notes',
        ];
    }

    public function templateExampleRows(): array
    {
        return [[
            'display_name' => $this->role() === 'customer' ? 'Acme Construction Ltd.' : 'Office Supply Co.',
            'company_name' => $this->role() === 'customer' ? 'Acme Construction Ltd.' : 'Office Supply Co.',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '555-0100',
            'tax_number' => '',
            'billing_line1' => '123 Main St',
            'billing_line2' => '',
            'billing_city' => 'Toronto',
            'billing_region' => 'ON',
            'billing_postal_code' => 'M5V 0J3',
            'billing_country' => 'CA',
            'shipping_line1' => '',
            'shipping_line2' => '',
            'shipping_city' => '',
            'shipping_region' => '',
            'shipping_postal_code' => '',
            'shipping_country' => '',
            'notes' => '',
        ]];
    }

    public function preview(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, dryRun: true);
    }

    public function commit(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, dryRun: false);
    }

    protected function run(string $csvPath, ImportContext $ctx, bool $dryRun): ImportResult
    {
        try {
            $rows = $this->normalizedRows($csvPath);
        } catch (Throwable $e) {
            return new ImportResult(isDryRun: $dryRun, previewRows: [], errors: [['row' => 0, 'message' => $e->getMessage()]]);
        }

        $errors = [];
        $preview = [];
        $createdIds = [];
        $created = 0;
        $merged = 0;

        $runner = function () use ($rows, $ctx, &$errors, &$preview, &$createdIds, &$created, &$merged, $dryRun): void {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2; // +1 for 1-based, +1 for header

                if (! $row['display_name']) {
                    $errors[] = ['row' => $rowNum, 'message' => 'display_name is required.'];

                    continue;
                }

                $existing = Contact::withoutGlobalScopes()
                    ->where('company_id', $ctx->company->id)
                    ->where('display_name', $row['display_name'])
                    ->first();

                $action = $existing ? 'merge' : 'create';

                if (count($preview) < self::PREVIEW_LIMIT) {
                    $preview[] = [
                        'row' => $rowNum,
                        'display_name' => $row['display_name'],
                        'email' => $row['email'],
                        'action' => $action,
                    ];
                }

                if ($dryRun) {
                    continue;
                }

                if ($existing) {
                    $existing->forceFill([
                        $this->role() === 'customer' ? 'is_customer' : 'is_vendor' => true,
                    ])->save();
                    $merged++;
                    $createdIds[] = $existing->id;

                    continue;
                }

                $contact = Contact::withoutGlobalScopes()->create([
                    'company_id' => $ctx->company->id,
                    'display_name' => $row['display_name'],
                    'company_name' => $row['company_name'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                    'phone' => $row['phone'],
                    'tax_number' => $row['tax_number'],
                    'is_customer' => $this->role() === 'customer',
                    'is_vendor' => $this->role() === 'vendor',
                    'is_employee' => false,
                    'billing_line1' => $row['billing_line1'],
                    'billing_line2' => $row['billing_line2'],
                    'billing_city' => $row['billing_city'],
                    'billing_region' => $row['billing_region'],
                    'billing_postal_code' => $row['billing_postal_code'],
                    'billing_country' => $row['billing_country'],
                    'shipping_line1' => $row['shipping_line1'],
                    'shipping_line2' => $row['shipping_line2'],
                    'shipping_city' => $row['shipping_city'],
                    'shipping_region' => $row['shipping_region'],
                    'shipping_postal_code' => $row['shipping_postal_code'],
                    'shipping_country' => $row['shipping_country'],
                    'notes' => $row['notes'],
                    'is_active' => $row['is_active'] ?? true,
                ]);

                $created++;
                $createdIds[] = $contact->id;
            }
        };

        if ($dryRun) {
            $runner();
        } else {
            try {
                DB::transaction($runner);
            } catch (Throwable $e) {
                $errors[] = ['row' => 0, 'message' => 'Import aborted: '.$e->getMessage()];
            }
        }

        return new ImportResult(
            isDryRun: $dryRun,
            previewRows: $preview,
            errors: $errors,
            createdIds: $createdIds,
            summary: ['created' => $created, 'merged' => $merged, 'rows' => count($rows)],
        );
    }

    /**
     * Read either the native template (display_name, …) or a QuickBooks "Customer
     * List" / "Vendor List" export into a uniform row shape. Skips QuickBooks' BOM,
     * leading blank column, and cp1252 encoding.
     *
     * @return list<array<string, mixed>>
     */
    protected function normalizedRows(string $csvPath): array
    {
        $handle = @fopen($csvPath, 'r');

        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV file at: {$csvPath}");
        }

        try {
            $cols = null;
            $isQuickBooks = false;

            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }

                $map = $this->headerMap($row);
                $native = isset($map['display_name']);
                $quickBooks = isset($map['customer']) || isset($map['vendor']);

                if ($native || $quickBooks) {
                    $cols = $map;
                    $isQuickBooks = ! $native && $quickBooks;
                    break;
                }
            }

            if ($cols === null) {
                throw new \RuntimeException('Could not find a header row. Use the template columns (display_name, …) or a QuickBooks "Customer List" / "Vendor List" export (with a Customer or Vendor column).');
            }

            $base = array_fill_keys($this->templateHeaders(), null);
            $rows = [];

            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                if ($cells === [null] || $cells === false) {
                    continue;
                }

                $get = fn (string $key): ?string => $this->cell($cells, $cols, $key);

                $row = $isQuickBooks
                    ? $this->mapQuickBooksRow($get, $cols)
                    : array_map(fn (string $h) => $get($h), array_combine($this->templateHeaders(), $this->templateHeaders()));

                $row = array_merge($base, ['is_active' => true], $row);

                if (($row['display_name'] ?? null) === null) {
                    continue; // skip total/blank rows with no name
                }

                $rows[] = $row;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Map a QuickBooks Customer/Vendor List row to contact fields. Customers use
     * "Invoice to"/"Ship to" address blocks; vendors use "Bill from"/"Ship from".
     *
     * @param  callable(string):?string  $get
     * @param  array<string, int>  $cols
     * @return array<string, mixed>
     */
    protected function mapQuickBooksRow(callable $get, array $cols): array
    {
        $billPrefix = isset($cols['bill from 1']) ? 'bill from ' : 'invoice to ';
        $shipPrefix = isset($cols['ship from 1']) ? 'ship from ' : 'ship to ';

        [$billingLine1, $billingLine2] = $this->qbAddressLines($get, $billPrefix);
        [$shippingLine1, $shippingLine2] = $this->qbAddressLines($get, $shipPrefix);

        return [
            'display_name' => $get('customer') ?? $get('vendor'),
            'company_name' => $get('company'),
            'first_name' => $get('first name'),
            'last_name' => $get('last name'),
            'email' => $get('main email'),
            'phone' => $get('main phone') ?? $get('alt. phone'),
            'tax_number' => $get('business number'),
            'billing_line1' => $billingLine1,
            'billing_line2' => $billingLine2,
            'billing_country' => $this->qbCountry($get('tax country')),
            'shipping_line1' => $shippingLine1,
            'shipping_line2' => $shippingLine2,
            'is_active' => strtolower((string) $get('active status')) !== 'inactive',
        ];
    }

    /**
     * Map a QuickBooks country name to the 2-letter code the contacts table stores.
     * Unknown longer values are dropped rather than overflowing the column.
     */
    protected function qbCountry(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (strtolower(trim($value))) {
            'canada', 'ca', 'can' => 'CA',
            'united states', 'united states of america', 'usa', 'us' => 'US',
            default => mb_strlen($value) <= 2 ? strtoupper($value) : null,
        };
    }

    /**
     * Collapse a QuickBooks 5-line freeform address block into line1 + a joined line2.
     *
     * @param  callable(string):?string  $get
     * @return array{0: ?string, 1: ?string}
     */
    protected function qbAddressLines(callable $get, string $prefix): array
    {
        $line1 = $get($prefix.'1');
        $rest = array_filter([$get($prefix.'2'), $get($prefix.'3'), $get($prefix.'4'), $get($prefix.'5')]);

        return [$line1, $rest === [] ? null : implode(', ', $rest)];
    }

    /**
     * @param  array<int, ?string>  $row
     * @return array<string, int>
     */
    protected function headerMap(array $row): array
    {
        $map = [];

        foreach ($row as $i => $cell) {
            $header = strtolower(trim($this->toUtf8((string) ($cell ?? ''))));

            if ($header !== '' && ! isset($map[$header])) {
                $map[$header] = $i;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, ?string>  $cells
     * @param  array<string, int>  $cols
     */
    protected function cell(array $cells, array $cols, string $key): ?string
    {
        if (! isset($cols[$key])) {
            return null;
        }

        $value = $cells[$cols[$key]] ?? null;
        $value = $value === null ? '' : trim($this->toUtf8((string) $value));

        return $value === '' ? null : $value;
    }

    /**
     * QuickBooks exports are usually Windows-1252; convert to UTF-8 and drop any BOM.
     */
    protected function toUtf8(string $value): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
        }

        if (str_starts_with($value, "\u{FEFF}")) {
            $value = substr($value, 3);
        }

        return mb_scrub($value, 'UTF-8');
    }
}
