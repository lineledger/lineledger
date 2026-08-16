<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MasterData\SaveTaxCode;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreTaxCodeRequest;
use App\Http\Requests\Api\V1\UpdateTaxCodeRequest;
use App\Http\Resources\Api\V1\TaxCodeResource;
use App\Models\TaxCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaxCodeController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TaxCode::query();

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['code', 'name'],
            'sortable' => ['code', 'name', 'rate_basis_points', 'id'],
            'default_sort' => ['code', 'asc'],
        ]);

        return TaxCodeResource::collection($this->paginateApi($query, $request));
    }

    public function show(TaxCode $taxCode): TaxCodeResource
    {
        return new TaxCodeResource($taxCode);
    }

    public function store(StoreTaxCodeRequest $request): JsonResponse
    {
        $taxCode = app(SaveTaxCode::class)->handle($request->validated());

        return (new TaxCodeResource($taxCode))->response()->setStatusCode(201);
    }

    public function update(UpdateTaxCodeRequest $request, TaxCode $taxCode): TaxCodeResource
    {
        $taxCode = app(SaveTaxCode::class)->handle($request->validated(), $taxCode);

        return new TaxCodeResource($taxCode);
    }

    public function destroy(TaxCode $taxCode): JsonResponse
    {
        $taxCode->delete();

        return response()->json(null, 204);
    }
}
