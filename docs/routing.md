## Defining API routes

Routes are declared under the `api` key in `module.php`. The contract — parameters, request body, and responses — is defined once at the top level. The framework uses it for both OpenAPI generation and runtime behavior: type casting, error map derivation, and response envelope building.

```php
'api' => [
    'v1' => [
        'GET /{id}' => [
            'scopes'  => ['notes:read'],
            'openapi' => [
                'summary'     => 'Get a note',
                'description' => 'Returns a single note by ID.',
                'tags'        => ['notes'],
            ],
            'params' => [
                'id' => ['in' => 'path', 'type' => 'integer', 'required' => true],
            ],
            'body' => [                             // optional — JSON schema of the request body
                'type'       => 'object',
                'required'   => ['body'],
                'properties' => ['body' => ['type' => 'string']],
            ],
            'responses' => [
                200 => ['description' => 'Note found', 'schema' => ['type' => 'object', ...]],
                404 => ['description' => 'Note not found', 'code' => 'NOT_FOUND'],
            ],
            'handler' => function (array $params, mixed $body, ContainerInterface $c): array {
                // $params['id'] is already cast to int (declared type: integer)
                // $body is the parsed JSON request body (or [] when absent)
                $stmt = $c->get(PDO::class)->prepare('SELECT * FROM notes WHERE id = ?');
                $stmt->execute([$params['id']]);
                $note = $stmt->fetch(PDO::FETCH_ASSOC);
                return $note !== false
                    ? ['data' => $note]
                    : ['error' => 'NOT_FOUND'];
            },
        ],
    ],
],
```

### Handler return shape

| Return value | Result |
|---|---|
| `['data' => $payload]` | 200 OK |
| `['data' => ..., 'http' => 201, 'message' => 'Created']` | Custom status and message |
| `['error' => 'CODE']` | HTTP status and message from `responses` declaration |
| `['error' => 'CODE', 'message' => 'Custom message.']` | Overrides the message from `responses` |
| `['error' => 'CODE', 'field' => 'email', 'detail' => '...']` | Adds `field` and `detail` to `errors[0]` |
| `['error' => 'CODE', 'http' => 410]` | Overrides the HTTP status from `responses` |

`field` and `detail` are appended to the `errors` array alongside `code`:

```json
{
  "errors": [{ "code": "BODY_REQUIRED", "field": "body", "detail": "Must be at least 1 character." }]
}
```

All override keys can be combined freely:

```php
return ['error' => 'VALIDATION_FAILED', 'message' => 'Email is invalid.', 'field' => 'email', 'detail' => 'Must contain @.'];
```

### What the framework does automatically

- Casts path and query params to declared types (`integer`, `number`, `boolean`, `string`)
- Derives the error map from `responses` entries that carry a `'code'` key
- Validates the request body root type and per-property types before calling the handler
- Calls `JsonHandler::ok()` or `JsonHandler::err()` with the correct envelope
- Builds OpenAPI `parameters`, `requestBody`, and `responses` from the same top-level keys
- For paginated endpoints: injects `page` / `limit` / `offset` into `$params`, computes the `pagination` field, and adds `page` / `per_page` query params to the OpenAPI spec automatically

### params

Declares path and query parameters. The `type` field drives both OpenAPI and runtime casting:

```php
'params' => [
    'id'     => ['in' => 'path',  'type' => 'integer', 'required' => true],
    'page'   => ['in' => 'query', 'type' => 'integer'],
    'active' => ['in' => 'query', 'type' => 'boolean'],
],
```

Supported types: `integer`, `number`, `boolean`, `string`.

### body

Optional. Declares the JSON schema of the request body. Used as the OpenAPI `requestBody` schema and made available to the handler as `$body`:

```php
'body' => [
    'type'       => 'object',
    'required'   => ['title'],
    'properties' => [
        'title' => ['type' => 'string', 'minLength' => 1],
        'draft' => ['type' => 'boolean'],
    ],
],
```

The framework validates the body before calling the handler and returns an error if the structure is invalid:

