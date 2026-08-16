<?php

namespace App\Mcp\Resources;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Models\Company;
use App\Models\Item;
use App\Support\Mcp\ItemsPresenter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

class ItemsResource extends Resource
{
    use AnswersBusinessQuestions;

    protected string $uri = 'lineledger://items/catalog';

    protected string $mimeType = 'text/plain';

    protected string $title = 'Items / products catalog';

    protected string $description = 'The catalog of products and services: name, SKU, default price, whether the item is inventory-tracked, (for tracked items) the quantity on hand, and the numeric API id (the item_id the REST API expects — not the SKU). Complements the inventory-status tool. Read-only.';

    /**
     * Only advertise the catalog when the company actually has items.
     */
    public function shouldRegister(Request $request): bool
    {
        $company = app()->bound('current_company') ? app('current_company') : null;

        return $company instanceof Company && Item::query()->exists();
    }

    public function handle(Request $request): Response
    {
        if ($denied = $this->requireAbility('inventory:read')) {
            return $denied;
        }

        if ($denied = $this->requireAnySection(Section::Lists, Section::Inventory)) {
            return $denied;
        }

        return Response::text(app(ItemsPresenter::class)->render($this->company()));
    }
}
