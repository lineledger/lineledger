<?php

namespace App\Mcp\Tools;

use App\Enums\Section;
use App\Mcp\Concerns\AnswersBusinessQuestions;
use App\Support\Mcp\ItemsPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * The tool half of the items catalog. The companion ItemsResource carries the
 * same text, but many MCP clients only auto-surface tools — resources have to be
 * attached by hand — so the listing is offered both ways.
 */
class ItemsCatalogTool extends Tool
{
    use AnswersBusinessQuestions;

    protected string $title = 'Items catalog';

    protected string $description = 'The full catalog of products and services with each item\'s name, SKU, default price, inventory tracking and quantity on hand, and numeric API id. Use this to look up the "API id" (the item_id the REST API expects) for an item you know by name or SKU — the SKU is not the id. Read-only and never modifies data.';

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

    /**
     * @return array<string, JsonSchema>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
