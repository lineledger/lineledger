<?php

namespace App\Actions\Recurring;

use App\Enums\BillType;
use App\Enums\RecurrenceEndType;
use App\Enums\RecurrenceFrequency;
use App\Enums\RecurringAutomationMode;
use App\Enums\RecurringDocumentType;
use App\Models\RecurringDocument;
use App\Services\Recurring\NextRunDateCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Builds or updates a recurring document template and its line items. Stores the
 * raw line template only — totals/tax are recomputed at generation time so each
 * generated draft reflects the tax rates in force when it is produced.
 *
 * Expected $data shape:
 *   document_type:    string  'invoice'|'bill'
 *   contact_id:       int
 *   bill_type:        ?string 'vendor'|'reimbursement' (bills only)
 *   vendor_reference: ?string
 *   terms_id:         ?int
 *   memo:             ?string
 *   name:             ?string
 *   frequency:        string
 *   start_date:       string
 *   day_of_month:     ?int     (monthly+ cadences)
 *   end_type:         string   'never'|'on_date'|'after_occurrences'
 *   end_date:         ?string
 *   max_occurrences:  ?int
 *   lines: array<int, array{
 *     item_id: ?int, account_id: int, description: ?string,
 *     quantity: string|int|float, unit_price_cents: int, tax_code_id: ?int
 *   }>
 */
final class SaveRecurringDocument
{
    public function __construct(
        protected NextRunDateCalculator $calculator,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, ?RecurringDocument $document = null): RecurringDocument
    {
        return DB::transaction(function () use ($data, $document): RecurringDocument {
            $type = RecurringDocumentType::from($data['document_type']);
            $frequency = RecurrenceFrequency::from($data['frequency']);
            $endType = RecurrenceEndType::from($data['end_type']);

            $header = [
                'document_type' => $type,
                // Automation is an invoice-only feature; bill schedules always draft.
                'automation_mode' => $type === RecurringDocumentType::Invoice
                    ? RecurringAutomationMode::from($data['automation_mode'] ?? RecurringAutomationMode::Draft->value)
                    : RecurringAutomationMode::Draft,
                'contact_id' => $data['contact_id'],
                'bill_type' => $type === RecurringDocumentType::Bill
                    ? BillType::from($data['bill_type'] ?? BillType::Vendor->value)
                    : null,
                'vendor_reference' => $data['vendor_reference'] ?? null,
                'terms_id' => $data['terms_id'] ?? null,
                'memo' => $data['memo'] ?? null,
                'name' => $data['name'] ?? null,
                'frequency' => $frequency,
                'start_date' => $data['start_date'],
                'day_of_month' => $frequency->usesDayOfMonth() ? ($data['day_of_month'] ?? null) : null,
                'end_type' => $endType,
                'end_date' => $endType === RecurrenceEndType::OnDate ? ($data['end_date'] ?? null) : null,
                'max_occurrences' => $endType === RecurrenceEndType::AfterOccurrences
                    ? ($data['max_occurrences'] ?? null)
                    : null,
            ];

            if ($document && $document->exists) {
                $document->update($header);
            } else {
                $document = RecurringDocument::create($header + [
                    'occurrences_generated' => 0,
                    'is_active' => true,
                ]);
            }

            // The upcoming run date is only (re)anchored before the first
            // generation. Once a schedule is running we leave the next date
            // fixed; later hops automatically adopt any changed cadence because
            // NextRunDateCalculator reads the current frequency/anchor.
            if ((int) $document->occurrences_generated === 0) {
                $document->next_run_date = $this->calculator->first($document)->toDateString();
                $document->save();
            }

            $document->lines()->delete();

            foreach (array_values($data['lines']) as $index => $line) {
                $document->lines()->create([
                    'company_id' => $document->company_id,
                    'item_id' => $line['item_id'] ?? null,
                    'account_id' => $line['account_id'],
                    'description' => $line['description'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_price_cents' => (int) $line['unit_price_cents'],
                    'tax_code_id' => $line['tax_code_id'] ?? null,
                    'secondary_tax_code_id' => $line['secondary_tax_code_id'] ?? null,
                    'line_order' => $index,
                ]);
            }

            return $document->refresh();
        });
    }
}
