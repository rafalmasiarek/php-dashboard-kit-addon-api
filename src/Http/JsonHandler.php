<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;

/**
 * Single source of truth for all JSON responses in the API plugin.
 *
 * Core envelope (always present):
 *   { "status": "success"|"error", "code": <int>, "message": <string>, "data": <object> }
 *
 * Additional top-level keys (e.g. "errors", "meta") are registered once at bootstrap
 * via registerExtraFields() and are then included automatically in every response.
 *
 * Typical bootstrap (in ApiAddon::register()):
 *   JsonHandler::registerExtraFields([
 *       'errors' => ['schema' => [...], 'validator' => 'errors', 'default' => []],
 *       'meta'   => ['schema' => [...], 'validator' => 'data',   'default' => new \stdClass()],
 *   ]);
 *
 * @package rafalmasiarek\DashboardKitApi\Http
 */
final class JsonHandler
{
    /** @var string */
    public const K_STATUS  = 'status';
    /** @var string */
    public const K_CODE    = 'code';
    /** @var string */
    public const K_MESSAGE = 'message';
    /** @var string */
    public const K_DATA    = 'data';

    /**
     * Default HTTP phrases used for OpenAPI descriptions and examples.
     *
     * @var array<int, string>
     */
    private static array $HTTP_PHRASES = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        410 => 'Gone',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
    ];

    /**
     * Core field definitions. Fixed — extra fields are registered via registerExtraFields().
     *
     * @var array<string, array{schema: array<string, mixed>, validator: callable|string, default: mixed}>
     */
    private static array $CORE_FIELD_DEFS = [
        self::K_STATUS => [
            'schema'    => ['type' => 'string', 'description' => 'High-level outcome.'],
            'validator' => 'string',
            'default'   => 'success',
        ],
        self::K_CODE => [
            'schema'    => ['type' => 'integer', 'example' => 200, 'description' => 'HTTP-like status code.'],
            'validator' => 'int',
            'default'   => 200,
        ],
        self::K_MESSAGE => [
            'schema'    => ['type' => 'string', 'example' => 'OK', 'description' => 'Human-readable message.'],
            'validator' => 'string',
            'default'   => 'OK',
        ],
        self::K_DATA => [
            'schema'    => ['type' => 'object', 'additionalProperties' => true, 'description' => 'Arbitrary payload.'],
            'validator' => 'data',
            'default'   => [],
        ],
    ];

    /**
     * Extra field definitions registered at runtime.
     *
     * @var array<string, array{schema: array<string, mixed>, validator: callable|string, default: mixed}>
     */
    private static array $EXTRA_FIELD_DEFS = [];

    /**
     * Stack for scoped extra-field registration.
     *
     * @var array<int, array<string, array{schema: array<string, mixed>, validator: callable|string, default: mixed}>>
     */
    private static array $EXTRA_FIELD_STACK = [];

    /**
     * Extra OpenAPI components merged into openApiComponents() output.
     *
     * @var array<string, mixed>
     */
    private static array $EXTRA_COMPONENTS = [];

    /**
     * Whether to include per-schema examples in openApiComponents().
     *
     * @var bool
     */
    private static bool $INCLUDE_SCHEMA_EXAMPLES = true;

    // ------------------------------------------------------------------
    // Configuration helpers
    // ------------------------------------------------------------------

    /**
     * Toggle schema-level examples in OpenAPI output.
     *
     * @param  bool $on
     * @return void
     */
    public static function setIncludeSchemaExamples(bool $on): void
    {
        self::$INCLUDE_SCHEMA_EXAMPLES = $on;
    }

    /**
     * Merge extra OpenAPI components (securitySchemes, common responses, etc.).
     *
     * @param  array<string, mixed> $components
     * @return void
     */
    public static function setExtraOpenApiComponents(array $components): void
    {
        self::$EXTRA_COMPONENTS = \array_replace_recursive(self::$EXTRA_COMPONENTS, $components);
    }

    /**
     * Override or extend default HTTP phrases.
     *
     * @param  array<int, string> $map
     * @return void
     */
    public static function setHttpPhrases(array $map): void
    {
        foreach ($map as $code => $msg) {
            if (\is_int($code) && \is_string($msg) && $msg !== '') {
                self::$HTTP_PHRASES[$code] = $msg;
            }
        }
    }

    /**
     * Resolve the HTTP phrase for a given status code.
     *
     * @param  int $code
     * @return string
     */
    public static function httpPhrase(int $code): string
    {
        return self::$HTTP_PHRASES[$code] ?? ('HTTP ' . $code);
    }

    // ------------------------------------------------------------------
    // Extra field registration
    // ------------------------------------------------------------------

    /**
     * Register extra top-level fields included in every response.
     *
     * Each definition must contain:
     *   schema    — OpenAPI schema fragment
     *   validator — 'string' | 'int' | 'data' | 'errors' | callable($value, bool $strict): mixed
     *   default   — value used when the field is absent in lenient mode
     *
     * @param  array<string, array{schema: array<string, mixed>, validator: callable|string, default: mixed}> $defs
     * @return void
     * @throws InvalidArgumentException When a name conflicts with a core key or definition is incomplete.
     */
    public static function registerExtraFields(array $defs): void
    {
        foreach ($defs as $key => $def) {
            if (!\is_string($key) || $key === '') {
                throw new InvalidArgumentException('Extra field name must be a non-empty string.');
            }
            if (isset(self::$CORE_FIELD_DEFS[$key])) {
                throw new InvalidArgumentException("Extra field '{$key}' conflicts with a core envelope key.");
            }
            if (!isset($def['schema'], $def['validator'])) {
                throw new InvalidArgumentException("Extra field '{$key}' must define 'schema' and 'validator'.");
            }
            if (!\array_key_exists('default', $def)) {
                $def['default'] = null;
            }
            self::$EXTRA_FIELD_DEFS[$key] = $def;
        }
    }

    /**
     * Push a temporary set of extra fields. Restore with popExtraFields().
     *
     * @param  array<string, array{schema: array<string, mixed>, validator: callable|string, default: mixed}> $defs
     * @return void
     */
    public static function pushExtraFields(array $defs): void
    {
        self::$EXTRA_FIELD_STACK[] = self::$EXTRA_FIELD_DEFS;
        self::registerExtraFields($defs);
    }

    /**
     * Pop the last pushed extra-field snapshot. Clears extras when the stack is empty.
     *
     * @return void
     */
    public static function popExtraFields(): void
    {
        if (!empty(self::$EXTRA_FIELD_STACK)) {
            self::$EXTRA_FIELD_DEFS = \array_pop(self::$EXTRA_FIELD_STACK);
        } else {
            self::$EXTRA_FIELD_DEFS = [];
        }
    }

    /**
     * Run a callback with temporarily registered extra fields.
     *
     * @template T
     * @param  array<string, array{schema: array<string, mixed>, validator: callable|string, default: mixed}> $defs
     * @param  callable(): T $callback
     * @return T
     */
    public static function withExtraFields(array $defs, callable $callback): mixed
    {
        self::pushExtraFields($defs);
        try {
            return $callback();
        } finally {
            self::popExtraFields();
        }
    }

    // ------------------------------------------------------------------
    // Response builders
    // ------------------------------------------------------------------

    /**
     * Build a JSON response with the standard envelope.
     *
     * @param  ResponseInterface    $response
     * @param  string               $status         'success' or 'error'.
     * @param  int                  $code           HTTP status code.
     * @param  string               $message        Human-readable message.
     * @param  mixed                $data           Payload (array or object).
     * @param  array<string, mixed> $additionalData Values for registered extra fields.
     * @param  bool                 $strict         Throw on invalid field values instead of coercing.
     * @return ResponseInterface
     */
    public static function respond(
        ResponseInterface $response,
        string $status,
        int $code,
        string $message,
        mixed $data = [],
        array $additionalData = [],
        bool $strict = false
    ): ResponseInterface {
        $isSuccess = ($code >= 200 && $code < 300);

        $errorsKey   = null;
        $errorsValue = null;

        foreach (self::$EXTRA_FIELD_DEFS as $k => $def) {
            if (($def['validator'] ?? null) === 'errors') {
                $errorsKey   = $k;
                $errorsValue = $additionalData[$k] ?? $def['default'];
                break;
            }
        }

        if (!$isSuccess) {
            $status = 'error';

            $hasData = \is_array($data)
                ? !empty($data)
                : (\is_object($data) && (array) $data !== []);

            if ($errorsKey !== null) {
                $isErrorsEmpty = !\is_array($errorsValue) || $errorsValue === [];
                if ($isErrorsEmpty && $hasData) {
                    $errorsValue = [['detail' => $data]];
                }
            }

            $data = new \stdClass();
        } else {
            if ($errorsKey !== null && !\is_array($errorsValue)) {
                $errorsValue = [];
            }
        }

        $payload = [
            self::K_STATUS  => $status,
            self::K_CODE    => $code,
            self::K_MESSAGE => $message,
            self::K_DATA    => $data,
        ];

        foreach (self::$EXTRA_FIELD_DEFS as $key => $def) {
            if ($key === $errorsKey) {
                $payload[$key] = $errorsValue ?? $def['default'];
                continue;
            }
            $value = \array_key_exists($key, $additionalData) ? $additionalData[$key] : $def['default'];
            if ($value === null && ($def['omit_if_null'] ?? false)) {
                continue;
            }
            $payload[$key] = $value;
        }

        // Write-through: any additionalData keys not covered by core or registered extra fields
        // are appended directly to the payload. Allows modules to inject ad-hoc fields
        // (e.g. 'facets') without requiring global extra-field registration.
        foreach ($additionalData as $key => $value) {
            if (!\array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        $normalized = self::validateAndNormalize($payload, $strict);
        $json       = \json_encode($normalized, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $response->getBody()->write(\is_string($json) ? $json : '');

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus((int) $normalized[self::K_CODE]);
    }

    /**
     * Build a 2xx success response.
     *
     * @param  ResponseInterface    $response
     * @param  string               $message
     * @param  mixed                $data
     * @param  int                  $http           HTTP status (default 200).
     * @param  array<string, mixed> $additionalData
     * @return ResponseInterface
     */
    public static function ok(
        ResponseInterface $response,
        string $message = 'OK',
        mixed $data = [],
        int $http = 200,
        array $additionalData = []
    ): ResponseInterface {
        return self::respond($response, 'success', $http, $message, $data, $additionalData);
    }

    /**
     * Build an error response using a symbolic error code looked up in an error map.
     *
     * Error map format:
     *   [ 'CODE' => ['http' => 404, 'msg' => 'Not found.'], ... ]
     * Pipe-separated codes and /regex/ patterns are also supported as map keys.
     *
     * @param  ResponseInterface    $response
     * @param  string               $code           Symbolic error code.
     * @param  array<string, mixed> $map            Error map.
     * @param  int|null             $httpOverride   Override the HTTP status from the map.
     * @param  string|null          $messageOverride Override the message from the map.
     * @param  string|null          $field          Field name for the errors array entry.
     * @param  string|null          $detail         Additional detail for the errors array entry.
     * @param  mixed                $data
     * @param  array<string, mixed> $additionalData
     * @return ResponseInterface
     */
    public static function err(
        ResponseInterface $response,
        string $code,
        array $map,
        ?int $httpOverride = null,
        ?string $messageOverride = null,
        ?string $field = null,
        ?string $detail = null,
        mixed $data = [],
        array $additionalData = []
    ): ResponseInterface {
        $entry = self::lookupError($code, $map);

        $http = $httpOverride ?? (int) ($entry['http'] ?? 400);
        $msg  = $messageOverride ?? (string) ($entry['msg'] ?? 'Unknown error.');

        foreach (self::$EXTRA_FIELD_DEFS as $k => $def) {
            if (($def['validator'] ?? null) === 'errors') {
                $list = $additionalData[$k] ?? [];
                if (!\is_array($list)) {
                    $list = [];
                }
                $item   = ['code' => $code] + \array_filter(['field' => $field, 'detail' => $detail]);
                $list[] = $item;
                $additionalData[$k] = $list;
                break;
            }
        }

        return self::respond($response, 'error', $http, $msg, $data, $additionalData);
    }

    /**
     * Build a 401 Unauthorized response with a WWW-Authenticate: Bearer header.
     *
     * @param  ResponseInterface    $response
     * @param  string               $message
     * @param  array<string, mixed> $additionalData
     * @return ResponseInterface
     */
    public static function unauthorized(
        ResponseInterface $response,
        string $message = 'Unauthorized.',
        array $additionalData = []
    ): ResponseInterface {
        $response = $response->withHeader(
            'WWW-Authenticate',
            'Bearer realm="api", error="invalid_token", error_description="' . $message . '"'
        );
        return self::respond($response, 'error', 401, $message, [], $additionalData);
    }

    /**
     * Build a 403 Forbidden response with a WWW-Authenticate: Bearer header.
     *
     * When required scopes are provided they are included in the WWW-Authenticate header
     * and in meta.required_scopes (if 'meta' is registered as an extra field).
     *
     * @param  ResponseInterface    $response
     * @param  string               $message
     * @param  string[]             $requiredScopes
     * @param  array<string, mixed> $additionalData
     * @return ResponseInterface
     */
    public static function forbidden(
        ResponseInterface $response,
        string $message = 'Insufficient scope.',
        array $requiredScopes = [],
        array $additionalData = []
    ): ResponseInterface {
        $hdr = 'Bearer error="insufficient_scope"';
        if ($requiredScopes !== []) {
            $hdr .= ', scope="' . \implode(' ', $requiredScopes) . '"';
            $additionalData = \array_replace_recursive($additionalData, [
                'meta' => ['required_scopes' => \array_values($requiredScopes)],
            ]);
        }
        $response = $response->withHeader('WWW-Authenticate', $hdr);
        return self::respond($response, 'error', 403, $message, [], $additionalData);
    }

    /**
     * Build a response from a service result array.
     *
     * Expected result shape: [ 'ok' => bool, 'data' => mixed, 'message' => string,
     *                          'code' => string, 'field' => string, 'detail' => string ]
     *
     * @param  ResponseInterface    $response
     * @param  array<string, mixed> $result
     * @param  array<string, mixed> $errorMap
     * @param  int|null             $httpOverride
     * @param  array<string, mixed> $additionalData
     * @return ResponseInterface
     */
    public static function fromResult(
        ResponseInterface $response,
        array $result,
        array $errorMap,
        ?int $httpOverride = null,
        array $additionalData = []
    ): ResponseInterface {
        $isOk = (bool) ($result['ok'] ?? false);

        if ($isOk) {
            return self::ok(
                $response,
                (string) ($result['message'] ?? 'OK'),
                $result['data'] ?? [],
                $httpOverride ?? 200,
                $additionalData
            );
        }

        return self::err(
            $response,
            (string) ($result['code'] ?? 'SERVER_ERROR'),
            $errorMap,
            $httpOverride,
            isset($result['message']) ? (string) $result['message'] : null,
            isset($result['field'])   ? (string) $result['field']   : null,
            isset($result['detail'])  ? (string) $result['detail']  : null,
            $result['data'] ?? [],
            $additionalData
        );
    }

    // ------------------------------------------------------------------
    // OpenAPI helpers
    // ------------------------------------------------------------------

    /**
     * Build an OpenAPI response object for a given HTTP status code.
     *
     * @param  int         $code
     * @param  string|null $description
     * @param  array|null  $example
     * @return array<string, mixed>
     */
    public static function openApiStandardResponseForCode(int $code, ?string $description = null, ?array $example = null): array
    {
        $schemaRef = ($code >= 200 && $code < 300)
            ? '#/components/schemas/StandardSuccessResponse'
            : '#/components/schemas/StandardErrorResponse';

        $desc = $description ?? self::httpPhrase($code);

        if ($example === null) {
            $additional = self::buildAdditionalExample($code < 200 || $code >= 300);
            $example    = ($code >= 200 && $code < 300)
                ? self::exampleSuccess(self::httpPhrase($code), (object) [], $code, $additional)
                : self::exampleError($code, self::httpPhrase($code), $additional);
        }

        return [
            'description' => $desc,
            'content'     => [
                'application/json' => [
                    'schema'  => ['$ref' => $schemaRef],
                    'example' => $example,
                ],
            ],
        ];
    }

    /**
     * Build the OpenAPI components section (schemas for success and error envelopes).
     *
     * @return array<string, mixed>
     */
    public static function openApiComponents(): array
    {
        $defs       = self::fieldDefs();
        $properties = [];

        foreach ($defs as $key => $meta) {
            $schema = $meta['schema'];
            if (\is_array($schema) && \array_key_exists('example', $schema)) {
                unset($schema['example']);
            }
            $properties[$key] = $schema;
        }

        $base = [
            'type'       => 'object',
            'required'   => [self::K_STATUS, self::K_CODE, self::K_MESSAGE],
            'properties' => $properties,
        ];

        $successExample = [
            self::K_STATUS  => 'success',
            self::K_CODE    => 200,
            self::K_MESSAGE => 'OK',
            self::K_DATA    => (object) [],
        ];
        $errorExample = [
            self::K_STATUS  => 'error',
            self::K_CODE    => 422,
            self::K_MESSAGE => 'Unprocessable Entity',
            self::K_DATA    => (object) [],
        ];

        foreach (self::$EXTRA_FIELD_DEFS as $key => $def) {
            $successExample[$key] = self::exampleForDef($def, false);
            $errorExample[$key]   = self::exampleForDef($def, true);
        }

        $successSchema = $base;
        $errorSchema   = $base;

        if (self::$INCLUDE_SCHEMA_EXAMPLES) {
            $successSchema['example'] = $successExample;
            $errorSchema['example']   = $errorExample;
        }

        $components = [
            'schemas' => [
                'StandardSuccessResponse' => $successSchema,
                'StandardErrorResponse'   => $errorSchema,
            ],
        ];

        return \array_replace_recursive($components, self::$EXTRA_COMPONENTS);
    }

    /**
     * Build an example envelope array.
     *
     * @param  string               $status
     * @param  int                  $code
     * @param  string               $message
     * @param  mixed                $data
     * @param  array<string, mixed> $additionalData
     * @return array<string, mixed>
     */
    public static function example(string $status, int $code, string $message, mixed $data = [], array $additionalData = []): array
    {
        $out = [
            self::K_STATUS  => $status,
            self::K_CODE    => $code,
            self::K_MESSAGE => $message,
            self::K_DATA    => (\is_array($data) && $data === []) ? (object) [] : $data,
        ];
        foreach (self::$EXTRA_FIELD_DEFS as $key => $def) {
            $out[$key] = \array_key_exists($key, $additionalData) ? $additionalData[$key] : $def['default'];
        }
        return $out;
    }

    /**
     * Build a success example envelope.
     *
     * @param  string               $message
     * @param  mixed                $data
     * @param  int                  $code
     * @param  array<string, mixed> $additionalData
     * @return array<string, mixed>
     */
    public static function exampleSuccess(string $message = 'OK', mixed $data = [], int $code = 200, array $additionalData = []): array
    {
        return self::example('success', $code, $message, $data, $additionalData);
    }

    /**
     * Build an error example envelope.
     *
     * @param  int                  $code
     * @param  string               $message
     * @param  array<string, mixed> $additionalData
     * @return array<string, mixed>
     */
    public static function exampleError(int $code, string $message = 'Error', array $additionalData = []): array
    {
        return self::example('error', $code, $message, (object) [], $additionalData);
    }

    /**
     * Normalize a JSON schema fragment: fixes $ref mixed with other keys.
     *
     * @param  array<string, mixed> $schema
     * @param  bool                 $forceRaw Remove Standard*Response refs entirely.
     * @return array<string, mixed>
     */
    public static function openApiNormalizeSchema(array $schema, bool $forceRaw = false): array
    {
        if (!isset($schema['$ref']) || !\is_string($schema['$ref'])) {
            return $schema;
        }

        $ref            = $schema['$ref'];
        $isStandardRef  = $ref === '#/components/schemas/StandardSuccessResponse'
                       || $ref === '#/components/schemas/StandardErrorResponse';

        if ($forceRaw && $isStandardRef) {
            return ['type' => 'object', 'additionalProperties' => true];
        }

        if (\count($schema) > 1) {
            $extra = $schema;
            unset($extra['$ref']);
            if ($extra === []) {
                return ['$ref' => $ref];
            }
            return ['allOf' => [['$ref' => $ref], $extra]];
        }

        return $schema;
    }

    /**
     * Normalize an OpenAPI operation array (fixes schema fragments and removes
     * conflicting example/examples pairs).
     *
     * @param  array<string, mixed> $operation
     * @param  bool                 $forceRaw
     * @return array<string, mixed>
     */
    public static function openApiNormalizeOperation(array $operation, bool $forceRaw = false): array
    {
        if (!isset($operation['responses']) || !\is_array($operation['responses'])) {
            return $operation;
        }

        foreach ($operation['responses'] as $code => $resp) {
            if (!\is_array($resp)) {
                continue;
            }

            $mt = $resp['content']['application/json'] ?? null;
            if (!\is_array($mt)) {
                continue;
            }

            if (isset($mt['schema']) && \is_array($mt['schema'])) {
                $mt['schema'] = self::openApiNormalizeSchema($mt['schema'], $forceRaw);
            }

            if (isset($mt['examples']) && \is_array($mt['examples']) && $mt['examples'] !== []) {
                unset($mt['example']);
            }

            $resp['content']['application/json']  = $mt;
            $operation['responses'][$code]         = $resp;
        }

        return $operation;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{schema: array<string, mixed>, validator: callable|string, default: mixed}>
     */
    private static function fieldDefs(): array
    {
        return self::$CORE_FIELD_DEFS + self::$EXTRA_FIELD_DEFS;
    }

    /**
     * Validate and coerce every field in the payload according to its definition.
     *
     * Fields declared with omit_if_null that were not placed in $payload by respond()
     * are skipped entirely so they remain absent from the output.
     *
     * @param  array<string, mixed> $payload
     * @param  bool                 $strict
     * @return array<string, mixed>
     * @throws InvalidArgumentException In strict mode when a value is of the wrong type.
     */
    private static function validateAndNormalize(array $payload, bool $strict): array
    {
        $out = [];
        foreach (self::fieldDefs() as $key => $def) {
            if (($def['omit_if_null'] ?? false) && !\array_key_exists($key, $payload)) {
                continue;
            }
            $val       = $payload[$key] ?? $def['default'];
            $validator = $def['validator'];

            switch ($validator) {
                case 'string':
                    if (!\is_string($val)) {
                        if ($strict) {
                            throw new InvalidArgumentException("'{$key}' must be a string.");
                        }
                        $val = (string) $val;
                    }
                    break;

                case 'int':
                    if (!\is_int($val)) {
                        if ($strict) {
                            throw new InvalidArgumentException("'{$key}' must be an integer.");
                        }
                        $val = (int) $val;
                    }
                    break;

                case 'data':
                    if (!\is_array($val) && !\is_object($val)) {
                        if ($strict) {
                            throw new InvalidArgumentException("'{$key}' must be array|object.");
                        }
                        $val = new \stdClass();
                    }
                    break;

                case 'errors':
                    if (!\is_array($val)) {
                        if ($strict) {
                            throw new InvalidArgumentException("'{$key}' must be an array.");
                        }
                        $val = [];
                    }
                    $val = \array_values(\array_map(
                        static function (mixed $item): object {
                            if (\is_array($item)) {
                                return self::isAssoc($item) ? (object) $item : (object) ['value' => $item];
                            }
                            if (\is_object($item)) {
                                return $item;
                            }
                            return (object) ['message' => (string) $item];
                        },
                        $val
                    ));
                    break;

                default:
                    if (\is_callable($validator)) {
                        $val = $validator($val, $strict);
                    }
                    break;
            }

            $out[$key] = $val;
        }

        // Pass through any keys not covered by known field definitions so that
        // ad-hoc fields injected via the additionalData write-through survive normalization.
        foreach ($payload as $key => $value) {
            if (!\array_key_exists($key, $out)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Look up an error map entry by code. Supports exact match, pipe-separated codes, and /regex/.
     *
     * @param  string               $code
     * @param  array<string, mixed> $map
     * @return array<string, mixed>
     */
    private static function lookupError(string $code, array $map): array
    {
        foreach ($map as $pattern => $entry) {
            if ($pattern === $code) {
                return $entry;
            }
            if (\strpos($pattern, '|') !== false && \in_array($code, \explode('|', $pattern), true)) {
                return $entry;
            }
            if (\strlen($pattern) > 2 && $pattern[0] === '/' && \substr($pattern, -1) === '/') {
                if (@\preg_match($pattern, $code) === 1) {
                    return $entry;
                }
            }
        }
        return [];
    }

    /**
     * Return true when the array has string keys (associative).
     *
     * @param  array<mixed, mixed> $arr
     * @return bool
     */
    private static function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return \array_keys($arr) !== \range(0, \count($arr) - 1);
    }

    /**
     * Build an example value for an extra field definition.
     *
     * @param  array{schema: array<string, mixed>, validator: callable|string, default: mixed} $def
     * @param  bool $forError
     * @return mixed
     */
    private static function exampleForDef(array $def, bool $forError): mixed
    {
        if (($def['validator'] ?? null) === 'errors') {
            return $forError ? [['message' => 'Validation failed']] : [];
        }
        $d = $def['default'] ?? null;
        if (\is_array($d) && $d === [] && ($def['schema']['type'] ?? null) === 'object') {
            return (object) [];
        }
        return $d;
    }

    /**
     * Build additionalData populated with per-extra-field example values.
     *
     * @param  bool $forError
     * @return array<string, mixed>
     */
    private static function buildAdditionalExample(bool $forError): array
    {
        $additional = [];
        foreach (self::$EXTRA_FIELD_DEFS as $key => $def) {
            $additional[$key] = self::exampleForDef($def, $forError);
        }
        return $additional;
    }
}
