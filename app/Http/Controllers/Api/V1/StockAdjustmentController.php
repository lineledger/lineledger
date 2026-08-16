<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\SaveStockAdjustment;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreStockAdjustmentRequest;
use App\Http\Requests\Api\V1\UpdateStockAdjustmentRequest;
use App\Http\Resources\Api\V1\StockAdjustmentResource;
use App\Models\StockAdjustment;
use App\Services\Posting\StockAdjustmentPoster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StockAdjustmentController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected StockAdjustmentPoster $poster) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = StockAdjustment::query()->with('lines');

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'date_column' => 'adjustment_date',
            'search' => ['adjustment_no', 'notes'],
            'sortable' => ['adjustment_date', 'adjustment_no', 'id'],
        ]);

        return StockAdjustmentResource::collection($this->paginateApi($query, $request));
    }

    public function show(StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        return new StockAdjustmentResource($stockAdjustment->load('lines'));
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        $data = $request->validated();

        $adjustment = $this->posting(function () use ($data, $request): StockAdjustment {
            $adjustment = app(SaveStockAdjustment::class)->handle($data);

            if (! $this->wantsDraft($request)) {
                $this->poster->post($adjustment);
            }

            return $adjustment->fresh(['lines']);
        });

        return (new StockAdjustmentResource($adjustment))->response()->setStatusCode(201);
    }

    /**
     * Edit a draft. Posted adjustments have no repost path — void and recreate.
     */
    public function update(UpdateStockAdjustmentRequest $request, StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        if ($stockAdjustment->voided_at !== null) {
            $this->conflict('A voided stock adjustment cannot be edited.');
        }

        if ($stockAdjustment->journal_entry_id !== null) {
            $this->conflict('This posted document cannot be edited; void and recreate.');
        }

        $adjustment = app(SaveStockAdjustment::class)->handle($request->validated(), $stockAdjustment);

        return new StockAdjustmentResource($adjustment->fresh(['lines']));
    }

    /**
     * Post a draft to the GL. No repost path.
     */
    public function post(StockAdjustment $stockAdjustment): StockAdjustmentResource
    {
        if ($stockAdjustment->voided_at !== null) {
            $this->conflict('A voided stock adjustment cannot be posted.');
        }

        if ($stockAdjustment->journal_entry_id !== null) {
            $this->conflict('This stock adjustment is already posted.');
        }

        $adjustment = $this->posting(function () use ($stockAdjustment): StockAdjustment {
            $this->poster->post($stockAdjustment->load('lines.item'));

            return $stockAdjustment->fresh(['lines']);
        });

        return new StockAdjustmentResource($adjustment);
    }

    /**
     * Void a posted adjustment (reversing JE + movements) or hard-delete a draft.
     */
    public function destroy(StockAdjustment $stockAdjustment): JsonResponse
    {
        if ($stockAdjustment->voided_at !== null) {
            $this->conflict('Stock adjustment is already voided.');
        }

        if ($stockAdjustment->journal_entry_id !== null) {
            $this->posting(fn () => $this->poster->void($stockAdjustment));

            return (new StockAdjustmentResource($stockAdjustment->fresh(['lines'])))->response()->setStatusCode(200);
        }

        $stockAdjustment->lines()->delete();
        $stockAdjustment->delete();

        return response()->json(null, 204);
    }
}
