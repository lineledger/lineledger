<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MasterData\SaveTaxAgency;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreTaxAgencyRequest;
use App\Http\Requests\Api\V1\UpdateTaxAgencyRequest;
use App\Http\Resources\Api\V1\TaxAgencyResource;
use App\Models\TaxAgency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaxAgencyController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = TaxAgency::query();

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['name', 'registration_number'],
            'sortable' => ['name', 'id'],
            'default_sort' => ['name', 'asc'],
        ]);

        return TaxAgencyResource::collection($this->paginateApi($query, $request));
    }

    public function show(TaxAgency $taxAgency): TaxAgencyResource
    {
        return new TaxAgencyResource($taxAgency);
    }

    public function store(StoreTaxAgencyRequest $request): JsonResponse
    {
        $agency = app(SaveTaxAgency::class)->handle($request->validated());

        return (new TaxAgencyResource($agency))->response()->setStatusCode(201);
    }

    public function update(UpdateTaxAgencyRequest $request, TaxAgency $taxAgency): TaxAgencyResource
    {
        $agency = app(SaveTaxAgency::class)->handle($request->validated(), $taxAgency);

        return new TaxAgencyResource($agency);
    }

    public function destroy(TaxAgency $taxAgency): JsonResponse
    {
        if ($taxAgency->taxCodes()->exists()) {
            $this->conflict('Agency has tax codes; reassign or remove them first.');
        }

        $taxAgency->delete();

        return response()->json(null, 204);
    }
}
