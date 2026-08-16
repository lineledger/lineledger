<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\FulfillSalesOrder;
use App\Actions\Sales\SaveSalesOrder;
use App\Enums\SalesOrderStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\FulfillSalesOrderRequest;
use App\Http\Requests\Api\V1\StoreSalesOrderRequest;
use App\Http\Requests\Api\V1\UpdateSalesOrderRequest;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Http\Resources\Api\V1\SalesOrderResource;
use App\Models\Invoice;
use App\Models\SalesOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesOrderController extends ApiController
{
    use AppliesApiListFilters;

    /**
     * Eager-loads the linked invoices so derived fulfillment status and per-line
     * backordered amounts compute without N+1 queries.
     */
    private const WITH = ['lines.invoiceLines.invoice'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SalesOrder::query()->with(self::WITH);

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'order_date',
            'search' => ['order_no', 'memo'],
            'sortable' => ['order_date', 'expected_date', 'order_no', 'total_cents', 'id'],
        ]);

        return SalesOrderResource::collection($this->paginateApi($query, $request));
    }

    public function show(SalesOrder $salesOrder): SalesOrderResource
    {
        return new SalesOrderResource($salesOrder->load(self::WITH));
    }

    public function store(StoreSalesOrderRequest $request): JsonResponse
    {
        $salesOrder = app(SaveSalesOrder::class)->handle($request->validated());

        return (new SalesOrderResource($salesOrder->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(UpdateSalesOrderRequest $request, SalesOrder $salesOrder): SalesOrderResource
    {
        if (! $salesOrder->effectiveStatus()->isEditable()) {
            $this->conflict('This sales order can no longer be edited.');
        }

        $salesOrder = app(SaveSalesOrder::class)->handle($request->validated(), $salesOrder);

        return new SalesOrderResource($salesOrder->load(self::WITH));
    }

    /**
     * Generate a Draft invoice for the requested quantities (partial fulfillment).
     */
    public function fulfill(FulfillSalesOrderRequest $request, SalesOrder $salesOrder): JsonResponse
    {
        /** @var array<int|string, mixed> $lines */
        $lines = $request->validated('lines');

        $invoice = $this->posting(
            fn (): Invoice => app(FulfillSalesOrder::class)->handle($salesOrder, $lines)->fresh(['lines'])
        );

        return (new InvoiceResource($invoice))->response()->setStatusCode(201);
    }

    /**
     * Cancel an order so no further quantity can be invoiced.
     */
    public function cancel(SalesOrder $salesOrder): SalesOrderResource
    {
        $status = $salesOrder->effectiveStatus();

        if ($status === SalesOrderStatus::Cancelled) {
            $this->conflict('Sales order is already cancelled.');
        }

        if ($status === SalesOrderStatus::Closed) {
            $this->conflict('A fully invoiced sales order cannot be cancelled.');
        }

        $salesOrder->update(['status' => SalesOrderStatus::Cancelled]);

        return new SalesOrderResource($salesOrder->load(self::WITH));
    }

    public function destroy(SalesOrder $salesOrder): JsonResponse
    {
        if (in_array($salesOrder->effectiveStatus(), [SalesOrderStatus::Partial, SalesOrderStatus::Closed], true)) {
            $this->conflict('A sales order with invoices cannot be deleted; cancel it instead.');
        }

        $salesOrder->delete();

        return response()->json(null, 204);
    }
}
