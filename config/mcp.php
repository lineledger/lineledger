<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | These domains are the domains that OAuth clients are permitted to use
    | for redirect URIs. Each domain should be specified with its scheme
    | and host. Domains not in this list will raise validation errors.
    |
    | An "*" may be used to allow all domains, but MUST NOT be used in
    | production: it lets anyone register an OAuth client with an attacker
    | controlled redirect URI (token exfiltration). The default below allows
    | this application's own host plus Anthropic's Claude clients; override per
    | environment with the comma-separated MCP_REDIRECT_DOMAINS env var (e.g.
    | add "http://localhost,http://127.0.0.1" for local MCP clients in dev).
    |
    */

    'redirect_domains' => array_values(array_filter(array_merge(
        array_map('trim', explode(',', (string) env('MCP_REDIRECT_DOMAINS', 'https://claude.ai,https://claude.com'))),
        [rtrim((string) env('APP_URL', ''), '/')],
    ))),

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        // 'claude',
        // 'cursor',
        // 'vscode',
    ],

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Agentic (write-enabled) MCP server
    |--------------------------------------------------------------------------
    |
    | The operator master switch for the write-enabled MCP server (the
    | propose→confirm tools on mcp/business-actions). Default OFF and fails
    | closed: even with the flag absent, the tools refuse to propose or confirm.
    | This is one half of the doubly-opt-in gate — the other is the per-company
    | `settings.mcp.agentic_writes` flag (Company::agenticWritesEnabled()).
    |
    */

    'write_enabled' => (bool) env('MCP_WRITE_ENABLED', false),

];
