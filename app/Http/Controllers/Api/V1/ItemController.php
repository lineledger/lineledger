<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\MasterData\SaveItem;
use App\Http\Concerns\AppliesApiListFilters;
use App\Http\Requests\Api\V1\StoreItemRequest;
use App\Http\Requests\Api\V1\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends ApiController
{
    use AppliesApiListFilters;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Item::query();

        $this->applyApiListFilters($query, $request, [
            'status_column' => null,
            'search' => ['name', 'sku'],
            'sortable' => ['name', 'sku', 'default_price_cents', 'id'],
            'default_sort' => ['name', 'asc'],
        ]);

        return ItemResource::collection($this->paginateApi($query, $request));
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item);
    }

    public function store(StoreItemRequest $request): JsonResponse
    {
        $item = $this->posting(fn (): Item => app(SaveItem::class)->handle($request->validated()));

        return (new ItemResource($item))->response()->setStatusCode(201);
    }

    public function update(UpdateItemRequest $request, Item $item): ItemResource
    {
        $item = $this->posting(fn (): Item => app(SaveItem::class)->handle($request->validated(), $item));

        return new ItemResource($item);
    }

    public function destroy(Item $item): JsonResponse
    {
        $item->delete();

        return response()->json(null, 204);
    }
}
