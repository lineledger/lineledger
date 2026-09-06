<?php

use App\Mcp\Servers\BusinessActionsServer;
use App\Mcp\Servers\BusinessQaServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| The Business Q&A server lets owners ask plain-language questions about their
| company's finances from an MCP client (e.g. Claude Desktop). Two connection
| methods are supported:
|
| 1. API key (mcp/business) — `auth.api_key` binds current_company + current_api_key
|    from a company-scoped key. Simple; the client sends a static bearer token.
|
| 2. OAuth (mcp/business/{company}) — `auth:api` (Passport) authenticates the staff
|    user via the authorization-code + PKCE flow; `mcp.company` then resolves the
|    {company} slug from the URL and binds current_company after verifying the user
|    is a member. This is the one-click connector path for Claude Desktop / claude.ai.
|
| Per-tool ability checks handle scope granularity, since one web route can't vary
| middleware per tool.
|
*/

// NOTE: OAuth discovery + dynamic client registration (Mcp::oauthRoutes()) is
// registered in AppServiceProvider::boot(), not here — it requires Passport's
// routes to already exist, and this file is loaded before the Passport service
// provider boots.

Mcp::web('mcp/business', BusinessQaServer::class)
    ->middleware(['auth.api_key', 'enforce.2fa_api']);

Mcp::web('mcp/business/{company}', BusinessQaServer::class)
    ->middleware(['auth:api', 'mcp.company', 'enforce.2fa_api']);

// Agentic (write-enabled) server: the propose→confirm tools. Same two
// connection methods as the Q&A server; authorization is per-tool
// (requireAbility / requireSection) plus the doubly-opt-in gate (operator
// MCP_WRITE_ENABLED + per-company settings.mcp.agentic_writes), so the only
// extra route middleware needed beyond the auth binding is the 2FA check.
Mcp::web('mcp/business-actions', BusinessActionsServer::class)
    ->middleware(['auth.api_key', 'enforce.2fa_api']);

Mcp::web('mcp/business-actions/{company}', BusinessActionsServer::class)
    ->middleware(['auth:api', 'mcp.company', 'enforce.2fa_api']);
