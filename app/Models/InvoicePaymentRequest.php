<?php

namespace App\Models;

use App\Concerns\BelongsToCompany;
use App\Enums\PaymentRequestStatus;
use App\Enums\PaymentRequestType;
use App\Services\Sales\PaymentRequestScheduleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One milestone (deposit / progress) payment request on an invoice. The amount
 * is resolved to cents at save time; the row's stored status is Requested or
 * Cancelled, while Paid is computed from the invoice's cumulative payments by
 * {@see PaymentRequestScheduleStatus}.
 *
 * @property int $id
 * @property int $company_id
 * @property int $invoice_id
 * @property string $label
 * @property PaymentRequestType $request_type
 * @property string|null $percent
 * @property int $amount_cents
 * @property Carbon|null $due_date
 * @property PaymentRequestStatus $status
 * @property int $sort_order
 */
#[Fillable([
    'company_id',
    'invoice_id',
    'label',
    'request_type',
    'percent',
    'amount_cents',
    'due_date',
    'status',
    'sort_order',
])]
class InvoicePaymentRequest extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'request_type' => PaymentRequestType::class,
            'status' => PaymentRequestStatus::class,
            'percent' => 'decimal:4',
            'amount_cents' => 'integer',
            'due_date' => 'date:Y-m-d',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
