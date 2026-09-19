<?php

use Illuminate\Support\Facades\Route;

/**
 * Every /api/v1 route as the spec would name it: the path relative to the
 * spec's server (/api/v1), with each route parameter normalised to {param}
 * so a spec author's parameter naming can't mask a missing path item.
 *
 * @return array<string, list<string>> path => sorted list of lower-case HTTP methods
 */
function apiV1RoutesBySpecPath(): array
{
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        // The spec's own endpoint documents the surface; it isn't part of it.
        if ($uri === 'api/v1/openapi.json') {
            continue;
        }

        $path = normaliseSpecPath(substr($uri, strlen('api/v1')));

        foreach ($route->methods() as $method) {
            $method = strtolower($method);

            if (in_array($method, ['head', 'options'], true)) {
                continue;
            }

            // PUT is registered as an alias of PATCH; the spec documents PATCH.
            $routes[$path][] = $method === 'put' ? 'patch' : $method;
        }
    }

    foreach ($routes as &$methods) {
        $methods = array_values(array_unique($methods));
        sort($methods);
    }

    ksort($routes);

    return $routes;
}

function normaliseSpecPath(string $path): string
{
    return (string) preg_replace('/\{[^}]+\}/', '{param}', $path);
}

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

    // Spot-check a couple of representative operations resolved correctly,
    // including a sibling `summary` overriding the fragment's.
    expect($spec['paths']['/customers']['get']['summary'])->toBe('List contacts')
        ->and($spec['paths']['/vendors']['get']['summary'])->toBe('List vendors')
        ->and($spec['paths']['/vendors']['get']['parameters'])->toBe($spec['paths']['/customers']['get']['parameters'])
        ->and($spec['paths']['/invoices']['post']['summary'])->toBe('Create (and post) an invoice')
        ->and(count($spec['paths']))->toBeGreaterThan(40);
});

it('reports the application version', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertStatus(200)->json();

    expect($spec['info']['version'])->toBe(config('version.app'));
});

it('documents every /api/v1 route and no phantom paths', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertStatus(200)->json();

    $documented = [];
    foreach ($spec['paths'] as $path => $item) {
        $methods = array_values(array_intersect(array_keys($item), ['get', 'post', 'put', 'patch', 'delete']));
        sort($methods);
        $documented[normaliseSpecPath($path)] = $methods;
    }
    ksort($documented);

    $routes = apiV1RoutesBySpecPath();

    expect(array_keys($documented))->toBe(array_keys($routes), 'Spec path items differ from the registered /api/v1 routes.');

    foreach ($routes as $path => $methods) {
        expect($documented[$path])->toBe($methods, "Operations on {$path} differ from the registered route methods.");
    }
});

it('resolves every $ref inside the served document', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertStatus(200)->json();

    $refs = [];
    $walk = function (mixed $node) use (&$walk, &$refs): void {
        if (! is_array($node)) {
            return;
        }
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $refs[] = $value;
            } else {
                $walk($value);
            }
        }
    };
    $walk($spec);

    $unresolved = [];
    foreach (array_unique($refs) as $ref) {
        $cursor = $spec;
        foreach (explode('/', ltrim(substr($ref, 1), '/')) as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                $unresolved[] = $ref;

                continue 2;
            }
            $cursor = $cursor[$segment];
        }
    }

    expect($unresolved)->toBe([]);
});

it('documents the 423 edit-lock response on lockable writes only', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertStatus(200)->json();

    expect($spec['components']['responses'])->toHaveKey('Locked')
        ->and($spec['components']['responses']['Locked']['headers'])->toHaveKey('Retry-After')
        ->and($spec['paths']['/invoices/{invoice}']['patch']['responses'])->toHaveKey('423')
        ->and($spec['paths']['/invoices/{invoice}']['patch']['responses']['423']['$ref'])->toBe('#/components/responses/Locked')
        // Inlined shared fragments carry it too…
        ->and($spec['paths']['/bills/{bill}/post']['post']['responses'])->toHaveKey('423')
        ->and($spec['paths']['/customers/{contact}']['delete']['responses'])->toHaveKey('423')
        ->and($spec['paths']['/transfers/{transfer}']['patch']['responses'])->toHaveKey('423')
        // …but resources with no web editor never answer 423.
        ->and($spec['paths']['/stock-adjustments/{stockAdjustment}/post']['post']['responses'])->not->toHaveKey('423')
        ->and($spec['paths']['/tax-return-payments/{taxReturnPayment}']['delete']['responses'])->not->toHaveKey('423')
        ->and($spec['paths']['/bank-reconciliations/{bankReconciliation}']['patch']['responses'])->not->toHaveKey('423');

    // Every update / delete / action on a lockable resource documents 423 and
    // every non-lockable one omits it — derived from the same registry the
    // middleware uses, so the spec can't drift from it.
    $unlockable = ['/bank-reconciliations', '/stock-adjustments', '/tax-return-payments'];

    foreach ($spec['paths'] as $path => $item) {
        if (! str_contains($path, '{')) {
            continue; // collection endpoints: reads and creates are never refused
        }

        $isUnlockable = collect($unlockable)->contains(fn (string $prefix) => str_starts_with($path, $prefix));

        foreach (['patch', 'delete', 'post'] as $method) {
            if (! isset($item[$method])) {
                continue;
            }

            $has423 = array_key_exists('423', $item[$method]['responses']);

            expect($has423)->toBe(! $isUnlockable, strtoupper($method)." {$path} should ".($isUnlockable ? 'not ' : '').'document 423.');
        }
    }
});

