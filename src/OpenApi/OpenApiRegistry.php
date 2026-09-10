<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\OpenApi;

use rafalmasiarek\DashboardKitApi\Http\JsonHandler;

/**
 * Collects API route metadata and produces a full OpenAPI 3.1 specification.
 *
 * Registered in the DI container as OpenApiRegistry::class when the API plugin
 * is installed. Other plugins should check container->has(OpenApiRegistry::class)
 * before interacting with this registry to remain independent of the API plugin.
 *
 * Usage from another plugin:
 * <code>
 *   if ($container->has(OpenApiRegistry::class)) {
 *       $container->get(OpenApiRegistry::class)
 *           ->registerTag('scheduler', 'Scheduler endpoints');
 *   }
 * </code>
 *
 * Routes are registered automatically by ApiAddon::registerApiRoutes() from
 * module 'api' key definitions. The 'openapi' sub-key on each route definition
 * provides per-route metadata:
 * <code>
 *   'openapi' => [
 *       'summary'     => 'List notes',
 *       'description' => 'Returns paginated notes for the authenticated user.',
 *       'tags'        => ['notes'],
 *       'parameters'  => [],
 *       'requestBody' => null,
 *       'responses'   => [
 *           200 => ['description' => 'Notes list', 'schema' => ['type' => 'array', ...]],
 *       ],
 *       'deprecated'  => false,
 *   ]
 * </code>
 *
 * @package rafalmasiarek\DashboardKitApi\OpenApi
 */
final class OpenApiRegistry
{
    /**
     * Collected route entries.
     *
     * @var array<int, array{
     *   method:   string,
     *   version:  string,
     *   slug:     string,
     *   path:     string,
     *   scopes:   string[],
     *   openapi:  array<string, mixed>,
     * }>
     */
    private array $routes = [];

    /**
     * Registered tags keyed by name.
     *
     * @var array<string, array{name: string, description: string|null}>
     */
    private array $tags = [];

    /**
     * Extra OpenAPI component sections (e.g. 'schemas', 'examples').
     *
     * @var array<string, array<string, mixed>>
     */
    private array $components = [];

    /**
     * OpenAPI info object.
     *
     * @var array<string, mixed>
     */
    private array $info = [
        'title'   => 'API',
        'version' => '1.0.0',
    ];

    /**
     * OpenAPI servers array.
     *
     * @var array<int, array<string, mixed>>
     */
    private array $servers = [['url' => '/']];

    /**
     * Optional route prefix applied before the version segment.
     *
     * Empty string means routes are at /{version}/..., 'api' means /api/{version}/...
     *
     * @var string
     */
    private string $apiPrefix = '';

    /**
     * Set the OpenAPI info block.
     *
     * @param array<string, mixed> $info Must contain at least 'title' and 'version'.
     * @return void
     */
    public function setInfo(array $info): void
    {
        $this->info = $info;
    }

    /**
     * Set the servers list.
     *
     * @param array<int, array<string, mixed>> $servers Each entry must have 'url'.
     * @return void
     */
    public function setServers(array $servers): void
    {
        $this->servers = $servers;
    }

    /**
     * Set the API route prefix used when building path objects in the spec.
     *
     * Must match the prefix used in ApiAddon::registerApiRoutes() so that paths
     * in the spec correspond to actual registered Slim routes.
     *
     * @param string $prefix Without leading or trailing slash. Empty string = no prefix.
     * @return void
     */
    public function setApiPrefix(string $prefix): void
    {
        $this->apiPrefix = \trim($prefix, '/');
    }

    /**
     * Register a route for inclusion in the OpenAPI spec.
     *
     * Called by ApiAddon::registerApiRoutes() for every discovered module route.
     *
     * @param string               $method  HTTP method (GET, POST, …).
     * @param string               $version Version key, e.g. 'v1'.
     * @param string               $slug    Module slug, e.g. 'notes'.
     * @param string               $path    Route path relative to slug, e.g. '/' or '/{id}'.
     * @param string[]             $scopes  Required scopes for this route.
     * @param array<string, mixed> $openapi Per-route OpenAPI metadata from module definition.
     * @return void
     */
    public function registerRoute(
        string $method,
        string $version,
        string $slug,
        string $path,
        array $scopes,
        array $openapi,
        bool $public = false,
    ): void {
        $this->routes[] = [
            'method'  => \strtoupper($method),
            'version' => $version,
            'slug'    => $slug,
            'path'    => $path,
            'scopes'  => $scopes,
            'openapi' => $openapi,
            'public'  => $public,
        ];
    }

    /**
     * Register a named tag for grouping operations.
     *
     * Safe to call multiple times with the same name — the last description wins.
     *
     * @param string      $name        Tag name matching the 'tags' key on individual routes.
     * @param string|null $description Optional human-readable description.
     * @return void
     */
    public function registerTag(string $name, ?string $description = null): void
    {
        $this->tags[$name] = ['name' => $name, 'description' => $description];
    }

