<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tax\SaveTaxReturn;
use App\Enums\TaxReturnStatus;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreTaxReturnRequest;
use App\Http\Requests\Api\V1\UpdateTaxReturnRequest;
use App\Http\Resources\Api\V1\TaxReturnResource;
use App\Models\TaxReturn;
use App\Services\Tax\TaxReturnFiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaxReturnController extends ApiController
{
    use AppliesApiListFilters;

    public function __construct(protected TaxReturnFiler $filer) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TaxReturn::query();

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'period_end',
            'search' => ['tax_return_no', 'filing_reference'],
            'sortable' => ['period_end', 'period_start', 'tax_return_no', 'net_cents', 'id'],
        ]);

        return TaxReturnResource::collection($this->paginateApi($query, $request));
    }

    public function show(TaxReturn $taxReturn): TaxReturnResource
    {
        return new TaxReturnResource($taxReturn->load('lines'));
    }

    /**
     * Create a draft tax return header. Lines are auto-generated only on filing.
     */
    public function store(StoreTaxReturnRequest $request): JsonResponse
    {
        $taxReturn = app(SaveTaxReturn::class)->handle($request->validated());

        return (new TaxReturnResource($taxReturn->fresh(['lines'])))->response()->setStatusCode(201);
    }

    /**
     * Edit a draft header. Filed or voided returns are immutable.
     */
    public function update(UpdateTaxReturnRequest $request, TaxReturn $taxReturn): TaxReturnResource
    {
        if ($taxReturn->status !== TaxReturnStatus::Draft) {
            $this->conflict('Only draft tax returns can be edited.');
        }

        $taxReturn = app(SaveTaxReturn::class)->handle($request->validated(), $taxReturn);

        return new TaxReturnResource($taxReturn->fresh(['lines']));
    }

    /**
     * File a draft: snapshot the contributing journal lines and lock the period.
     */
    public function file(TaxReturn $taxReturn): TaxReturnResource
    {
        if ($taxReturn->status !== TaxReturnStatus::Draft) {
            $this->conflict('Only draft tax returns can be filed.');
        }

        $taxReturn = $this->posting(fn (): TaxReturn => $this->filer->file($taxReturn));

        return new TaxReturnResource($taxReturn->fresh(['lines']));
    }

    /**
     * Void a filed return (record-keeping reversal — no GL impact).
     */
    public function void(Request $request, TaxReturn $taxReturn): TaxReturnResource
    {
        if ($taxReturn->status !== TaxReturnStatus::Filed) {
            $this->conflict('Only filed tax returns can be voided.');
        }

        $reason = (string) $request->input('void_reason', 'Voided via API');

        $this->posting(fn () => $this->filer->void($taxReturn, $reason));

        return new TaxReturnResource($taxReturn->fresh(['lines']));
    }

    /**
     * Delete a draft. Filed/voided returns must go through void.
     */
    public function destroy(TaxReturn $taxReturn): JsonResponse
    {
        if ($taxReturn->status !== TaxReturnStatus::Draft) {
            $this->conflict('Only draft tax returns can be deleted; file then void instead.');
        }

        $taxReturn->lines()->delete();
        $taxReturn->delete();

        return response()->json(null, 204);
    }
}