it('gives every list operation sort and direction, and every request body a concrete schema', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertStatus(200)->json();

    expect($spec['components']['parameters'])->toHaveKey('direction')
        ->and($spec['components']['parameters']['direction']['schema']['enum'])->toBe(['asc', 'desc'])
        ->and($spec['components']['schemas'])->not->toHaveKey('GenericInput');

    foreach ($spec['paths'] as $path => $item) {
        if (str_contains($path, '{') || ! isset($item['get'])) {
            continue;
        }

        $refs = array_map(fn (array $p) => $p['$ref'] ?? null, $item['get']['parameters'] ?? []);

        expect(in_array('#/components/parameters/sort', $refs, true))->toBeTrue("GET {$path} lacks sort")
            ->and(in_array('#/components/parameters/direction', $refs, true))->toBeTrue("GET {$path} lacks direction");
    }

    foreach ($spec['paths'] as $path => $item) {
        foreach (['post', 'patch'] as $method) {
            if (! isset($item[$method]['requestBody'])) {
                continue;
            }

            $body = $item[$method]['requestBody'];
            $bodyRef = $body['$ref'] ?? null;
            if ($bodyRef !== null) {
                $body = $spec['components']['requestBodies'][substr($bodyRef, strlen('#/components/requestBodies/'))];
            }

            $schema = $body['content']['application/json']['schema'];

            expect(array_key_exists('$ref', $schema))->toBeTrue(strtoupper($method)." {$path} has an inline / generic body");
        }
    }
});

it('reflects the 1.1.0 schema additions', function () {
    $spec = $this->getJson('/api/v1/openapi.json')->assertStatus(200)->json();
    $schemas = $spec['components']['schemas'];

    // Transfers are a first-class Banking resource.
    expect($spec['paths'])->toHaveKeys(['/transfers', '/transfers/{transfer}', '/transfers/{transfer}/post'])
        ->and($spec['paths']['/transfers']['get']['tags'])->toBe(['Banking'])
        ->and($schemas['TransferInput']['required'])->toBe(['from_account_id', 'to_account_id', 'transfer_date', 'from_amount_cents', 'to_amount_cents'])
        ->and($schemas['Transfer']['properties'])->toHaveKeys(['from_fx_rate', 'to_fx_rate', 'home_amount_cents', 'journal_entry_id']);

    // Invoice: response and input fields the resource / form request expose.
    expect($schemas['Invoice']['properties'])->toHaveKeys(['terms_id', 'form_style_id', 'memo', 'posted_at', 'balance_cents', 'sales_rep_id'])
        ->and($schemas['DocumentLine']['properties'])->toHaveKey('secondary_tax_code_id')
        ->and($schemas['InvoiceInput']['properties'])->toHaveKeys(['invoice_no', 'form_style_id', 'sales_rep_id'])
        ->and($schemas['InvoiceLineInput']['properties']['unit_price_cents']['minimum'])->toBeLessThan(0)
        ->and($schemas['DocumentLineInput']['properties']['unit_price_cents']['minimum'])->toBe(0);

    // Contacts.
    expect($schemas['Contact']['properties'])->toHaveKeys(['first_name', 'last_name', 'tax_number', 'notes', 'created_at', 'default_income_account_id'])
        ->and($schemas['ContactInput']['properties'])->toHaveKeys(['account_no', 'first_name', 'last_name']);

    // Accounts, journal entries, receipts, credit memos, refunds.
    expect($schemas['AccountInput']['properties'])->toHaveKeys(['cash_flow_activity', 'default_tax_code_id', 'currency_code'])
        ->and($schemas['JournalEntryInput']['properties'])->toHaveKey('entry_no')
        ->and($schemas['JournalEntryInput']['properties'])->not->toHaveKey('currency_code')
        ->and($schemas['ReceiptInput']['properties'])->toHaveKey('receipt_no')
        ->and($schemas['Receipt']['properties'])->toHaveKey('unapplied_cents')
        ->and($schemas['CreditMemoInput']['properties'])->toHaveKeys(['credit_memo_no', 'sales_rep_id'])
        ->and($schemas['CreditMemoRefundInput']['properties'])->toHaveKey('receipt_no');

    // Cheques have a real schema carrying the 1.1.0 changes.
    expect($schemas['ChequeInput']['properties'])->toHaveKeys(['payee_address', 'cheque_no', 'payee_contact_id', 'payee_name'])
        ->and($schemas['ChequeLineInput']['properties'])->toHaveKeys(['contact_id', 'tax_code_id', 'secondary_tax_code_id'])
        ->and($schemas['ChequeLineInput']['description'])->toContain('ignored')
        ->and($schemas['ChequeInput']['properties']['cheque_no']['description'])->toContain('Not unique')
        ->and($schemas['Cheque']['properties'])->toHaveKeys(['payee_address', 'credit_memo_id'])
        ->and($spec['paths']['/cheques/{cheque}']['patch']['summary'])->toContain('reposted');
});