    /**
     * Register an extra OpenAPI component (schema, example, parameter, etc.).
     *
     * @param string               $section Component section: 'schemas', 'examples', 'parameters', 'responses'.
     * @param string               $name    Component name (used as the $ref key).
     * @param array<string, mixed> $schema  OpenAPI component object.
     * @return void
     */
    public function registerComponent(string $section, string $name, array $schema): void
    {
        $this->components[$section][$name] = $schema;
    }

    /**
     * Build and return the complete OpenAPI 3.1 specification as a PHP array.
     *
     * The result is ready for json_encode(). Tags are auto-collected from all
     * registered routes if not explicitly registered. Standard 401/403 responses
     * are injected automatically for every route that requires scopes.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $allTags   = $this->collectTags();
        $paths     = $this->buildPaths();
        $schemas   = $this->buildSchemas();
        $responses = $this->buildStandardResponses();

        $spec = [
            'openapi'    => '3.1.0',
            'info'       => $this->info,
            'servers'    => $this->servers,
            'tags'       => \array_values($allTags),
            'paths'      => $paths,
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type'         => 'http',
                        'scheme'       => 'bearer',
                        'bearerFormat' => 'opaque',
                        'description'  => 'Token issued via /settings/api-tokens or /admin/api/tokens.',
                    ],
                ],
                'schemas'   => $schemas,
                'responses' => $responses,
            ],
        ];

        foreach ($this->components as $section => $items) {
            $spec['components'][$section] = \array_merge(
                $spec['components'][$section] ?? [],
                $items,
            );
        }

        return $spec;
    }

    /**
     * Collect all tags: explicitly registered ones merged with tags derived from routes.
     *
     * Route tags that have no matching explicit registration get an empty description.
     *
     * @return array<string, array{name: string, description: string|null}>
     */
    private function collectTags(): array
    {
        $all = $this->tags;
        foreach ($this->routes as $entry) {
            foreach ((array) ($entry['openapi']['tags'] ?? []) as $tag) {
                $tagName = (string) $tag;
                if (!isset($all[$tagName])) {
                    $all[$tagName] = ['name' => $tagName, 'description' => null];
                }
            }
        }
        return $all;
    }

    /**
     * Build the OpenAPI paths object.
     *
     * Routes sharing the same full path are grouped under a single path entry,
     * each HTTP method becoming a separate operation object.
     *
     * The path includes the optional API prefix, version, slug, and route path,
     * e.g. /api/v1/notes/{id} when prefix is 'api'.
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildPaths(): array
    {
        $paths = [];

        foreach ($this->routes as $entry) {
            $slugPath = $entry['slug'] . ($entry['path'] === '/' ? '' : $entry['path']);
            $segments = \array_filter([$this->apiPrefix, $entry['version'], $slugPath]);
            $fullPath = '/' . \implode('/', $segments);

            $method    = \strtolower($entry['method']);
            $operation = $this->buildOperation($entry['scopes'], $entry['openapi'], $entry['public'] ?? false);

            if (!isset($paths[$fullPath])) {
                $paths[$fullPath] = [];
            }
            $paths[$fullPath][$method] = $operation;
        }

        \ksort($paths);
        return $paths;
    }

    /**
     * Build a single OpenAPI operation object.
     *
     * Auto-adds BearerAuth security for all routes. Injects 401/403 component
     * references for routes that declare required scopes.
     *
     * The private '_paginated' key in $openapi is consumed here and forwarded to
     * buildResponseObject() so that success response examples include the pagination
     * object. It is never written into the spec output.
     *
     * @param  string[]             $scopes
     * @param  array<string, mixed> $openapi Per-route OpenAPI meta from module.
     * @return array<string, mixed>
     */
    private function buildOperation(array $scopes, array $openapi, bool $public = false): array
    {
        $paginated = (bool) ($openapi['_paginated'] ?? false);
        unset($openapi['_paginated']);

        $operation = [
            'summary'     => (string) ($openapi['summary']     ?? ''),
            'description' => (string) ($openapi['description'] ?? ''),
            'tags'        => (array)  ($openapi['tags']        ?? []),
            'security'    => $public ? [] : [['BearerAuth' => $scopes]],
        ];

        if (!empty($openapi['deprecated'])) {
            $operation['deprecated'] = true;
        }

        if (!empty($openapi['parameters'])) {
            $operation['parameters'] = (array) $openapi['parameters'];
        }

        if (!empty($openapi['requestBody'])) {
            $operation['requestBody'] = (array) $openapi['requestBody'];
        }

        $responses = [];
        foreach ((array) ($openapi['responses'] ?? []) as $code => $resp) {
            $responses[(string) $code] = $this->buildResponseObject((int) $code, (array) $resp, $paginated);
        }

        if (!$public && !isset($responses['401'])) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthorized'];
        }
        if (!empty($scopes) && !isset($responses['403'])) {
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }

        \ksort($responses);
        $operation['responses'] = $responses !== [] ? $responses : new \stdClass();

