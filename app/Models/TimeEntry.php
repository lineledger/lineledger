<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\TimeEntryStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A logged block of work: one employee, one day, some hours. Feeds payroll (the
 * employee's hours) and invoicing (billable time to a customer). `pay_run_id` and
 * `invoice_id` mark consumption so neither flow double-counts an entry.
 *
 * @property int $id
 * @property int $company_id
 * @property int $contact_id
 * @property CarbonInterface $date_worked
 * @property string $hours
 * @property string $pay_code
 * @property ?string $description
 * @property bool $billable
 * @property ?int $customer_id
 * @property ?int $item_id
 * @property ?int $billable_rate_cents
 * @property ?int $class_id
 * @property ?int $location_id
 * @property TimeEntryStatus $status
 * @property ?int $pay_run_id
 * @property ?int $invoice_id
 * @property ?int $time_off_request_id
 * @property-read ?Contact $employee
 * @property-read ?Contact $customer
 * @property-read ?Item $item
 * @property-read ?Classification $classification
 * @property-read ?Location $location
 * @property-read ?PayRun $payRun
 * @property-read ?Invoice $invoice
 */
#[Fillable([
    'company_id', 'contact_id', 'date_worked', 'hours', 'pay_code', 'description', 'billable',
    'customer_id', 'item_id', 'billable_rate_cents', 'class_id', 'location_id',
    'status', 'pay_run_id', 'invoice_id', 'time_off_request_id',
])]
class TimeEntry extends Model
{
    use BelongsToCompany, HasFactory;

    /**
     * The employee who logged the time. Keyed on contact_id to match payroll
     * (PayRunLine.contact_id), so the payroll pull joins cleanly.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<Classification, $this>
     */
    public function classification(): BelongsTo
    {
        return $this->belongsTo(Classification::class, 'class_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<PayRun, $this>
     */
    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The approved time-off request this entry was generated from, if any.
     *
     * @return BelongsTo<TimeOffRequest, $this>
     */
    public function timeOffRequest(): BelongsTo
    {
        return $this->belongsTo(TimeOffRequest::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_worked' => 'date:Y-m-d',
            'hours' => 'decimal:2',
            'billable' => 'boolean',
            'billable_rate_cents' => 'integer',
            'status' => TimeEntryStatus::class,
        ];
    }
}
