<?php

it('serves a valid dereferenced OpenAPI spec without auth', function () {
    $response = $this->getJson('/api/v1/openapi.json');

    $response->assertStatus(200)
        ->assertJsonPath('openapi', '3.1.0')
        ->assertJsonPath('info.title', 'LineLedger REST API');

    $spec = $response->json();

    // Reusable operation fragments were inlined, not left as $refs.
    expect($spec['components']['x-ops'] ?? null)->toBeNull();

    $refsLeft = 0;
    foreach ($spec['paths'] as $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($item[$method]['$ref'])) {
                $refsLeft++;
            }
        }
    }
    expect($refsLeft)->toBe(0);

    // Spot-check a couple of representative operations resolved correctly.
    expect($spec['paths']['/customers']['get']['summary'])->toBe('List contacts')
        ->and($spec['paths']['/invoices']['post']['summary'])->toBe('Create (and post) an invoice')
        ->and(count($spec['paths']))->toBeGreaterThan(40);
});
