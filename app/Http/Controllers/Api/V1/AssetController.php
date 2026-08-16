<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Assets\SaveAsset;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreAssetRequest;
use App\Http\Requests\Api\V1\UpdateAssetRequest;
use App\Http\Resources\Api\V1\AssetResource;
use App\Models\Asset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Asset::query();

        $this->applyApiListFilters($query, $request, [
            'date_column' => 'acquired_date',
            'search' => ['asset_no', 'name', 'serial_number', 'location'],
            'sortable' => ['acquired_date', 'asset_no', 'name', 'cost_cents', 'id'],
            'default_sort' => ['id', 'desc'],
        ]);

        return AssetResource::collection($this->paginateApi($query, $request));
    }

    public function show(Asset $asset): AssetResource
    {
        return new AssetResource($asset);
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = app(SaveAsset::class)->handle($request->validated());

        return (new AssetResource($asset))->response()->setStatusCode(201);
    }

    public function update(UpdateAssetRequest $request, Asset $asset): AssetResource
    {
        $asset = app(SaveAsset::class)->handle($request->validated(), $asset);

        return new AssetResource($asset);
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $asset->delete();

        return response()->json(null, 204);
    }
}
