<?php

use App\Models\Company;
use App\Models\CompanyApiKey;

/*
 | The MCP endpoints over HTTP, end to end through the API-key route. Connectors
 | still open with the `initialize` handshake (2025-06-18 / 2025-11-25). Since
 | laravel/mcp 1.0 a client on the 2026-07-28 revision skips it and carries the
 | protocol version in every request's `_meta`, with matching MCP-Protocol-Version,
 | Mcp-Method and Mcp-Name headers. Both kinds of client must reach the same tools.
 */

beforeEach(function () {
    $this->company = Company::factory()->create();

    ['plaintext' => $plain] = CompanyApiKey::mint($this->company, 'MCP protocol test');

    $this->headers = [
        'Authorization' => "Bearer {$plain}",
        'Accept' => 'application/json, text/event-stream',
    ];
});

afterEach(function () {
    app()->forgetInstance('current_company');
    app()->forgetInstance('current_api_key');
});

it('answers a legacy initialize handshake with the version the client asked for', function () {
    $this->postJson('/mcp/business', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'connector', 'version' => '1.0.0'],
        ],
    ], $this->headers)
        ->assertOk()
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.serverInfo.name', 'LineLedger Business Q&A');
});

it('serves a legacy tools/call without the MCP headers', function () {
    $response = $this->postJson('/mcp/business', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => ['name' => 'company-profile-tool', 'arguments' => []],
    ], $this->headers);

    $response->assertOk()->assertJsonPath('result.isError', false);
    expect($response->getContent())->toContain($this->company->name);
});

it('serves a 2026-07-28 client that sends the protocol metadata and headers', function () {
    $meta = [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];

    $headers = [
        ...$this->headers,
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => 'tools/call',
        'Mcp-Name' => 'company-profile-tool',
    ];

    $response = $this->postJson('/mcp/business', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'company-profile-tool', 'arguments' => [], '_meta' => $meta],
    ], $headers);

    $response->assertOk()->assertJsonPath('result.isError', false);
    expect($response->getContent())->toContain($this->company->name);

    // A 2026-07-28 request whose headers don't match its body is refused.
    unset($headers['Mcp-Name']);

    $this->postJson('/mcp/business', [
        'jsonrpc' => '2.0',
        'id' => 4,
        'method' => 'tools/call',
        'params' => ['name' => 'company-profile-tool', 'arguments' => [], '_meta' => $meta],
    ], $headers)
        ->assertStatus(400)
        ->assertJsonPath('error.code', -32020);
});
