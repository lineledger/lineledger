<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\Yaml\Yaml;

/**
 * Serves the hand-authored OpenAPI spec (resources/api/openapi.yaml) as JSON.
 * Public — the spec documents the API surface and contains no secrets.
 *
 * Operation objects cannot be JSON references, so reusable operation fragments
 * are stored under the `components.x-ops` extension and inlined here before the
 * document is returned.
 */
class OpenApiController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $spec = Yaml::parseFile(resource_path('api/openapi.yaml'));

        $fragments = $spec['components']['x-ops'] ?? [];

        foreach ($spec['paths'] as &$item) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
                if (! isset($item[$method]['$ref'])) {
                    continue;
                }

                $ref = $item[$method]['$ref'];
                if (! str_starts_with($ref, '#/components/x-ops/')) {
                    continue;
                }

                $key = substr($ref, strlen('#/components/x-ops/'));
                $siblings = $item[$method];
                unset($siblings['$ref']);

                $item[$method] = array_merge($fragments[$key] ?? [], $siblings);
            }
        }
        unset($item);

        unset($spec['components']['x-ops']);

        return response()->json($spec, 200, [], JSON_UNESCAPED_SLASHES);
    }
}
