<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Assets\SaveAssetCategory;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreAssetCategoryRequest;
use App\Http\Requests\Api\V1\UpdateAssetCategoryRequest;
use App\Http\Resources\Api\V1\AssetCategoryResource;
use App\Models\AssetCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AssetCategoryController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AssetCategory::query();

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['name', 'description'],
            'sortable' => ['name', 'created_at', 'id'],
            'default_sort' => ['name', 'asc'],
        ]);

        return AssetCategoryResource::collection($this->paginateApi($query, $request));
    }

    public function show(AssetCategory $assetCategory): AssetCategoryResource
    {
        return new AssetCategoryResource($assetCategory);
    }

    public function store(StoreAssetCategoryRequest $request): JsonResponse
    {
        $category = app(SaveAssetCategory::class)->handle($request->validated());

        return (new AssetCategoryResource($category))->response()->setStatusCode(201);
    }

    public function update(UpdateAssetCategoryRequest $request, AssetCategory $assetCategory): AssetCategoryResource
    {
        $category = app(SaveAssetCategory::class)->handle($request->validated(), $assetCategory);

        return new AssetCategoryResource($category);
    }

    public function destroy(AssetCategory $assetCategory): JsonResponse
    {
        $assetCategory->delete();

        return response()->json(null, 204);
    }
}
