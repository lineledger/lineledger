<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Write\ConfirmProposalTool;
use App\Mcp\Tools\Write\ProposeBillTool;
use App\Mcp\Tools\Write\ProposeExpenseTool;
use App\Mcp\Tools\Write\ProposeInvoiceTool;
use App\Mcp\Tools\Write\ProposeJournalEntryTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

/**
 * The write-enabled (agentic) companion to {@see BusinessQaServer}. It can create
 * invoices, bills, expenses, and journal entries — but never in a single step.
 * Every mutation goes through a two-call propose -> confirm handshake, because MCP
 * has no UI for a human to approve a draft.
 */
class BusinessActionsServer extends Server
{
    /**
     * The MCP server's name.
     */
    protected string $name = 'LineLedger Business Actions';

    /**
     * The MCP server's version.
     */
    protected string $version = '1.0.0';

    /**
     * The MCP server's instructions for the LLM.
     */
    protected string $instructions = <<<'TEXT'
        This server can MAKE CHANGES to a single company's books in LineLedger (a
        double-entry accounting app): it can create invoices, vendor bills, paid
        expenses, and manual journal entries.

        Every change uses a mandatory two-step PROPOSE then CONFIRM handshake. There
        is no one-shot write:

          1. Call a propose tool (propose invoice / bill / expense / journal entry).
             It validates everything, computes the amounts, and returns a TOKEN plus
             a human-readable preview. NOTHING is written to the ledger at this step.
          2. Show the preview to the user and get their explicit go-ahead. Then call
             the confirm-proposal tool with that token to actually commit. Confirming
             is the only step that writes.

        Rules you must follow:
        - Never confirm a proposal the user has not explicitly approved. Always show
          them the preview first and wait for a clear "yes".
        - Confirming the same token twice is safe: the second call changes nothing and
          returns the original result. If a confirm fails, NOTHING was written — fix
          the issue and propose again rather than assuming a partial change.
        - Amounts in the propose tools are in DOLLARS (e.g. "unit_price": 19.99). The
          server converts to exact integer cents for you.
        - Line items must use ordinary income, expense, asset, or liability accounts.
          System / control accounts — Accounts Receivable, Accounts Payable, Retained
          Earnings — are rejected; the ledger resolves AR/AP itself when posting an
          invoice or bill.
        - If the preview warns that the period is LOCKED, confirming will fail. Tell
          the user the books are closed for that date instead of retrying.
        - Posting respects the company's lock date; a locked company cannot be
          written to, and the confirm call will say so.

        Use the read-only Business Q&A server to look up accounts, customers, vendors,
        and balances before proposing a write.
        TEXT;

    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ProposeInvoiceTool::class,
        ProposeBillTool::class,
        ProposeExpenseTool::class,
        ProposeJournalEntryTool::class,
        ConfirmProposalTool::class,
    ];
}