        foreach ($openapi as $key => $value) {
            if (\str_starts_with((string) $key, 'x-')) {
                $operation[$key] = $value;
            }
        }

        return $operation;
    }

    /**
     * Build a single response object for a declared status code.
     *
     * All responses include a content block with the appropriate standard schema:
     *   - 2xx without 'schema' key → $ref StandardSuccessResponse, data: {}
     *   - 2xx with 'schema' key    → allOf[StandardSuccessResponse, {properties: {data: <schema>}}]
     *   - 4xx/5xx                  → $ref StandardErrorResponse
     *
     * Optional keys in the response entry:
     *   'schema'     — (2xx only) describes the 'data' field payload
     *   'code'       — symbolic error code shown in errors[0].code of the auto-generated example
     *   'example'    — manual override for the entire example object (any status)
     *   'no_content' — when true, omits the content block entirely (e.g. for 3xx redirects)
     *   'headers'    — map of response header definitions passed through to the OpenAPI object
     *
     * When $paginated is true, success examples include a representative pagination object.
     *
     * @param  int                  $code      HTTP status code.
     * @param  array<string, mixed> $resp      Declared response entry from module openapi def.
     * @param  bool                 $paginated Whether this operation supports pagination.
     * @return array<string, mixed>
     */
    private function buildResponseObject(int $code, array $resp, bool $paginated = false): array
    {
        $description = (string) ($resp['description'] ?? self::statusPhrase($code));

        $out = ['description' => $description];

        if (!empty($resp['headers']) && \is_array($resp['headers'])) {
            $out['headers'] = $resp['headers'];
        }

        if (!empty($resp['no_content'])) {
            return $out;
        }

        if (!empty($resp['content']) && \is_array($resp['content'])) {
            $out['content'] = $resp['content'];
            return $out;
        }

        $isSuccess = $code >= 200 && $code < 300;

        if ($isSuccess) {
            $dataSchema = isset($resp['schema']) ? (array) $resp['schema'] : null;

            $schema = $dataSchema !== null
                ? [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/StandardSuccessResponse'],
                        ['type' => 'object', 'properties' => ['data' => $dataSchema]],
                    ],
                ]
                : ['$ref' => '#/components/schemas/StandardSuccessResponse'];

            if (\array_key_exists('example', $resp)) {
                $example = JsonHandler::exampleSuccess(self::statusPhrase($code), $resp['example'], $code);
            } else {
                $additionalData = $paginated
                    ? ['pagination' => ['count' => 0, 'page' => 1, 'per_page' => 20, 'pages' => 0, 'has_more' => false]]
                    : [];
                $base         = JsonHandler::exampleSuccess(self::statusPhrase($code), new \stdClass(), $code, $additionalData);
                $isArrayData  = $dataSchema !== null && ($dataSchema['type'] ?? '') === 'array';
                $base['data'] = $isArrayData ? [] : new \stdClass();
                $example      = $base;
            }
        } else {
            $schema = ['$ref' => '#/components/schemas/StandardErrorResponse'];

            if (\array_key_exists('examples', $resp)) {
                $out['content'] = [
                    'application/json' => [
                        'schema'   => $schema,
                        'examples' => (array) $resp['examples'],
                    ],
                ];
                return $out;
            }

            $errCode    = isset($resp['code']) ? (string) $resp['code'] : null;
            $additional = $errCode !== null ? ['errors' => [['code' => $errCode]]] : [];
            $example    = \array_key_exists('example', $resp)
                ? $resp['example']
                : JsonHandler::exampleError($code, $description, $additional);
        }

        $out['content'] = [
            'application/json' => [
                'schema'  => $schema,
                'example' => $example,
            ],
        ];

        return $out;
    }

    /**
     * Build the components/schemas section.
     *
     * Merges schemas from JsonHandler (StandardSuccessResponse, StandardErrorResponse)
     * with any additional schemas registered via registerComponent().
     *
     * @return array<string, mixed>
     */
    private function buildSchemas(): array
    {
        $base = JsonHandler::openApiComponents()['schemas'] ?? [];
        return \array_merge($base, $this->components['schemas'] ?? []);
    }

    /**
     * Build standard reusable response components for 401 and 403.
     *
     * @return array<string, mixed>
     */
    private function buildStandardResponses(): array
    {
        $errorSchema = ['$ref' => '#/components/schemas/StandardErrorResponse'];

        return [
            'Unauthorized' => [
                'description' => 'Authentication token is missing or invalid.',
                'content'     => ['application/json' => ['schema' => $errorSchema]],
            ],
            'Forbidden' => [
                'description' => 'Token does not have the required scopes.',
                'content'     => ['application/json' => ['schema' => $errorSchema]],
            ],
        ];
    }

    /**
     * Return a standard HTTP reason phrase for a status code.
     *
     * @param  int    $code
     * @return string
     */
    private static function statusPhrase(int $code): string
    {
        return match ($code) {
            200 => 'OK',
            201 => 'Created',
            204 => 'No Content',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown',
        };
    }
}
