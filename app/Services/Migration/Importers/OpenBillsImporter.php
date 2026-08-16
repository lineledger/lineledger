<?php

namespace App\Services\Migration\Importers;

use App\Enums\AccountSubtype;
use App\Enums\BillStatus;
use App\Enums\BillType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Contact;
use App\Services\Migration\Csv\CsvParser;
use App\Services\Migration\ImportContext;
use App\Services\Migration\ImportResult;
use App\Services\Posting\BillPoster;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports open (unpaid / partially-paid) vendor bills at conversion date.
 *
 * Each row becomes a Bill with is_opening_balance=true. The synthetic
 * single line targets Opening Balance Equity, so the existing BillPoster
 * naturally posts:
 *   DR Opening Balance Equity       balance_remaining
 *   CR Accounts Payable             balance_remaining
 *
 * No expense or tax is recognised — those events happened in QB.
 */
class OpenBillsImporter implements Importer
{
    public function __construct(
        protected CsvParser $parser,
        protected BillPoster $billPoster,
    ) {}

    public function templateHeaders(): array
    {
        return ['vendor_display_name', 'bill_no', 'vendor_reference', 'bill_date', 'due_date', 'balance_remaining', 'memo'];
    }

    public function templateExampleRows(): array
    {
        return [[
            'vendor_display_name' => 'Office Supply Co.',
            'bill_no' => 'BILL-507',
            'vendor_reference' => 'PO-1042',
            'bill_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'balance_remaining' => '425.00',
            'memo' => 'Carried over from QuickBooks',
        ]];
    }

    public function preview(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, true);
    }

    public function commit(string $csvPath, ImportContext $ctx): ImportResult
    {
        return $this->run($csvPath, $ctx, false);
    }

    protected function run(string $csvPath, ImportContext $ctx, bool $dryRun): ImportResult
    {
        $rows = $this->parser->parse($csvPath, ['vendor_display_name', 'bill_no', 'balance_remaining'], $this->templateHeaders());
        $errors = [];
        $preview = [];
        $createdIds = [];
        $created = 0;
        $totalCents = 0;

        $obe = $this->openingBalanceEquityAccount($ctx->company->id);

        if (! $obe) {
            return new ImportResult(
                isDryRun: $dryRun,
                previewRows: [],
                errors: [['row' => 0, 'message' => "Missing 'Opening Balance Equity' account."]],
            );
        }

        $contactByName = Contact::withoutGlobalScopes()
            ->where('company_id', $ctx->company->id)
            ->where('is_vendor', true)
            ->get(['id', 'display_name'])
            ->keyBy('display_name');

        $runner = function () use ($rows, $ctx, $obe, $contactByName, &$errors, &$preview, &$createdIds, &$created, &$totalCents, $dryRun): void {
            foreach ($rows as $i => $row) {
                $rowNum = $i + 2;
                $name = $row['vendor_display_name'];
                $billNo = $row['bill_no'];
                $balanceCents = CsvParser::parseCents($row['balance_remaining']);

                if (! $name || ! $billNo || $balanceCents === null) {
                    $errors[] = ['row' => $rowNum, 'message' => 'vendor_display_name, bill_no and balance_remaining are required.'];

                    continue;
                }

                if ($balanceCents <= 0) {
                    $errors[] = ['row' => $rowNum, 'message' => 'balance_remaining must be greater than zero.'];

                    continue;
                }

                $contact = $contactByName->get($name);

                if (! $contact) {
                    $errors[] = ['row' => $rowNum, 'message' => "Vendor '{$name}' not found. Import vendors before open bills."];

                    continue;
                }

                $billDate = $ctx->useOriginalDates && $row['bill_date']
                    ? CarbonImmutable::parse($row['bill_date'])
                    : $ctx->conversionDate;

                $dueDate = $row['due_date']
                    ? CarbonImmutable::parse($row['due_date'])
                    : $billDate;

                $preview[] = [
                    'row' => $rowNum,
                    'vendor' => $name,
                    'bill_no' => $billNo,
                    'bill_date' => $billDate->toDateString(),
                    'balance' => CsvParser::centsLabel($balanceCents),
                ];

                $totalCents += $balanceCents;

                if ($dryRun) {
                    continue;
                }

                $bill = Bill::withoutGlobalScopes()->create([
                    'company_id' => $ctx->company->id,
                    'contact_id' => $contact->id,
                    'bill_type' => BillType::Vendor,
                    'bill_no' => $billNo,
                    'vendor_reference' => $row['vendor_reference'],
                    'bill_date' => $billDate,
                    'due_date' => $dueDate,
                    'status' => BillStatus::Draft,
                    'subtotal_cents' => $balanceCents,
                    'tax_cents' => 0,
                    'total_cents' => $balanceCents,
                    'amount_paid_cents' => 0,
                    'memo' => $row['memo'] ?? 'Opening balance — carried over from QuickBooks',
                    'is_opening_balance' => true,
                ]);

                $bill->lines()->create([
                    'account_id' => $obe->id,
                    'description' => "Opening balance — QB bill {$billNo}",
                    'quantity' => '1.0000',
                    'unit_price_cents' => $balanceCents,
                    'line_subtotal_cents' => $balanceCents,
                    'line_tax_cents' => 0,
                    'line_total_cents' => $balanceCents,
                    'line_order' => 0,
                ]);

                $this->billPoster->post($bill->fresh());

                $created++;
                $createdIds[] = $bill->id;
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
            summary: [
                'created' => $created,
                'rows' => count($rows),
                'total_ap_cents' => $totalCents,
            ],
        );
    }

    protected function openingBalanceEquityAccount(int $companyId): ?Account
    {
        return Account::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('subtype', AccountSubtype::Equity->value)
            ->whereIn('name', Account::OPENING_BALANCE_NAMES)
            ->first();
    }
}
