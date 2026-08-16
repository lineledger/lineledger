<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\ArCollectionsPrompt;
use App\Mcp\Prompts\MonthEndCloseReviewPrompt;
use App\Mcp\Prompts\SalesTaxFilingPrepPrompt;
use App\Mcp\Prompts\YearEndTaxPrepChecklistPrompt;
use App\Mcp\Resources\ChartOfAccountsResource;
use App\Mcp\Resources\CompanyProfileResource;
use App\Mcp\Resources\ContactsResource;
use App\Mcp\Resources\GifiCatalogResource;
use App\Mcp\Resources\ItemsResource;
use App\Mcp\Resources\PaymentMethodsResource;
use App\Mcp\Resources\TaxCodesResource;
use App\Mcp\Tools\AccountBalanceTool;
use App\Mcp\Tools\AccountsPayableTool;
use App\Mcp\Tools\AccountsReceivableTool;
use App\Mcp\Tools\BalanceSheetTool;
use App\Mcp\Tools\CashFlowTool;
use App\Mcp\Tools\ChartOfAccountsTool;
use App\Mcp\Tools\CompanyProfileTool;
use App\Mcp\Tools\ContactsDirectoryTool;
use App\Mcp\Tools\FinancialSummaryTool;
use App\Mcp\Tools\FindContactTool;
use App\Mcp\Tools\Form1099Tool;
use App\Mcp\Tools\InventoryStatusTool;
use App\Mcp\Tools\ItemsCatalogTool;
use App\Mcp\Tools\ListInvoicesTool;
use App\Mcp\Tools\PaymentMethodsTool;
use App\Mcp\Tools\ProfitAndLossTool;
use App\Mcp\Tools\SalesReportTool;
use App\Mcp\Tools\SalesTaxTool;
use App\Mcp\Tools\TaxCodesTool;
use App\Mcp\Tools\TrialBalanceTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

class BusinessQaServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'LineLedger Business Q&A';

    /**
     * The MCP server's version.
     */
    protected string $version = '0.2.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'TEXT'
        This server answers plain-language questions about the finances of a single
        company in LineLedger (a double-entry accounting app). Use it for questions
        like "how did we do last quarter?", "who owes me money?", "are we low on any
        stock?", or "how much sales tax do I owe?".

        Important context for every answer:
        - All figures are already in the company's home currency and are computed from
          the posted general ledger, so they are authoritative.
        - This server is READ-ONLY. It cannot create, edit, post, void, or delete
          anything. If the user asks to make a change, explain that and point them to
          the LineLedger app.
        - You are always scoped to exactly one company; you cannot see other tenants.
        - Prefer the friendly `period` argument (e.g. "last_quarter") when the user
          names a relative window, and use explicit start/end dates only when they give
          specific dates.

        Reference context — each of these is available BOTH as a tool and as a
        resource, because some clients only surface tools:
        - The company profile gives the organization type, jurisdiction, home currency,
          the fiscal-year start and the CURRENT fiscal year's start and end dates, and
          which CRA returns apply. Read it when a question depends on the fiscal
          calendar (e.g. "this year") so periods are framed correctly.
        - The chart of accounts lists every account with its code, GIFI code, and
          balance. The other listings cover tax codes & agencies, the items catalog,
          the contacts directory, payment methods, and the CRA GIFI catalog.

        Every one of those listings reports a numeric "API id" per row. That id — not
        the account code, SKU, tax code, or name — is what `/api/v1` payloads take as
        account_id / item_id / tax_code_id / contact_id / payment_method_id. When a user
        asks "what's the id for X", or you need an id to build a request, read the
        matching listing rather than guessing: codes and names are user-facing and can
        be renumbered or renamed, ids cannot.

        Guided workflows (prompts): month-end close review, AR collections, sales-tax
        filing prep, and an entity-aware year-end tax-prep checklist.
        TEXT;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        FinancialSummaryTool::class,
        ProfitAndLossTool::class,
        BalanceSheetTool::class,
        CashFlowTool::class,
        TrialBalanceTool::class,
        AccountsReceivableTool::class,
        AccountsPayableTool::class,
        AccountBalanceTool::class,
        SalesReportTool::class,
        InventoryStatusTool::class,
        FindContactTool::class,
        ListInvoicesTool::class,
        SalesTaxTool::class,
        Form1099Tool::class,
        CompanyProfileTool::class,
        ChartOfAccountsTool::class,
        ItemsCatalogTool::class,
        TaxCodesTool::class,
        ContactsDirectoryTool::class,
        PaymentMethodsTool::class,
    ];

    /**
     * The resources registered with this MCP server.
     *
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        CompanyProfileResource::class,
        ChartOfAccountsResource::class,
        TaxCodesResource::class,
        ItemsResource::class,
        ContactsResource::class,
        PaymentMethodsResource::class,
        GifiCatalogResource::class,
    ];

    /**
     * The prompts registered with this MCP server.
     *
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        MonthEndCloseReviewPrompt::class,
        ArCollectionsPrompt::class,
        SalesTaxFilingPrepPrompt::class,
        YearEndTaxPrepChecklistPrompt::class,
    ];
}