| Error code | HTTP | Condition |
|---|---|---|
| `MALFORMED_JSON` | 400 | `Content-Type: application/json` sent with non-JSON content |
| `INVALID_BODY_TYPE` | 400 | Body root type does not match the declared `type` |
| `INVALID_FIELD_TYPE` | 422 | A declared property has the wrong type |

These errors are returned by the framework directly — they do not need to be listed in the route's `responses` declaration.

### responses

Declares all possible response codes. Entries with a `'code'` key become part of the runtime error map; entries with a `'schema'` key describe the `data` payload in the OpenAPI spec:

```php
'responses' => [
    200 => [
        'description' => 'Notes list',
        'schema'      => ['type' => 'array', 'items' => ['type' => 'object']],
    ],
    201 => ['description' => 'Created'],          // data: {} (no schema needed)
    404 => ['description' => 'Not found', 'code' => 'NOT_FOUND'],
    422 => ['description' => 'body is required',  'code' => 'BODY_REQUIRED'],
],
```

### pagination

Optional. Enables automatic pagination for list endpoints. When declared, the framework injects `page`, `limit`, and `offset` into `$params` and computes the `pagination` envelope field from the `total` key returned by the handler:

```php
'GET /' => [
    'scopes'     => ['notes:read'],
    'pagination' => [
        'default_limit' => 20,   // items per page when client does not specify
        'max_limit'     => 100,  // upper cap on per_page
    ],
    'responses' => [
        200 => ['description' => 'Notes list', 'schema' => ['type' => 'array', ...]],
    ],
    'handler' => function (array $params, mixed $body, ContainerInterface $c): array {
        // $params['page'], $params['limit'], $params['offset'] are injected automatically
        $total = (int) $db->query('SELECT COUNT(*) FROM notes')->fetchColumn();
        $stmt  = $db->prepare('SELECT * FROM notes LIMIT :limit OFFSET :offset');
        $stmt->bindValue(':limit',  $params['limit'],  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $params['offset'], PDO::PARAM_INT);
        $stmt->execute();
        return ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => $total];
    },
],
```

Client query params: `?page=2&per_page=10`. The response includes a top-level `pagination` object:

```json
{
  "status": "success",
  "code": 200,
  "message": "OK",
  "data": [...],
  "errors": [],
  "meta": {},
  "pagination": {
    "count": 86,
    "page": 2,
    "per_page": 10,
    "pages": 9,
    "has_more": true
  }
}
```

The `pagination` field is absent from non-paginated endpoints. The OpenAPI spec automatically includes `page` and `per_page` query parameters and a representative pagination example for 2xx responses.

### OpenAPI specification

A full OpenAPI 3.1 spec is generated automatically from all module route definitions and served at a configurable endpoint:

```php
// index.php
'api' => [
    'prefix'  => '',          // optional: 'api' → routes at /api/v1/*, spec at /api/openapi.json
    'openapi' => [
        'path'       => '/openapi.json',  // default: /{prefix}/openapi.json
        'visibility' => 'admin',          // 'admin' | 'user' | 'public'
        'info' => [
            'title'   => 'My App API',
            'version' => '1.0.0',
        ],
        'servers' => [['url' => '/']],
    ],
],
```

The `openapi` sub-key on a route carries documentation-only metadata:

```php
'openapi' => [
    'summary'     => 'List notes',
    'description' => 'Returns all notes ordered by creation date descending.',
    'tags'        => ['notes'],
    'deprecated'  => false,
],
```

Standard 401/403 responses are auto-injected for all routes that declare scopes.

Other plugins can extend the spec when the API plugin is installed:

```php
if ($container->has(OpenApiRegistry::class)) {
    $container->get(OpenApiRegistry::class)
        ->registerTag('scheduler', 'Scheduler endpoints');
}
```

To use a dedicated log channel, add `logger.api` to your dashboard-kit logging configuration:

```php
'logging' => [
    'channels' => [
        'api' => ['path' => storage_path('logs/api.log'), 'level' => 'info'],
    ],
],
```
