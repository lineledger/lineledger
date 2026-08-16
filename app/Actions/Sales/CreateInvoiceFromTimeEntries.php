<?php

namespace App\Actions\Sales;

use App\Enums\AccountSubtype;
use App\Enums\TimeEntryStatus;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\TimeEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bills a customer's approved, billable, un-invoiced time entries as a single
 * Draft invoice — one line per entry (hours × rate). Mirrors
 * {@see ConvertEstimateToInvoice}: it reuses {@see SaveInvoice} so tax, numbering
 * and totals stay in one place, and stamps each entry's invoice_id so the same
 * time is never billed twice. No posting occurs; the user reviews the Draft.
 */
final class CreateInvoiceFromTimeEntries
{
    public function __construct(protected SaveInvoice $saveInvoice) {}

    /**
     * @param  Collection<int, TimeEntry>  $entries
     */
    public function handle(Contact $customer, Collection $entries): Invoice
    {
        $billable = $entries->filter(fn (TimeEntry $entry): bool => $entry->billable
            && $entry->status === TimeEntryStatus::Approved
            && $entry->invoice_id === null
            && (int) $entry->customer_id === (int) $customer->id);

        if ($billable->isEmpty()) {
            throw new \RuntimeException(__('No approved, billable, un-billed time for this customer.'));
        }

        return DB::transaction(function () use ($customer, $billable): Invoice {
            $company = app('current_company');

            // Last-resort income account so a missing item/customer default still
            // yields an editable Draft the user can correct before posting.
            $defaultIncomeAccountId = Account::query()
                ->where('subtype', AccountSubtype::Income->value)
                ->orderBy('code')
                ->value('id');

            // Resolve the service items once, keyed by id. Plain-array access with a
            // null fallback gives a genuinely nullable Item for the line builder.
            $itemsById = Item::query()
                ->whereIn('id', $billable->pluck('item_id')->filter()->unique()->all())
                ->get()
                ->keyBy('id')
                ->all();

            $invoice = $this->saveInvoice->handle([
                'contact_id' => $customer->id,
                'invoice_no' => null,
                'invoice_date' => $company->currentDateTime()->toDateString(),
                'due_date' => null, // SaveInvoice derives from terms_id, else invoice_date
                'terms_id' => $customer->default_terms_id,
                'lines' => $billable->map(function (TimeEntry $entry) use ($customer, $defaultIncomeAccountId, $itemsById): array {
                    // Defaults from the customer; the service item (when set, and it's a
                    // valid FK so isset is guaranteed) overrides the rate/account/tax.
                    $rateCents = $entry->billable_rate_cents;
                    $accountId = $customer->default_income_account_id ?? $defaultIncomeAccountId;
                    $taxCodeId = $customer->default_tax_code_id;
                    $secondaryTaxCodeId = null;
                    $itemName = null;

                    if ($entry->item_id !== null && isset($itemsById[$entry->item_id])) {
                        $item = $itemsById[$entry->item_id];
                        $rateCents ??= $item->default_price_cents;
                        $accountId = $item->income_account_id ?? $accountId;
                        $taxCodeId = $item->default_tax_code_id ?? $taxCodeId;
                        $secondaryTaxCodeId = $item->default_secondary_tax_code_id;
                        $itemName = $item->name;
                    }

                    return [
                        'item_id' => $entry->item_id,
                        'account_id' => $accountId,
                        'description' => ($entry->description ?: $itemName) ?: __('Time'),
                        'service_date' => $entry->date_worked->toDateString(),
                        'quantity' => (string) (float) $entry->hours,
                        'unit_price_cents' => (int) ($rateCents ?? 0),
                        'line_discount_cents' => 0,
                        'tax_code_id' => $taxCodeId,
                        'secondary_tax_code_id' => $secondaryTaxCodeId,
                        'class_id' => $entry->class_id,
                        'location_id' => $entry->location_id,
                    ];
                })->all(),
            ]);

            TimeEntry::query()
                ->whereIn('id', $billable->pluck('id')->all())
                ->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }
}
