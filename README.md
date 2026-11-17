# dashboard-kit-addon-api
[![Packagist Version](https://img.shields.io/packagist/v/rafalmasiarek/dashboard-kit-addon-api)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-api)
[![Total Downloads](https://img.shields.io/packagist/dt/rafalmasiarek/dashboard-kit-addon-api)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-api)
[![PHP](https://img.shields.io/packagist/php-v/rafalmasiarek/dashboard-kit-addon-api)](https://packagist.org/packages/rafalmasiarek/dashboard-kit-addon-api)
[![License](https://img.shields.io/badge/license-BUSL--1.1-red)](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/LICENSE)

API plugin for [rafalmasiarek/dashboard-kit](https://github.com/rafalmasiarek/php-dashboard-kit). Adds Bearer token authentication with per-route scope enforcement, admin UI for token and scope management, user self-service token page, and a structured audit trail.




## Requirements

- PHP 8.2+
- `rafalmasiarek/dashboard-kit`
- MySQL 8 or SQLite

## Installation

```bash
composer require rafalmasiarek/dashboard-kit-addon-api
```

The plugin is registered automatically when the package is installed. No additional configuration is required.

## Features

### Token authentication

Every versioned API route (`/v1/...`) is protected by a Bearer token:

```http
GET /v1/notes HTTP/1.1
Authorization: Bearer <token>
```

Tokens are created by users from `/settings/api-tokens` or by admins from `/admin/api/tokens`.

### Scope enforcement

Routes declare required scopes. A token must carry all required scopes to pass:

```php
// modules/notes/module.php
'api' => [
    'v1' => [
        'GET /'  => ['scopes' => ['notes:read'],  'handler' => ...],
        'POST /' => ['scopes' => ['notes:write'], 'handler' => ...],
    ],
],
```

Super-scopes `*` and `admin:*` bypass all scope checks. Namespace wildcards (`notes:*`) satisfy any `notes:{action}` requirement.

### Scope management

Admins define global scopes and assign subsets to individual users:

1. Create scopes at `/admin/api/scopes` (e.g. `notes:read`, `notes:write`)
2. Assign scopes to a user via Admin → Users → API Scopes
3. User can now create tokens using only their assigned scopes
4. Admins can create tokens with any scope regardless of assignments

### JSON response envelope

All API responses follow a consistent shape:

```json
{
  "status": "success",
  "code": 200,
  "message": "OK",
  "data": {},
  "errors": [],
  "meta": {}
}
```

Error responses:

```json
{
  "status": "error",
  "code": 403,
  "message": "Insufficient scope.",
  "data": {},
  "errors": [],
  "meta": { "required_scopes": ["notes:read"] }
}
```

### Audit trail

Every API request is logged to the `logger.api` channel (falls back to `logger.audit`):

```
api.token.allowed  token=5L9eh4oT…  user_id=abc  email=user@example.com  ip=1.2.3.4
                   method=GET  path=/v1/notes  scopes_required=["notes:read"]  scopes_granted=["notes:read"]

api.token.denied   deny_reason=insufficient_scope  token=5L9eh4oT…  ip=1.2.3.4
                   method=POST  path=/v1/notes  scopes_required=["notes:write"]

api.token.denied   deny_reason=missing_token   ip=1.2.3.4  method=GET  path=/v1/notes
api.token.denied   deny_reason=invalid_token   ip=1.2.3.4  method=GET  path=/v1/notes
```

Token values are never logged in full — only the first 8 characters are stored as a correlation prefix.


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


## Admin routes

| Route | Description |
|-------|-------------|
| `GET /admin/api/scopes` | List all scopes |
| `POST /admin/api/scopes` | Create a scope |
| `POST /admin/api/scopes/{id}/delete` | Delete a scope |
| `GET /admin/api/tokens` | List all tokens (all users) |
| `POST /admin/api/tokens` | Create a token (any scope) |
| `POST /admin/api/tokens/{token}/revoke` | Revoke any token |
| `GET /admin/api/users/{id}/scopes` | View/edit scope assignments for a user |
| `POST /admin/api/users/{id}/scopes` | Save scope assignments |

## User routes

| Route | Description |
|-------|-------------|
| `GET /settings/api-tokens` | List own tokens, create new token |
| `POST /settings/api-tokens` | Create token (assigned scopes only) |
| `POST /settings/api-tokens/{token}/revoke` | Revoke own token |

## Database tables

Auto-migrated on first boot:

| Table | Description |
|-------|-------------|
| `user_tokens` | Issued tokens with optional subject and expiry |
| `token_scopes` | Token → scope assignments |
| `scopes` | Global scope registry (name, category, description) |
| `user_scopes` | Per-user scope assignments |

System tables managed by `dashboard-kit` core (never prefixed):

| Table | Description |
|-------|-------------|
| `_schema_state` | Schema hash per table — drives the schema sync fast path |
| `_table_version` | Per-table write counters — drives SELECT cache invalidation |
| `_query_cache` | Cached SELECT results with TTL |


## Index

### Classes

- [`ApiScopeRepository`](#apiscoperepository) — `rafalmasiarek\DashboardKitApi\Admin`
- [`UserScopeRepository`](#userscoperepository) — `rafalmasiarek\DashboardKitApi\Admin`
- [`ApiAddon`](#apiaddon) — `rafalmasiarek\DashboardKitApi`
- [`ApiTokenAuditLog`](#apitokenauditlog) — `rafalmasiarek\DashboardKitApi\Audit`
- [`ApiTokenAuditMiddleware`](#apitokenauditmiddleware) — `rafalmasiarek\DashboardKitApi\Audit`
- [`DbTokenRepository`](#dbtokenrepository) — `rafalmasiarek\DashboardKitApi\Auth`
- [`DbTokenValidator`](#dbtokenvalidator) — `rafalmasiarek\DashboardKitApi\Auth`
- [`TokenAuthMiddleware`](#tokenauthmiddleware) — `rafalmasiarek\DashboardKitApi\Auth`
- [`TokenScopeMiddleware`](#tokenscopemiddleware) — `rafalmasiarek\DashboardKitApi\Auth`
- [`JsonHandler`](#jsonhandler) — `rafalmasiarek\DashboardKitApi\Http`
- [`OpenApiRegistry`](#openapiregistry) — `rafalmasiarek\DashboardKitApi\OpenApi`
- [`RedirectResponseDriver`](#redirectresponsedriver) — `rafalmasiarek\DashboardKitApi\ResponseDriver`
- [`ApiSchemaProvider`](#apischemaprovider) — `rafalmasiarek\DashboardKitApi\Schema`

### Interfaces

- [`TokenRepositoryInterface`](#tokenrepositoryinterface) — `rafalmasiarek\DashboardKitApi\Auth`
- [`TokenValidatorInterface`](#tokenvalidatorinterface) — `rafalmasiarek\DashboardKitApi\Auth`
- [`ResponseDriverInterface`](#responsedriverinterface) — `rafalmasiarek\DashboardKitApi`

## `ApiScopeRepository`

*final class* — `rafalmasiarek\DashboardKitApi\Admin\ApiScopeRepository` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L18)

Read/write access to the API scope dictionary table. Scopes follow the convention `category:action` (e.g. `notes:read`, `cron:run`). The `category` and `action` derived fields are computed on the fly from the stored `name` by splitting on the first colon.

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$tblScopes` | `string` | private | Scopes table name. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#apiscoperepository-__construct) | public | — | — |
| [`all()`](#apiscoperepository-all) | public | `array<int, array{id: int, name: string, description: string\|null, category: string, action: string}>` | Return all scope rows enriched with derived category and action fields. Each returned item has the shape: id (int) name (string) full name, e.g. 'notes:read' description (string\|null) category (string) part before first colon, e.g. 'notes' action (string) part after first colon, e.g. 'read'; equals name when no colon |
| [`allGrouped()`](#apiscoperepository-allgrouped) | public | `array<string, array<int, array{id: int, name: string, description: string\|null, category: string, action: string}>>` | Return scopes grouped by category. Returns a dict keyed by category string, where each value is a list of scope rows (same shape as {@see all()}) that belong to that category. |
| [`create()`](#apiscoperepository-create) | public | `void` | Insert a new scope. Silently ignores duplicates (INSERT IGNORE). |
| [`deleteById()`](#apiscoperepository-deletebyid) | public | `bool` | Delete a scope by its primary key. |
| [`exists()`](#apiscoperepository-exists) | public | `bool` | Check whether a scope name already exists. |
| [`allNames()`](#apiscoperepository-allnames) | public | `string[]` | Return just the scope name strings, sorted alphabetically. |
| [`enrichRow()`](#apiscoperepository-enrichrow) | private | `array{id: int, name: string, description: string\|null, category: string, action: string}` | Derive category and action from a raw DB row and return the enriched shape. |

<details>
<summary><code>__construct()</code></summary>

#### `ApiScopeRepository __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L30)

```php
public function __construct(string $tblScopes)
```

| Param | Type | Description |
|---|---|---|
| `$tblScopes` | `string` | Scopes table name. Default: 'scopes'. |

</details>

<details>
<summary><code>all()</code></summary>

#### `ApiScopeRepository all()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L47)

```php
public function all(): array
```

Return all scope rows enriched with derived category and action fields. Each returned item has the shape: id          (int) name        (string)  full name, e.g. 'notes:read' description (string|null) category    (string)  part before first colon, e.g. 'notes' action      (string)  part after first colon, e.g. 'read'; equals name when no colon

**Returns:** `array<int, array{id: int, name: string, description: string\|null, category: string, action: string}>`

</details>

<details>
<summary><code>allGrouped()</code></summary>

#### `ApiScopeRepository allGrouped()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L66)

```php
public function allGrouped(): array
```

Return scopes grouped by category. Returns a dict keyed by category string, where each value is a list of scope rows (same shape as {@see all()}) that belong to that category.

**Returns:** `array<string, array<int, array{id: int, name: string, description: string\|null, category: string, action: string}>>`

</details>

<details>
<summary><code>create()</code></summary>

#### `ApiScopeRepository create()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L82)

```php
public function create(string $name, ?string $description): void
```

Insert a new scope. Silently ignores duplicates (INSERT IGNORE).

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | Full scope name, e.g. 'notes:read'. |
| `$description` | `string\|null` | Optional human-readable description. |

</details>

<details>
<summary><code>deleteById()</code></summary>

#### `ApiScopeRepository deleteById()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L93)

```php
public function deleteById(int $id): bool
```

Delete a scope by its primary key.

| Param | Type | Description |
|---|---|---|
| `$id` | `int` | Scope row ID. |

**Returns:** `bool` — True when a row was actually deleted.

</details>

<details>
<summary><code>exists()</code></summary>

#### `ApiScopeRepository exists()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L104)

```php
public function exists(string $name): bool
```

Check whether a scope name already exists.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | Full scope name to check. |

**Returns:** `bool`

</details>

<details>
<summary><code>allNames()</code></summary>

#### `ApiScopeRepository allNames()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L114)

```php
public function allNames(): array
```

Return just the scope name strings, sorted alphabetically.

**Returns:** `string[]`

</details>

<details>
<summary><code>enrichRow()</code></summary>

#### `ApiScopeRepository enrichRow()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/ApiScopeRepository.php#L126)

```php
private function enrichRow(array $row): array
```

Derive category and action from a raw DB row and return the enriched shape.

| Param | Type | Description |
|---|---|---|
| `$row` | `array<string, mixed>` | Raw PDO row with id, name, description. |

**Returns:** `array{id: int, name: string, description: string\|null, category: string, action: string}`

</details>

## `UserScopeRepository`

*final class* — `rafalmasiarek\DashboardKitApi\Admin\UserScopeRepository` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/UserScopeRepository.php#L18)

Manages the many-to-many assignment of API scopes to users. A user may only create tokens with scopes that have been explicitly assigned to them by an administrator. Admins bypass this restriction when creating tokens from the admin panel.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`scopeIdsForUser()`](#userscoperepository-scopeidsforuser) | public | `int[]` | Return all scope IDs assigned to a user. |
| [`scopesForUser()`](#userscoperepository-scopesforuser) | public | `array<int, array{id: int, name: string, description: string\|null, category: string, action: string}>` | Return all scope rows assigned to a user, enriched with name and description. |
| [`setForUser()`](#userscoperepository-setforuser) | public | `void` | Replace all scope assignments for a user with the given set of scope IDs. Deletes existing assignments and inserts the new ones in a single transaction. |
| [`userHasScopeByName()`](#userscoperepository-userhasscopebyname) | public | `bool` | Check whether a user has a specific scope assigned by name. |
| [`enrichRow()`](#userscoperepository-enrichrow) | private | `array{id: int, name: string, description: string\|null, category: string, action: string}` | Derive category and action from a raw DB row. |

<details>
<summary><code>scopeIdsForUser()</code></summary>

#### `UserScopeRepository scopeIdsForUser()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/UserScopeRepository.php#L26)

```php
public function scopeIdsForUser(string $userId): array
```

Return all scope IDs assigned to a user.

| Param | Type | Description |
|---|---|---|
| `$userId` | `string` | User UUID. |

**Returns:** `int[]` — List of scope IDs.

</details>

<details>
<summary><code>scopesForUser()</code></summary>

#### `UserScopeRepository scopesForUser()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/UserScopeRepository.php#L38)

```php
public function scopesForUser(string $userId): array
```

Return all scope rows assigned to a user, enriched with name and description.

| Param | Type | Description |
|---|---|---|
| `$userId` | `string` | User UUID. |

**Returns:** `array<int, array{id: int, name: string, description: string\|null, category: string, action: string}>`

</details>

<details>
<summary><code>setForUser()</code></summary>

#### `UserScopeRepository setForUser()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/UserScopeRepository.php#L60)

```php
public function setForUser(string $userId, array $scopeIds): void
```

Replace all scope assignments for a user with the given set of scope IDs. Deletes existing assignments and inserts the new ones in a single transaction.

| Param | Type | Description |
|---|---|---|
| `$userId` | `string` | User UUID. |
| `$scopeIds` | `int[]` | New set of scope IDs to assign. |

</details>

<details>
<summary><code>userHasScopeByName()</code></summary>

#### `UserScopeRepository userHasScopeByName()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/UserScopeRepository.php#L78)

```php
public function userHasScopeByName(string $userId, string $scopeName): bool
```

Check whether a user has a specific scope assigned by name.

| Param | Type | Description |
|---|---|---|
| `$userId` | `string` | User UUID. |
| `$scopeName` | `string` | Full scope name, e.g. 'notes:read'. |

**Returns:** `bool`

</details>

<details>
<summary><code>enrichRow()</code></summary>

#### `UserScopeRepository enrichRow()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Admin/UserScopeRepository.php#L93)

```php
private function enrichRow(array $row): array
```

Derive category and action from a raw DB row.

| Param | Type | Description |
|---|---|---|
| `$row` | `array<string, mixed>` | Raw PDO row with id, name, description. |

**Returns:** `array{id: int, name: string, description: string\|null, category: string, action: string}`

</details>

## `ApiAddon`

*final class* — `rafalmasiarek\DashboardKitApi\ApiAddon` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L42)

Wires the API token system into a Dashboard application.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`register()`](#apiaddon-register) | public static | `void` | Register the API addon. Initializes the schema, wires all DI bindings, discovers admin/user modules, and registers admin UI routes, user UI routes, and versioned API routes. |
| [`registerAdminRoutes()`](#apiaddon-registeradminroutes) | private static | `void` | Register admin UI routes for API token and scope management. All routes are protected by AuthMiddleware, CsrfMiddleware, and RoleMiddleware(['admin']). Routes are mounted directly on $app (not via module discovery) because Dashboard::registerRoutes() runs before ApiAddon::register(). |
| [`registerUserRoutes()`](#apiaddon-registeruserroutes) | private static | `void` | Register user-facing routes for "My API Tokens". All routes are protected by AuthMiddleware and CsrfMiddleware. Revoke verifies that the token belongs to the current user before deleting. |
| [`registerApiRoutes()`](#apiaddon-registerapiroutes) | private static | `void` | Discover modules with an 'api' key and mount versioned route groups. Route definition keys: scopes — required Bearer token scopes openapi — documentation metadata (summary, description, tags, deprecated) params — path/query param declarations; drives type casting and OpenAPI parameters body — JSON schema of the request body; drives OpenAPI requestBody responses — success schema and error codes; drives OpenAPI responses and runtime error map handler — fn(array $params, mixed $body, ContainerInterface $c): array |
| [`castParams()`](#apiaddon-castparams) | private static | `array<string, mixed>` | Cast path and query parameters to their declared types. Path params from Slim args are always included in the output; query params are included only when declared in $paramDefs. Declared params are cast to their 'type' ('integer', 'number', 'boolean', or 'string'). |
| [`buildApiResponse()`](#apiaddon-buildapiresponse) | private static | `ResponseInterface` | Build a PSR-7 response from a declarative handler result. Success keys: 'data' (payload), 'http' (status, default 200), 'message' (default from HTTP phrase). Error keys: 'error' (symbolic code), 'message' (override), 'field', 'detail'. Error codes are resolved against the route's responses declaration (entries with a 'code' key). For non-JSON responses, use the 'driver' key in the route definition instead. |
| [`applyHeaders()`](#apiaddon-applyheaders) | private static | `ResponseInterface` | Apply an array of header name => value pairs to a PSR-7 response. |
| [`buildErrorMap()`](#apiaddon-builderrormap) | private static | `array<string, array{http: int, msg: string}>` | Build a JsonHandler error map from a route's responses declaration. Each response entry with a 'code' key becomes an error map entry: 'CODE' => ['http' => <status>, 'msg' => <description>] |
| [`validateBody()`](#apiaddon-validatebody) | private static | `array{code: string, message?: string, field?: string, detail?: string}\|null` | Validate a parsed request body against a JSON Schema subset. Checks the root type and per-property types declared in 'properties'. Returns an error descriptor array on failure, null on success. |
| [`checkJsonType()`](#apiaddon-checkjsontype) | private static | `bool` | Check whether a PHP value matches a JSON Schema primitive type. Empty arrays are accepted for both 'array' and 'object' because PHP cannot distinguish {} from [] after json_decode with assoc=true. |
| [`registerOpenApiRoute()`](#apiaddon-registeropenapiroute) | private static | `void` | Mount the GET endpoint that serves the OpenAPI specification as JSON. Visibility determines which middleware guards the endpoint: 'public' — no authentication required 'user' — requires a valid session (AuthMiddleware) 'admin' — requires admin role (AuthMiddleware + RoleMiddleware) |
| [`normalizeTokensForView()`](#apiaddon-normalizetokensforview) | private static | `array<int, array{token: string, email: string, subject: string\|null, scopes: string[], exp: int\|null, exp_h: string}>` | Transform a raw token map from DbTokenRepository::all() / allByUser() into a flat list suitable for Twig templates. Input shape: array<token_string, ['sub' => email, 'subject' => ?string, 'exp' => ?int, 'scopes' => string[], ...]> Output shape: array<int, ['token' => string, 'email' => string, 'subject' => ?string, 'scopes' => string[], 'exp' => ?int, 'exp_h' => string]> |
| [`minimizeScopes()`](#apiaddon-minimizescopes) | private static | `string[]` | Remove redundant scopes when a category wildcard is already present. If the input contains 'notes:*', then 'notes:read' and 'notes:write' are redundant and should be stripped. Wildcards of the form 'category:*' cover all actions in that category. |
| [`generateToken()`](#apiaddon-generatetoken) | private static | `string` | Generate a cryptographically secure URL-safe base64 token. |

<details>
<summary><code>register()</code></summary>

#### `ApiAddon register()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L67)

```php
public static function register(App $app, ContainerInterface $container, array $config): void
```

Register the API addon. Initializes the schema, wires all DI bindings, discovers admin/user modules, and registers admin UI routes, user UI routes, and versioned API routes.

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | — |
| `$config` | `array<string, mixed>` | Optional configuration overrides. |

</details>

<details>
<summary><code>registerAdminRoutes()</code></summary>

#### `ApiAddon registerAdminRoutes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L251)

```php
private static function registerAdminRoutes(App $app, ContainerInterface $container, string $urlBasePath, string $adminPanelUrlPrefix): void
```

Register admin UI routes for API token and scope management. All routes are protected by AuthMiddleware, CsrfMiddleware, and RoleMiddleware(['admin']). Routes are mounted directly on $app (not via module discovery) because Dashboard::registerRoutes() runs before ApiAddon::register().

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | — |
| `$urlBasePath` | `string` | Full URL base path (e.g. '/api'). Prepended to self-referential redirects. |
| `$adminPanelUrlPrefix` | `string` | Full URL prefix for the admin panel (e.g. '/api/admin/panel'). Used for cross-section redirects. |

</details>

<details>
<summary><code>registerUserRoutes()</code></summary>

#### `ApiAddon registerUserRoutes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L428)

```php
private static function registerUserRoutes(App $app, ContainerInterface $container, string $urlBasePath): void
```

Register user-facing routes for "My API Tokens". All routes are protected by AuthMiddleware and CsrfMiddleware. Revoke verifies that the token belongs to the current user before deleting.

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | — |
| `$urlBasePath` | `string` | Full URL base path (e.g. '/api'). Prepended to self-referential redirects. |

</details>

<details>
<summary><code>registerApiRoutes()</code></summary>

#### `ApiAddon registerApiRoutes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L541)

```php
private static function registerApiRoutes(App $app, ContainerInterface $container, string $apiPrefix, OpenApiRegistry $openApiReg): void
```

Discover modules with an 'api' key and mount versioned route groups. Route definition keys: scopes    — required Bearer token scopes openapi   — documentation metadata (summary, description, tags, deprecated) params    — path/query param declarations; drives type casting and OpenAPI parameters body      — JSON schema of the request body; drives OpenAPI requestBody responses — success schema and error codes; drives OpenAPI responses and runtime error map handler   — fn(array $params, mixed $body, ContainerInterface $c): array

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | — |
| `$apiPrefix` | `string` | Optional route prefix without slashes (e.g. 'api'). |
| `$openApiReg` | [`OpenApiRegistry`](#openapiregistry) | Registry to receive route metadata for OpenAPI spec. |

</details>

<details>
<summary><code>castParams()</code></summary>

#### `ApiAddon castParams()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L814)

```php
private static function castParams(array $args, array $query, array $paramDefs): array
```

Cast path and query parameters to their declared types. Path params from Slim args are always included in the output; query params are included only when declared in $paramDefs. Declared params are cast to their 'type' ('integer', 'number', 'boolean', or 'string').

| Param | Type | Description |
|---|---|---|
| `$args` | `array<string, string>` | Slim route arguments (path params). |
| `$query` | `array<string, mixed>` | Parsed query string parameters. |
| `$paramDefs` | `array<string, array<string, mixed>>` | Param declarations from route def. |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>buildApiResponse()</code></summary>

#### `ApiAddon buildApiResponse()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L854)

```php
private static function buildApiResponse(ResponseInterface $res, array $result, array $responses, ?array $pagination): ResponseInterface
```

Build a PSR-7 response from a declarative handler result. Success keys: 'data' (payload), 'http' (status, default 200), 'message' (default from HTTP phrase). Error keys:   'error' (symbolic code), 'message' (override), 'field', 'detail'. Error codes are resolved against the route's responses declaration (entries with a 'code' key). For non-JSON responses, use the 'driver' key in the route definition instead.

| Param | Type | Description |
|---|---|---|
| `$res` | `ResponseInterface` | — |
| `$result` | `array<string, mixed>` | Handler return value. |
| `$responses` | `array<int, array<string, mixed>>` | Route responses declaration. |
| `$pagination` | `array<string, mixed>\|null` | Computed pagination object, or null. |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>applyHeaders()</code></summary>

#### `ApiAddon applyHeaders()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L906)

```php
private static function applyHeaders(ResponseInterface $response, array $headers): ResponseInterface
```

Apply an array of header name => value pairs to a PSR-7 response.

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$headers` | `array<string, string>` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>buildErrorMap()</code></summary>

#### `ApiAddon buildErrorMap()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L923)

```php
private static function buildErrorMap(array $responses): array
```

Build a JsonHandler error map from a route's responses declaration. Each response entry with a 'code' key becomes an error map entry: 'CODE' => ['http' => <status>, 'msg' => <description>]

| Param | Type | Description |
|---|---|---|
| `$responses` | `array<int, array<string, mixed>>` | — |

**Returns:** `array<string, array{http: int, msg: string}>`

</details>

<details>
<summary><code>validateBody()</code></summary>

#### `ApiAddon validateBody()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L947)

```php
private static function validateBody(array $schema, mixed $parsed): ?array
```

Validate a parsed request body against a JSON Schema subset. Checks the root type and per-property types declared in 'properties'. Returns an error descriptor array on failure, null on success.

| Param | Type | Description |
|---|---|---|
| `$schema` | `array<string, mixed>` | JSON Schema declaration from the route 'body' key. |
| `$parsed` | `mixed` | Parsed request body from getParsedBody(). |

**Returns:** `array{code: string, message?: string, field?: string, detail?: string}\|null`

</details>

<details>
<summary><code>checkJsonType()</code></summary>

#### `ApiAddon checkJsonType()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L984)

```php
private static function checkJsonType(mixed $value, string $type): bool
```

Check whether a PHP value matches a JSON Schema primitive type. Empty arrays are accepted for both 'array' and 'object' because PHP cannot distinguish {} from [] after json_decode with assoc=true.

| Param | Type | Description |
|---|---|---|
| `$value` | `mixed` | — |
| `$type` | `string` | JSON Schema type: string, integer, number, boolean, array, object. |

**Returns:** `bool`

</details>

<details>
<summary><code>registerOpenApiRoute()</code></summary>

#### `ApiAddon registerOpenApiRoute()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L1011)

```php
private static function registerOpenApiRoute(App $app, ContainerInterface $container, string $path, string $visibility): void
```

Mount the GET endpoint that serves the OpenAPI specification as JSON. Visibility determines which middleware guards the endpoint: 'public'  — no authentication required 'user'    — requires a valid session (AuthMiddleware) 'admin'   — requires admin role (AuthMiddleware + RoleMiddleware)

| Param | Type | Description |
|---|---|---|
| `$app` | `App` | — |
| `$container` | `ContainerInterface` | — |
| `$path` | `string` | Slim route path, e.g. '/api/openapi.json'. |
| `$visibility` | `string` | 'admin' \| 'user' \| 'public'. |

</details>

<details>
<summary><code>normalizeTokensForView()</code></summary>

#### `ApiAddon normalizeTokensForView()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L1042)

```php
private static function normalizeTokensForView(array $raw): array
```

Transform a raw token map from DbTokenRepository::all() / allByUser() into a flat list suitable for Twig templates. Input shape: array<token_string, ['sub' => email, 'subject' => ?string, 'exp' => ?int, 'scopes' => string[], ...]> Output shape: array<int, ['token' => string, 'email' => string, 'subject' => ?string, 'scopes' => string[], 'exp' => ?int, 'exp_h' => string]>

| Param | Type | Description |
|---|---|---|
| `$raw` | `array<string, array<string, mixed>>` | Raw map from DbTokenRepository. |

**Returns:** `array<int, array{token: string, email: string, subject: string\|null, scopes: string[], exp: int\|null, exp_h: string}>`

</details>

<details>
<summary><code>minimizeScopes()</code></summary>

#### `ApiAddon minimizeScopes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L1075)

```php
private static function minimizeScopes(array $names): array
```

Remove redundant scopes when a category wildcard is already present. If the input contains 'notes:*', then 'notes:read' and 'notes:write' are redundant and should be stripped. Wildcards of the form 'category:*' cover all actions in that category.

| Param | Type | Description |
|---|---|---|
| `$names` | `string[]` | Raw scope names as submitted. |

**Returns:** `string[]` — Minimized list with redundant entries removed.

</details>

<details>
<summary><code>generateToken()</code></summary>

#### `ApiAddon generateToken()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ApiAddon.php#L1113)

```php
private static function generateToken(int $bytes): string
```

Generate a cryptographically secure URL-safe base64 token.

| Param | Type | Description |
|---|---|---|
| `$bytes` | `int` | Number of random bytes before encoding. Default: 32. |

**Returns:** `string` — URL-safe base64 string without padding.

| Throws | When |
|---|---|
| `\Random\RandomException` | When the system PRNG fails (PHP 8.2+). |

</details>

## `ApiTokenAuditLog`

*final class* — `rafalmasiarek\DashboardKitApi\Audit\ApiTokenAuditLog` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditLog.php#L26)

Writes structured audit entries for API token requests. Each method logs a single event with a consistent context shape. Mirrors the pattern of DashboardKit's core AuditLog — one method per event, PSR-3 logger injected via constructor, named events in dot notation. Event names: api.token.allowed — token valid and scopes satisfied (INFO) api.token.denied  — 401 (missing/invalid token) or 403 (insufficient scope) (WARNING) Token values are never logged in full. Only the first 8 characters are stored as a correlation prefix so individual requests can be traced without exposing the credential.

**Depends on:** `LoggerInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$logger` | `LoggerInterface` | private | PSR-3 logger receiving audit entries. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#apitokenauditlog-__construct) | public | — | — |
| [`allowed()`](#apitokenauditlog-allowed) | public | `void` | Log a successful API request — token valid, scopes satisfied. |
| [`denied()`](#apitokenauditlog-denied) | public | `void` | Log a denied API request — missing/invalid token or insufficient scope. |

<details>
<summary><code>__construct()</code></summary>

#### `ApiTokenAuditLog __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditLog.php#L38)

```php
public function __construct(LoggerInterface $logger)
```

| Param | Type | Description |
|---|---|---|
| `$logger` | `LoggerInterface` | PSR-3 logger (typically the 'api' or 'audit' channel). |

</details>

<details>
<summary><code>allowed()</code></summary>

#### `ApiTokenAuditLog allowed()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditLog.php#L56)

```php
public function allowed(string $tokenPrefix, string $userId, string $email, string $ip, string $method, string $path, array $scopesRequired, array $scopesGranted): void
```

Log a successful API request — token valid, scopes satisfied.

| Param | Type | Description |
|---|---|---|
| `$tokenPrefix` | `string` | First 8 characters of the bearer token. |
| `$userId` | `string` | User ID from token claims. |
| `$email` | `string` | User email from token claims (sub). |
| `$ip` | `string` | Client IP address. |
| `$method` | `string` | HTTP method (GET, POST, …). |
| `$path` | `string` | Request path (e.g. /v1/notes). |
| `$scopesRequired` | `string[]` | Scopes required by the route. |
| `$scopesGranted` | `string[]` | Scopes present in the token. |

</details>

<details>
<summary><code>denied()</code></summary>

#### `ApiTokenAuditLog denied()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditLog.php#L91)

```php
public function denied(string $reason, string $ip, string $method, string $path, array $scopesRequired, ?string $tokenPrefix, ?string $userId, ?string $email): void
```

Log a denied API request — missing/invalid token or insufficient scope.

| Param | Type | Description |
|---|---|---|
| `$reason` | `string` | Deny reason: 'missing_token' \| 'invalid_token' \| 'insufficient_scope'. |
| `$ip` | `string` | Client IP address. |
| `$method` | `string` | HTTP method. |
| `$path` | `string` | Request path. |
| `$scopesRequired` | `string[]` | Scopes required by the route. |
| `$tokenPrefix` | `string\|null` | First 8 chars of the token, null when no token was present. |
| `$userId` | `string\|null` | User ID from claims, null on auth failure. |
| `$email` | `string\|null` | User email from claims, null on auth failure. |

</details>

## `ApiTokenAuditMiddleware`

*final class* — `rafalmasiarek\DashboardKitApi\Audit\ApiTokenAuditMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditMiddleware.php#L36)

PSR-15 middleware that writes an audit entry for every versioned API request. Must be added OUTSIDE TokenAuthMiddleware and TokenScopeMiddleware so that it wraps the entire auth+scope chain and can inspect the final response: ->add(TokenScopeMiddleware)   // innermost — per-route ->add(TokenAuthMiddleware) ->add(ApiTokenAuditMiddleware) // outermost — sees the final response Outcome detection (post-response): X-Auth-Error: missing_token       → denied, reason 'missing_token' X-Auth-Error: invalid_or_expired  → denied, reason 'invalid_token' HTTP 403                          → denied, reason 'insufficient_scope' HTTP 2xx/3xx/4xx (no auth error)  → allowed Token logging: Only the first 8 characters of the Bearer token are recorded (e.g. "a1b2c3d4…") to allow per-request correlation without storing the full credential.

implements `MiddlewareInterface`

**Depends on:** [`ApiTokenAuditLog`](#apitokenauditlog), `RealIpResolver`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$auditLog` | [`ApiTokenAuditLog`](#apitokenauditlog) | private | — |
| `$resolver` | `RealIpResolver\|null` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#apitokenauditmiddleware-__construct) | public | — | — |
| [`process()`](#apitokenauditmiddleware-process) | public | `ResponseInterface` | {@inheritDoc} |
| [`extractTokenPrefix()`](#apitokenauditmiddleware-extracttokenprefix) | private | `string\|null` | Extract the first 8 characters of the Bearer token as a correlation prefix. |
| [`requiredScopesFromRoute()`](#apitokenauditmiddleware-requiredscopesfromroute) | private | `string[]` | Read required scopes directly from the Slim route argument. Reading from the route argument (set at registration time) rather than from a request attribute ensures the value is available even when TokenScopeMiddleware returns early with 403 before it can set the request attribute. |

<details>
<summary><code>__construct()</code></summary>

#### `ApiTokenAuditMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditMiddleware.php#L50)

```php
public function __construct(ApiTokenAuditLog $auditLog, ?RealIpResolver $resolver)
```

| Param | Type | Description |
|---|---|---|
| `$auditLog` | [`ApiTokenAuditLog`](#apitokenauditlog) | Audit logger for token events. |
| `$resolver` | `RealIpResolver\|null` | Real IP resolver; falls back to REMOTE_ADDR when null. |

</details>

<details>
<summary><code>process()</code></summary>

#### `ApiTokenAuditMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditMiddleware.php#L59)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

{@inheritDoc}

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$handler` | `RequestHandlerInterface` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>extractTokenPrefix()</code></summary>

#### `ApiTokenAuditMiddleware extractTokenPrefix()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditMiddleware.php#L117)

```php
private function extractTokenPrefix(ServerRequestInterface $request): ?string
```

Extract the first 8 characters of the Bearer token as a correlation prefix.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |

**Returns:** `string\|null` — Null when no Authorization header is present.

</details>

<details>
<summary><code>requiredScopesFromRoute()</code></summary>

#### `ApiTokenAuditMiddleware requiredScopesFromRoute()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Audit/ApiTokenAuditMiddleware.php#L136)

```php
private function requiredScopesFromRoute(ServerRequestInterface $request): array
```

Read required scopes directly from the Slim route argument. Reading from the route argument (set at registration time) rather than from a request attribute ensures the value is available even when TokenScopeMiddleware returns early with 403 before it can set the request attribute.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |

**Returns:** `string[]`

</details>

## `DbTokenRepository`

*final class* — `rafalmasiarek\DashboardKitApi\Auth\DbTokenRepository` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L19)

Persists API tokens in the database. Tables (see ApiSchemaProvider): user_tokens (token PK, user_id FK, subject NULL, expires_at NULL) scopes      (id PK AUTO_INCREMENT, name UNIQUE) token_scopes (token, scope_id) PK(token, scope_id)

implements [`TokenRepositoryInterface`](#tokenrepositoryinterface)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$tblUsers` | `string` | private | — |
| `$tblTokens` | `string` | private | — |
| `$tblScopes` | `string` | private | — |
| `$tblTokenScopes` | `string` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#dbtokenrepository-__construct) | public | — | — |
| [`all()`](#dbtokenrepository-all) | public | `array<string, array<string, mixed>>` | Return all tokens as token => claims map. |
| [`allByUser()`](#dbtokenrepository-allbyuser) | public | `array<string, array<string, mixed>>` | Return all tokens for a specific user. |
| [`get()`](#dbtokenrepository-get) | public | `array<string, mixed>\|null` | Return claims for a token, or null if not found. |
| [`put()`](#dbtokenrepository-put) | public | `void` | Insert or update token claims. Claims must include 'user_id' (int). Optional claims: 'exp' (int unix timestamp), 'subject' (string label). |
| [`delete()`](#dbtokenrepository-delete) | public | `bool` | Delete a token. |
| [`setTokenScopesByNames()`](#dbtokenrepository-settokenscopesbynames) | public | `void` | Replace scope assignments for a token with an exact set of scope names. |
| [`getTokenScopesByNames()`](#dbtokenrepository-gettokenscopesbynames) | public | `string[]` | Return scope names assigned to a token. |
| [`mapByToken()`](#dbtokenrepository-mapbytoken) | private | `array<string, array<string, mixed>>` | Converts a list of joined token+email rows into a token => claims map. |
| [`toClaims()`](#dbtokenrepository-toclaims) | private | `array<string, mixed>` | Converts one joined token+email row into the claims shape. |
| [`toUnix()`](#dbtokenrepository-tounix) | private | `int\|null` | Convert a DATETIME string to unix timestamp or null. |

<details>
<summary><code>__construct()</code></summary>

#### `DbTokenRepository __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L47)

```php
public function __construct(string $tblUsers, string $tblTokens, string $tblScopes, string $tblTokenScopes)
```

| Param | Type | Description |
|---|---|---|
| `$tblUsers` | `string` | Users table (default: 'users'). |
| `$tblTokens` | `string` | API tokens table (default: 'user_tokens'). |
| `$tblScopes` | `string` | Scopes dictionary (default: 'scopes'). |
| `$tblTokenScopes` | `string` | Token-scopes junction (default: 'token_scopes'). |

</details>

<details>
<summary><code>all()</code></summary>

#### `DbTokenRepository all()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L64)

```php
public function all(): array
```

Return all tokens as token => claims map.

**Returns:** `array<string, array<string, mixed>>`

</details>

<details>
<summary><code>allByUser()</code></summary>

#### `DbTokenRepository allByUser()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L88)

```php
public function allByUser(string $userId): array
```

Return all tokens for a specific user.

| Param | Type | Description |
|---|---|---|
| `$userId` | `string` | UUID string, as stored by AuthKit PdoUserStorage with UuidUserIdPolicy. |

**Returns:** `array<string, array<string, mixed>>`

</details>

<details>
<summary><code>get()</code></summary>

#### `DbTokenRepository get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L110)

```php
public function get(string $token): ?array
```

Return claims for a token, or null if not found.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `array<string, mixed>\|null`

</details>

<details>
<summary><code>put()</code></summary>

#### `DbTokenRepository put()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L132)

```php
public function put(string $token, array $claims): void
```

Insert or update token claims. Claims must include 'user_id' (int). Optional claims: 'exp' (int unix timestamp), 'subject' (string label).

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |
| `$claims` | `array<string, mixed>` | — |

| Throws | When |
|---|---|
| `\InvalidArgumentException` | When claims['user_id'] is missing or invalid. |

</details>

<details>
<summary><code>delete()</code></summary>

#### `DbTokenRepository delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L158)

```php
public function delete(string $token): bool
```

Delete a token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `bool` — True when a row was actually removed.

</details>

<details>
<summary><code>setTokenScopesByNames()</code></summary>

#### `DbTokenRepository setTokenScopesByNames()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L170)

```php
public function setTokenScopesByNames(string $token, array $names): void
```

Replace scope assignments for a token with an exact set of scope names.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |
| `$names` | `string[]` | Scope names (e.g. ['cron:run', 'cron:status']). |

</details>

<details>
<summary><code>getTokenScopesByNames()</code></summary>

#### `DbTokenRepository getTokenScopesByNames()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L193)

```php
public function getTokenScopesByNames(string $token): array
```

Return scope names assigned to a token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `string[]`

</details>

<details>
<summary><code>mapByToken()</code></summary>

#### `DbTokenRepository mapByToken()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L212)

```php
private function mapByToken(array $rows): array
```

Converts a list of joined token+email rows into a token => claims map.

| Param | Type | Description |
|---|---|---|
| `$rows` | `list<array<string, mixed>>` | — |

**Returns:** `array<string, array<string, mixed>>`

</details>

<details>
<summary><code>toClaims()</code></summary>

#### `DbTokenRepository toClaims()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L227)

```php
private function toClaims(array $row): array
```

Converts one joined token+email row into the claims shape.

| Param | Type | Description |
|---|---|---|
| `$row` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>toUnix()</code></summary>

#### `DbTokenRepository toUnix()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenRepository.php#L244)

```php
private function toUnix(string $datetime): ?int
```

Convert a DATETIME string to unix timestamp or null.

| Param | Type | Description |
|---|---|---|
| `$datetime` | `string` | — |

**Returns:** `int\|null`

</details>

## `DbTokenValidator`

*final class* — `rafalmasiarek\DashboardKitApi\Auth\DbTokenValidator` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenValidator.php#L28)

Validates bearer tokens against the database. Expected schema (see ApiSchemaProvider): user_tokens (token PK, user_id, subject NULL, expires_at NULL) token_scopes (token, scope_id) PK(token, scope_id) scopes (id PK, name UNIQUE) Returned claims shape: sub    (int)      User ID from user_tokens.user_id. email  (string)   User email from users.email. exp    (int|null) Expiry unix timestamp, null = never. scopes (string[]) Scope names assigned to the token. Note: AuthKit's users table does not carry is_active/is_suspend/is_admin. Those checks are the application's responsibility, not the token validator's.

implements [`TokenValidatorInterface`](#tokenvalidatorinterface)

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$tblUsers` | `string` | private | — |
| `$tblTokens` | `string` | private | — |
| `$tblTokenScopes` | `string` | private | — |
| `$tblScopes` | `string` | private | — |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#dbtokenvalidator-__construct) | public | — | — |
| [`validate()`](#dbtokenvalidator-validate) | public | `array<string, mixed>\|null` | Validate a raw bearer token. |
| [`fetchClaims()`](#dbtokenvalidator-fetchclaims) | private | `array<string, mixed>\|null` | Fetch base claims from user_tokens JOIN users. |
| [`fetchScopes()`](#dbtokenvalidator-fetchscopes) | private | `string[]` | Fetch scope names assigned to a token. |

<details>
<summary><code>__construct()</code></summary>

#### `DbTokenValidator __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenValidator.php#L56)

```php
public function __construct(string $tblUsers, string $tblTokens, string $tblTokenScopes, string $tblScopes)
```

| Param | Type | Description |
|---|---|---|
| `$tblUsers` | `string` | Users table (default: 'users'). |
| `$tblTokens` | `string` | API tokens table (default: 'user_tokens'). |
| `$tblTokenScopes` | `string` | Token-scopes junction table (default: 'token_scopes'). |
| `$tblScopes` | `string` | Scopes dictionary table (default: 'scopes'). |

</details>

<details>
<summary><code>validate()</code></summary>

#### `DbTokenValidator validate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenValidator.php#L71)

```php
public function validate(string $token): ?array
```

Validate a raw bearer token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | Raw token value from Authorization: Bearer header. |

**Returns:** `array<string, mixed>\|null` — Claims on success, null when invalid or expired.

</details>

<details>
<summary><code>fetchClaims()</code></summary>

#### `DbTokenValidator fetchClaims()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenValidator.php#L93)

```php
private function fetchClaims(string $token): ?array
```

Fetch base claims from user_tokens JOIN users.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `array<string, mixed>\|null` — Null when not found or expired.

</details>

<details>
<summary><code>fetchScopes()</code></summary>

#### `DbTokenValidator fetchScopes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/DbTokenValidator.php#L129)

```php
private function fetchScopes(string $token): array
```

Fetch scope names assigned to a token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `string[]`

</details>

## `TokenAuthMiddleware`

*final class* — `rafalmasiarek\DashboardKitApi\Auth\TokenAuthMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenAuthMiddleware.php#L25)

PSR-15 middleware that extracts and validates a Bearer token. On success, attaches token claims to the request attribute (default: 'auth'). On failure, returns 401 JSON with WWW-Authenticate header. Must run before TokenScopeMiddleware (add in reverse order in Slim): $route->add(new TokenScopeMiddleware(...))->add(new TokenAuthMiddleware(...));

implements `MiddlewareInterface`

**Depends on:** [`TokenValidatorInterface`](#tokenvalidatorinterface), `ResponseFactoryInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$validator` | [`TokenValidatorInterface`](#tokenvalidatorinterface) | private | — |
| `$responses` | `ResponseFactoryInterface` | private | — |
| `$attribute` | `string` | private | Request attribute name for attaching claims. |
| `$allowQueryParam` | `bool` | private | Whether to also accept ?access_token= query param. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#tokenauthmiddleware-__construct) | public | — | — |
| [`process()`](#tokenauthmiddleware-process) | public | `ResponseInterface` | {@inheritDoc} |
| [`extractToken()`](#tokenauthmiddleware-extracttoken) | private | `string\|null` | Extract bearer token from Authorization header or optional query param. |

<details>
<summary><code>__construct()</code></summary>

#### `TokenAuthMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenAuthMiddleware.php#L53)

```php
public function __construct(TokenValidatorInterface $validator, ResponseFactoryInterface $responses, string $attribute, bool $allowQueryParam)
```

| Param | Type | Description |
|---|---|---|
| `$validator` | [`TokenValidatorInterface`](#tokenvalidatorinterface) | — |
| `$responses` | `ResponseFactoryInterface` | — |
| `$attribute` | `string` | Request attribute for claims (default: 'auth'). |
| `$allowQueryParam` | `bool` | Accept ?access_token= fallback (default: false). |

</details>

<details>
<summary><code>process()</code></summary>

#### `TokenAuthMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenAuthMiddleware.php#L68)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

{@inheritDoc}

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$handler` | `RequestHandlerInterface` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>extractToken()</code></summary>

#### `TokenAuthMiddleware extractToken()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenAuthMiddleware.php#L97)

```php
private function extractToken(ServerRequestInterface $request): ?string
```

Extract bearer token from Authorization header or optional query param.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |

**Returns:** `string\|null`

</details>

## `TokenRepositoryInterface`

*interface* — `rafalmasiarek\DashboardKitApi\Auth\TokenRepositoryInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenRepositoryInterface.php#L12)

Persistence abstraction for API access tokens.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`all()`](#tokenrepositoryinterface-all) | public | `array<string, array<string, mixed>>` | Return all tokens as token => claims map. |
| [`get()`](#tokenrepositoryinterface-get) | public | `array<string, mixed>\|null` | Return claims for a token, or null if not found. |
| [`put()`](#tokenrepositoryinterface-put) | public | `void` | Insert or update token claims. Claims must include 'user_id' (int). Optional claims: 'exp' (int unix timestamp), 'subject' (string label). |
| [`delete()`](#tokenrepositoryinterface-delete) | public | `bool` | Delete a token. |

<details>
<summary><code>all()</code></summary>

#### `TokenRepositoryInterface all()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenRepositoryInterface.php#L19)

```php
public function all(): array
```

Return all tokens as token => claims map.

**Returns:** `array<string, array<string, mixed>>`

</details>

<details>
<summary><code>get()</code></summary>

#### `TokenRepositoryInterface get()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenRepositoryInterface.php#L27)

```php
public function get(string $token): ?array
```

Return claims for a token, or null if not found.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `array<string, mixed>\|null`

</details>

<details>
<summary><code>put()</code></summary>

#### `TokenRepositoryInterface put()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenRepositoryInterface.php#L38)

```php
public function put(string $token, array $claims): void
```

Insert or update token claims. Claims must include 'user_id' (int). Optional claims: 'exp' (int unix timestamp), 'subject' (string label).

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |
| `$claims` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>delete()</code></summary>

#### `TokenRepositoryInterface delete()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenRepositoryInterface.php#L46)

```php
public function delete(string $token): bool
```

Delete a token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | — |

**Returns:** `bool` — True when a row was actually removed.

</details>

## `TokenScopeMiddleware`

*final class* — `rafalmasiarek\DashboardKitApi\Auth\TokenScopeMiddleware` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L30)

PSR-15 middleware that enforces per-route scope requirements. Reads required scopes from the Slim route argument 'required_scopes' (JSON-encoded string array). Reads granted scopes from the request attribute set by TokenAuthMiddleware (default: 'auth' → claims['scopes']). Super scopes '*' and 'admin:*' bypass all checks. A 'namespace:*' scope satisfies any 'namespace:action' requirement. Add in reverse order in Slim (last added runs first): $route->add(new TokenScopeMiddleware(...))->add(new TokenAuthMiddleware(...));

implements `MiddlewareInterface`

**Depends on:** `ResponseFactoryInterface`

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$responses` | `ResponseFactoryInterface` | private | — |
| `$claimsAttribute` | `string` | private | Request attribute carrying auth claims. |
| `$routeArg` | `string` | private | Slim route argument carrying JSON-encoded required scopes. |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`__construct()`](#tokenscopemiddleware-__construct) | public | — | — |
| [`process()`](#tokenscopemiddleware-process) | public | `ResponseInterface` | {@inheritDoc} |
| [`requiredScopesFromRoute()`](#tokenscopemiddleware-requiredscopesfromroute) | private | `string[]` | Extract required scopes from the current Slim route argument. |
| [`normalizeGrantedScopes()`](#tokenscopemiddleware-normalizegrantedscopes) | private | `string[]` | Normalize granted scopes from claims array. Accepts claims['scopes'] as string[] or space/comma-delimited string. |
| [`hasSuperScope()`](#tokenscopemiddleware-hassuperscope) | private | `bool` | True when token has a super scope that bypasses all checks. |
| [`scopesSatisfy()`](#tokenscopemiddleware-scopessatisfy) | private | `bool` | True when all required scopes are satisfied by granted scopes. |
| [`isScopeSatisfied()`](#tokenscopemiddleware-isscopesatisfied) | private | `bool` | True when a single required scope is satisfied by any granted scope. |
| [`scopeMatches()`](#tokenscopemiddleware-scopematches) | private | `bool` | Match a granted scope against a required scope. Rules: exact match, '*', 'namespace:*' matches 'namespace:action'. |

<details>
<summary><code>__construct()</code></summary>

#### `TokenScopeMiddleware __construct()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L52)

```php
public function __construct(ResponseFactoryInterface $responses, string $claimsAttribute, string $routeArg)
```

| Param | Type | Description |
|---|---|---|
| `$responses` | `ResponseFactoryInterface` | — |
| `$claimsAttribute` | `string` | Request attribute for claims (default: 'auth'). |
| `$routeArg` | `string` | Route argument name (default: 'required_scopes'). |

</details>

<details>
<summary><code>process()</code></summary>

#### `TokenScopeMiddleware process()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L65)

```php
public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
```

{@inheritDoc}

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |
| `$handler` | `RequestHandlerInterface` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>requiredScopesFromRoute()</code></summary>

#### `TokenScopeMiddleware requiredScopesFromRoute()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L112)

```php
private function requiredScopesFromRoute(ServerRequestInterface $request): array
```

Extract required scopes from the current Slim route argument.

| Param | Type | Description |
|---|---|---|
| `$request` | `ServerRequestInterface` | — |

**Returns:** `string[]`

</details>

<details>
<summary><code>normalizeGrantedScopes()</code></summary>

#### `TokenScopeMiddleware normalizeGrantedScopes()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L159)

```php
private function normalizeGrantedScopes(array $claims): array
```

Normalize granted scopes from claims array. Accepts claims['scopes'] as string[] or space/comma-delimited string.

| Param | Type | Description |
|---|---|---|
| `$claims` | `array<string, mixed>` | — |

**Returns:** `string[]`

</details>

<details>
<summary><code>hasSuperScope()</code></summary>

#### `TokenScopeMiddleware hasSuperScope()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L183)

```php
private function hasSuperScope(array $granted): bool
```

True when token has a super scope that bypasses all checks.

| Param | Type | Description |
|---|---|---|
| `$granted` | `string[]` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>scopesSatisfy()</code></summary>

#### `TokenScopeMiddleware scopesSatisfy()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L195)

```php
private function scopesSatisfy(array $granted, array $required): bool
```

True when all required scopes are satisfied by granted scopes.

| Param | Type | Description |
|---|---|---|
| `$granted` | `string[]` | — |
| `$required` | `string[]` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>isScopeSatisfied()</code></summary>

#### `TokenScopeMiddleware isScopeSatisfied()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L212)

```php
private function isScopeSatisfied(array $granted, string $required): bool
```

True when a single required scope is satisfied by any granted scope.

| Param | Type | Description |
|---|---|---|
| `$granted` | `string[]` | — |
| `$required` | `string` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>scopeMatches()</code></summary>

#### `TokenScopeMiddleware scopeMatches()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenScopeMiddleware.php#L231)

```php
private function scopeMatches(string $own, string $required): bool
```

Match a granted scope against a required scope. Rules: exact match, '*', 'namespace:*' matches 'namespace:action'.

| Param | Type | Description |
|---|---|---|
| `$own` | `string` | Granted scope. |
| `$required` | `string` | Required scope. |

**Returns:** `bool`

</details>

## `TokenValidatorInterface`

*interface* — `rafalmasiarek\DashboardKitApi\Auth\TokenValidatorInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenValidatorInterface.php#L18)

Validates an opaque bearer token and returns its claims on success. Returned claims shape: sub    (int)      User ID. email  (string)   User email. exp    (int|null) Expiry unix timestamp, null = never. scopes (string[]) Granted scope names, e.g. ['cron:run', 'cron:status'].

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`validate()`](#tokenvalidatorinterface-validate) | public | `array<string, mixed>\|null` | Validate a raw bearer token. |

<details>
<summary><code>validate()</code></summary>

#### `TokenValidatorInterface validate()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Auth/TokenValidatorInterface.php#L26)

```php
public function validate(string $token): ?array
```

Validate a raw bearer token.

| Param | Type | Description |
|---|---|---|
| `$token` | `string` | Raw token value from Authorization: Bearer header. |

**Returns:** `array<string, mixed>\|null` — Claims on success, null when invalid or expired.

</details>

## `JsonHandler`

*final class* — `rafalmasiarek\DashboardKitApi\Http\JsonHandler` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L27)

Single source of truth for all JSON responses in the API plugin. Core envelope (always present): { "status": "success"|"error", "code": <int>, "message": <string>, "data": <object> } Additional top-level keys (e.g. "errors", "meta") are registered once at bootstrap via registerExtraFields() and are then included automatically in every response. Typical bootstrap (in ApiAddon::register()): JsonHandler::registerExtraFields([ 'errors' => ['schema' => [...], 'validator' => 'errors', 'default' => []], 'meta'   => ['schema' => [...], 'validator' => 'data',   'default' => new \stdClass()], ]);

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$HTTP_PHRASES` | `array<int, string>` | private static | Default HTTP phrases used for OpenAPI descriptions and examples. |
| `$CORE_FIELD_DEFS` | `array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>` | private static | Core field definitions. Fixed — extra fields are registered via registerExtraFields(). |
| `$EXTRA_FIELD_DEFS` | `array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>` | private static | Extra field definitions registered at runtime. |
| `$EXTRA_FIELD_STACK` | `array<int, array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>>` | private static | Stack for scoped extra-field registration. |
| `$EXTRA_COMPONENTS` | `array<string, mixed>` | private static | Extra OpenAPI components merged into openApiComponents() output. |
| `$INCLUDE_SCHEMA_EXAMPLES` | `bool` | private static | Whether to include per-schema examples in openApiComponents(). |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`setIncludeSchemaExamples()`](#jsonhandler-setincludeschemaexamples) | public static | `void` | Toggle schema-level examples in OpenAPI output. |
| [`setExtraOpenApiComponents()`](#jsonhandler-setextraopenapicomponents) | public static | `void` | Merge extra OpenAPI components (securitySchemes, common responses, etc.). |
| [`setHttpPhrases()`](#jsonhandler-sethttpphrases) | public static | `void` | Override or extend default HTTP phrases. |
| [`httpPhrase()`](#jsonhandler-httpphrase) | public static | `string` | Resolve the HTTP phrase for a given status code. |
| [`registerExtraFields()`](#jsonhandler-registerextrafields) | public static | `void` | Register extra top-level fields included in every response. Each definition must contain: schema — OpenAPI schema fragment validator — 'string' \| 'int' \| 'data' \| 'errors' \| callable($value, bool $strict): mixed default — value used when the field is absent in lenient mode |
| [`pushExtraFields()`](#jsonhandler-pushextrafields) | public static | `void` | Push a temporary set of extra fields. Restore with popExtraFields(). |
| [`popExtraFields()`](#jsonhandler-popextrafields) | public static | `void` | Pop the last pushed extra-field snapshot. Clears extras when the stack is empty. |
| [`withExtraFields()`](#jsonhandler-withextrafields) | public static | `T` | Run a callback with temporarily registered extra fields. |
| [`respond()`](#jsonhandler-respond) | public static | `ResponseInterface` | Build a JSON response with the standard envelope. |
| [`ok()`](#jsonhandler-ok) | public static | `ResponseInterface` | Build a 2xx success response. |
| [`err()`](#jsonhandler-err) | public static | `ResponseInterface` | Build an error response using a symbolic error code looked up in an error map. Error map format: [ 'CODE' => ['http' => 404, 'msg' => 'Not found.'], ... ] Pipe-separated codes and /regex/ patterns are also supported as map keys. |
| [`unauthorized()`](#jsonhandler-unauthorized) | public static | `ResponseInterface` | Build a 401 Unauthorized response with a WWW-Authenticate: Bearer header. |
| [`forbidden()`](#jsonhandler-forbidden) | public static | `ResponseInterface` | Build a 403 Forbidden response with a WWW-Authenticate: Bearer header. When required scopes are provided they are included in the WWW-Authenticate header and in meta.required_scopes (if 'meta' is registered as an extra field). |
| [`fromResult()`](#jsonhandler-fromresult) | public static | `ResponseInterface` | Build a response from a service result array. Expected result shape: [ 'ok' => bool, 'data' => mixed, 'message' => string, 'code' => string, 'field' => string, 'detail' => string ] |
| [`openApiStandardResponseForCode()`](#jsonhandler-openapistandardresponseforcode) | public static | `array<string, mixed>` | Build an OpenAPI response object for a given HTTP status code. |
| [`openApiComponents()`](#jsonhandler-openapicomponents) | public static | `array<string, mixed>` | Build the OpenAPI components section (schemas for success and error envelopes). |
| [`example()`](#jsonhandler-example) | public static | `array<string, mixed>` | Build an example envelope array. |
| [`exampleSuccess()`](#jsonhandler-examplesuccess) | public static | `array<string, mixed>` | Build a success example envelope. |
| [`exampleError()`](#jsonhandler-exampleerror) | public static | `array<string, mixed>` | Build an error example envelope. |
| [`openApiNormalizeSchema()`](#jsonhandler-openapinormalizeschema) | public static | `array<string, mixed>` | Normalize a JSON schema fragment: fixes $ref mixed with other keys. |
| [`openApiNormalizeOperation()`](#jsonhandler-openapinormalizeoperation) | public static | `array<string, mixed>` | Normalize an OpenAPI operation array (fixes schema fragments and removes conflicting example/examples pairs). |
| [`fieldDefs()`](#jsonhandler-fielddefs) | private static | `array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>` | — |
| [`validateAndNormalize()`](#jsonhandler-validateandnormalize) | private static | `array<string, mixed>` | Validate and coerce every field in the payload according to its definition. Fields declared with omit_if_null that were not placed in $payload by respond() are skipped entirely so they remain absent from the output. |
| [`lookupError()`](#jsonhandler-lookuperror) | private static | `array<string, mixed>` | Look up an error map entry by code. Supports exact match, pipe-separated codes, and /regex/. |
| [`isAssoc()`](#jsonhandler-isassoc) | private static | `bool` | Return true when the array has string keys (associative). |
| [`exampleForDef()`](#jsonhandler-examplefordef) | private static | `mixed` | Build an example value for an extra field definition. |
| [`buildAdditionalExample()`](#jsonhandler-buildadditionalexample) | private static | `array<string, mixed>` | Build additionalData populated with per-extra-field example values. |

<details>
<summary><code>setIncludeSchemaExamples()</code></summary>

#### `JsonHandler setIncludeSchemaExamples()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L126)

```php
public static function setIncludeSchemaExamples(bool $on): void
```

Toggle schema-level examples in OpenAPI output.

| Param | Type | Description |
|---|---|---|
| `$on` | `bool` | — |

</details>

<details>
<summary><code>setExtraOpenApiComponents()</code></summary>

#### `JsonHandler setExtraOpenApiComponents()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L137)

```php
public static function setExtraOpenApiComponents(array $components): void
```

Merge extra OpenAPI components (securitySchemes, common responses, etc.).

| Param | Type | Description |
|---|---|---|
| `$components` | `array<string, mixed>` | — |

</details>

<details>
<summary><code>setHttpPhrases()</code></summary>

#### `JsonHandler setHttpPhrases()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L148)

```php
public static function setHttpPhrases(array $map): void
```

Override or extend default HTTP phrases.

| Param | Type | Description |
|---|---|---|
| `$map` | `array<int, string>` | — |

</details>

<details>
<summary><code>httpPhrase()</code></summary>

#### `JsonHandler httpPhrase()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L163)

```php
public static function httpPhrase(int $code): string
```

Resolve the HTTP phrase for a given status code.

| Param | Type | Description |
|---|---|---|
| `$code` | `int` | — |

**Returns:** `string`

</details>

<details>
<summary><code>registerExtraFields()</code></summary>

#### `JsonHandler registerExtraFields()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L184)

```php
public static function registerExtraFields(array $defs): void
```

Register extra top-level fields included in every response. Each definition must contain: schema    — OpenAPI schema fragment validator — 'string' | 'int' | 'data' | 'errors' | callable($value, bool $strict): mixed default   — value used when the field is absent in lenient mode

| Param | Type | Description |
|---|---|---|
| `$defs` | `array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>` | — |

| Throws | When |
|---|---|
| `InvalidArgumentException` | When a name conflicts with a core key or definition is incomplete. |

</details>

<details>
<summary><code>pushExtraFields()</code></summary>

#### `JsonHandler pushExtraFields()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L209)

```php
public static function pushExtraFields(array $defs): void
```

Push a temporary set of extra fields. Restore with popExtraFields().

| Param | Type | Description |
|---|---|---|
| `$defs` | `array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>` | — |

</details>

<details>
<summary><code>popExtraFields()</code></summary>

#### `JsonHandler popExtraFields()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L220)

```php
public static function popExtraFields(): void
```

Pop the last pushed extra-field snapshot. Clears extras when the stack is empty.

</details>

<details>
<summary><code>withExtraFields()</code></summary>

#### `JsonHandler withExtraFields()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L237)

```php
public static function withExtraFields(array $defs, callable $callback): mixed
```

Run a callback with temporarily registered extra fields.

| Param | Type | Description |
|---|---|---|
| `$defs` | `array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>` | — |
| `$callback` | `callable` | — |

**Returns:** `T`

</details>

<details>
<summary><code>respond()</code></summary>

#### `JsonHandler respond()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L263)

```php
public static function respond(ResponseInterface $response, string $status, int $code, string $message, mixed $data, array $additionalData, bool $strict): ResponseInterface
```

Build a JSON response with the standard envelope.

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$status` | `string` | 'success' or 'error'. |
| `$code` | `int` | HTTP status code. |
| `$message` | `string` | Human-readable message. |
| `$data` | `mixed` | Payload (array or object). |
| `$additionalData` | `array<string, mixed>` | Values for registered extra fields. |
| `$strict` | `bool` | Throw on invalid field values instead of coercing. |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>ok()</code></summary>

#### `JsonHandler ok()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L354)

```php
public static function ok(ResponseInterface $response, string $message, mixed $data, int $http, array $additionalData): ResponseInterface
```

Build a 2xx success response.

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$message` | `string` | — |
| `$data` | `mixed` | — |
| `$http` | `int` | HTTP status (default 200). |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>err()</code></summary>

#### `JsonHandler err()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L382)

```php
public static function err(ResponseInterface $response, string $code, array $map, ?int $httpOverride, ?string $messageOverride, ?string $field, ?string $detail, mixed $data, array $additionalData): ResponseInterface
```

Build an error response using a symbolic error code looked up in an error map. Error map format: [ 'CODE' => ['http' => 404, 'msg' => 'Not found.'], ... ] Pipe-separated codes and /regex/ patterns are also supported as map keys.

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$code` | `string` | Symbolic error code. |
| `$map` | `array<string, mixed>` | Error map. |
| `$httpOverride` | `int\|null` | Override the HTTP status from the map. |
| `$messageOverride` | `string\|null` | Override the message from the map. |
| `$field` | `string\|null` | Field name for the errors array entry. |
| `$detail` | `string\|null` | Additional detail for the errors array entry. |
| `$data` | `mixed` | — |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>unauthorized()</code></summary>

#### `JsonHandler unauthorized()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L422)

```php
public static function unauthorized(ResponseInterface $response, string $message, array $additionalData): ResponseInterface
```

Build a 401 Unauthorized response with a WWW-Authenticate: Bearer header.

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$message` | `string` | — |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>forbidden()</code></summary>

#### `JsonHandler forbidden()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L446)

```php
public static function forbidden(ResponseInterface $response, string $message, array $requiredScopes, array $additionalData): ResponseInterface
```

Build a 403 Forbidden response with a WWW-Authenticate: Bearer header. When required scopes are provided they are included in the WWW-Authenticate header and in meta.required_scopes (if 'meta' is registered as an extra field).

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$message` | `string` | — |
| `$requiredScopes` | `string[]` | — |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>fromResult()</code></summary>

#### `JsonHandler fromResult()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L476)

```php
public static function fromResult(ResponseInterface $response, array $result, array $errorMap, ?int $httpOverride, array $additionalData): ResponseInterface
```

Build a response from a service result array. Expected result shape: [ 'ok' => bool, 'data' => mixed, 'message' => string, 'code' => string, 'field' => string, 'detail' => string ]

| Param | Type | Description |
|---|---|---|
| `$response` | `ResponseInterface` | — |
| `$result` | `array<string, mixed>` | — |
| `$errorMap` | `array<string, mixed>` | — |
| `$httpOverride` | `int\|null` | — |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `ResponseInterface`

</details>

<details>
<summary><code>openApiStandardResponseForCode()</code></summary>

#### `JsonHandler openApiStandardResponseForCode()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L520)

```php
public static function openApiStandardResponseForCode(int $code, ?string $description, ?array $example): array
```

Build an OpenAPI response object for a given HTTP status code.

| Param | Type | Description |
|---|---|---|
| `$code` | `int` | — |
| `$description` | `string\|null` | — |
| `$example` | `array\|null` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>openApiComponents()</code></summary>

#### `JsonHandler openApiComponents()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L551)

```php
public static function openApiComponents(): array
```

Build the OpenAPI components section (schemas for success and error envelopes).

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>example()</code></summary>

#### `JsonHandler example()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L616)

```php
public static function example(string $status, int $code, string $message, mixed $data, array $additionalData): array
```

Build an example envelope array.

| Param | Type | Description |
|---|---|---|
| `$status` | `string` | — |
| `$code` | `int` | — |
| `$message` | `string` | — |
| `$data` | `mixed` | — |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>exampleSuccess()</code></summary>

#### `JsonHandler exampleSuccess()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L639)

```php
public static function exampleSuccess(string $message, mixed $data, int $code, array $additionalData): array
```

Build a success example envelope.

| Param | Type | Description |
|---|---|---|
| `$message` | `string` | — |
| `$data` | `mixed` | — |
| `$code` | `int` | — |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>exampleError()</code></summary>

#### `JsonHandler exampleError()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L652)

```php
public static function exampleError(int $code, string $message, array $additionalData): array
```

Build an error example envelope.

| Param | Type | Description |
|---|---|---|
| `$code` | `int` | — |
| `$message` | `string` | — |
| `$additionalData` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>openApiNormalizeSchema()</code></summary>

#### `JsonHandler openApiNormalizeSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L664)

```php
public static function openApiNormalizeSchema(array $schema, bool $forceRaw): array
```

Normalize a JSON schema fragment: fixes $ref mixed with other keys.

| Param | Type | Description |
|---|---|---|
| `$schema` | `array<string, mixed>` | — |
| `$forceRaw` | `bool` | Remove Standard*Response refs entirely. |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>openApiNormalizeOperation()</code></summary>

#### `JsonHandler openApiNormalizeOperation()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L698)

```php
public static function openApiNormalizeOperation(array $operation, bool $forceRaw): array
```

Normalize an OpenAPI operation array (fixes schema fragments and removes conflicting example/examples pairs).

| Param | Type | Description |
|---|---|---|
| `$operation` | `array<string, mixed>` | — |
| `$forceRaw` | `bool` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>fieldDefs()</code></summary>

#### `JsonHandler fieldDefs()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L736)

```php
private static function fieldDefs(): array
```

**Returns:** `array<string, array{schema: array<string, mixed>, validator: callable\|string, default: mixed}>`

</details>

<details>
<summary><code>validateAndNormalize()</code></summary>

#### `JsonHandler validateAndNormalize()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L752)

```php
private static function validateAndNormalize(array $payload, bool $strict): array
```

Validate and coerce every field in the payload according to its definition. Fields declared with omit_if_null that were not placed in $payload by respond() are skipped entirely so they remain absent from the output.

| Param | Type | Description |
|---|---|---|
| `$payload` | `array<string, mixed>` | — |
| `$strict` | `bool` | — |

**Returns:** `array<string, mixed>`

| Throws | When |
|---|---|
| `InvalidArgumentException` | In strict mode when a value is of the wrong type. |

</details>

<details>
<summary><code>lookupError()</code></summary>

#### `JsonHandler lookupError()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L839)

```php
private static function lookupError(string $code, array $map): array
```

Look up an error map entry by code. Supports exact match, pipe-separated codes, and /regex/.

| Param | Type | Description |
|---|---|---|
| `$code` | `string` | — |
| `$map` | `array<string, mixed>` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>isAssoc()</code></summary>

#### `JsonHandler isAssoc()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L863)

```php
private static function isAssoc(array $arr): bool
```

Return true when the array has string keys (associative).

| Param | Type | Description |
|---|---|---|
| `$arr` | `array<mixed, mixed>` | — |

**Returns:** `bool`

</details>

<details>
<summary><code>exampleForDef()</code></summary>

#### `JsonHandler exampleForDef()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L878)

```php
private static function exampleForDef(array $def, bool $forError): mixed
```

Build an example value for an extra field definition.

| Param | Type | Description |
|---|---|---|
| `$def` | `array{schema: array<string, mixed>, validator: callable\|string, default: mixed}` | — |
| `$forError` | `bool` | — |

**Returns:** `mixed`

</details>

<details>
<summary><code>buildAdditionalExample()</code></summary>

#### `JsonHandler buildAdditionalExample()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Http/JsonHandler.php#L896)

```php
private static function buildAdditionalExample(bool $forError): array
```

Build additionalData populated with per-extra-field example values.

| Param | Type | Description |
|---|---|---|
| `$forError` | `bool` | — |

**Returns:** `array<string, mixed>`

</details>

## `OpenApiRegistry`

*final class* — `rafalmasiarek\DashboardKitApi\OpenApi\OpenApiRegistry` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L43)

Collects API route metadata and produces a full OpenAPI 3.1 specification. Registered in the DI container as OpenApiRegistry::class when the API plugin is installed. Other plugins should check container->has(OpenApiRegistry::class) before interacting with this registry to remain independent of the API plugin. Usage from another plugin: <code> if ($container->has(OpenApiRegistry::class)) { $container->get(OpenApiRegistry::class) ->registerTag('scheduler', 'Scheduler endpoints'); } </code> Routes are registered automatically by ApiAddon::registerApiRoutes() from module 'api' key definitions. The 'openapi' sub-key on each route definition provides per-route metadata: <code> 'openapi' => [ 'summary'     => 'List notes', 'description' => 'Returns paginated notes for the authenticated user.', 'tags'        => ['notes'], 'parameters'  => [], 'requestBody' => null, 'responses'   => [ 200 => ['description' => 'Notes list', 'schema' => ['type' => 'array', ...]], ], 'deprecated'  => false, ] </code>

| Property | Type | Visibility | Description |
|---|---|---|---|
| `$routes` | `array<int, array{method: string, version: string, slug: string, path: string, scopes: string[], openapi: array<string, mixed>,}>` | private | Collected route entries. |
| `$tags` | `array<string, array{name: string, description: string\|null}>` | private | Registered tags keyed by name. |
| `$components` | `array<string, array<string, mixed>>` | private | Extra OpenAPI component sections (e.g. 'schemas', 'examples'). |
| `$info` | `array<string, mixed>` | private | OpenAPI info object. |
| `$servers` | `array<int, array<string, mixed>>` | private | OpenAPI servers array. |
| `$apiPrefix` | `string` | private | Optional route prefix applied before the version segment. Empty string means routes are at /{version}/..., 'api' means /api/{version}/... |

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`setInfo()`](#openapiregistry-setinfo) | public | `void` | Set the OpenAPI info block. |
| [`setServers()`](#openapiregistry-setservers) | public | `void` | Set the servers list. |
| [`setApiPrefix()`](#openapiregistry-setapiprefix) | public | `void` | Set the API route prefix used when building path objects in the spec. Must match the prefix used in ApiAddon::registerApiRoutes() so that paths in the spec correspond to actual registered Slim routes. |
| [`registerRoute()`](#openapiregistry-registerroute) | public | `void` | Register a route for inclusion in the OpenAPI spec. Called by ApiAddon::registerApiRoutes() for every discovered module route. |
| [`registerTag()`](#openapiregistry-registertag) | public | `void` | Register a named tag for grouping operations. Safe to call multiple times with the same name — the last description wins. |
| [`registerComponent()`](#openapiregistry-registercomponent) | public | `void` | Register an extra OpenAPI component (schema, example, parameter, etc.). |
| [`build()`](#openapiregistry-build) | public | `array<string, mixed>` | Build and return the complete OpenAPI 3.1 specification as a PHP array. The result is ready for json_encode(). Tags are auto-collected from all registered routes if not explicitly registered. Standard 401/403 responses are injected automatically for every route that requires scopes. |
| [`collectTags()`](#openapiregistry-collecttags) | private | `array<string, array{name: string, description: string\|null}>` | Collect all tags: explicitly registered ones merged with tags derived from routes. Route tags that have no matching explicit registration get an empty description. |
| [`buildPaths()`](#openapiregistry-buildpaths) | private | `array<string, array<string, mixed>>` | Build the OpenAPI paths object. Routes sharing the same full path are grouped under a single path entry, each HTTP method becoming a separate operation object. The path includes the optional API prefix, version, slug, and route path, e.g. /api/v1/notes/{id} when prefix is 'api'. |
| [`buildOperation()`](#openapiregistry-buildoperation) | private | `array<string, mixed>` | Build a single OpenAPI operation object. Auto-adds BearerAuth security for all routes. Injects 401/403 component references for routes that declare required scopes. The private '_paginated' key in $openapi is consumed here and forwarded to buildResponseObject() so that success response examples include the pagination object. It is never written into the spec output. |
| [`buildResponseObject()`](#openapiregistry-buildresponseobject) | private | `array<string, mixed>` | Build a single response object for a declared status code. All responses include a content block with the appropriate standard schema: - 2xx without 'schema' key → $ref StandardSuccessResponse, data: {} - 2xx with 'schema' key → allOf[StandardSuccessResponse, {properties: {data: <schema>}}] - 4xx/5xx → $ref StandardErrorResponse Optional keys in the response entry: 'schema' — (2xx only) describes the 'data' field payload 'code' — symbolic error code shown in errors[0].code of the auto-generated example 'example' — manual override for the entire example object (any status) 'no_content' — when true, omits the content block entirely (e.g. for 3xx redirects) 'headers' — map of response header definitions passed through to the OpenAPI object When $paginated is true, success examples include a representative pagination object. |
| [`buildSchemas()`](#openapiregistry-buildschemas) | private | `array<string, mixed>` | Build the components/schemas section. Merges schemas from JsonHandler (StandardSuccessResponse, StandardErrorResponse) with any additional schemas registered via registerComponent(). |
| [`buildStandardResponses()`](#openapiregistry-buildstandardresponses) | private | `array<string, mixed>` | Build standard reusable response components for 401 and 403. |
| [`statusPhrase()`](#openapiregistry-statusphrase) | private static | `string` | Return a standard HTTP reason phrase for a status code. |

<details>
<summary><code>setInfo()</code></summary>

#### `OpenApiRegistry setInfo()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L105)

```php
public function setInfo(array $info): void
```

Set the OpenAPI info block.

| Param | Type | Description |
|---|---|---|
| `$info` | `array<string, mixed>` | Must contain at least 'title' and 'version'. |

</details>

<details>
<summary><code>setServers()</code></summary>

#### `OpenApiRegistry setServers()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L116)

```php
public function setServers(array $servers): void
```

Set the servers list.

| Param | Type | Description |
|---|---|---|
| `$servers` | `array<int, array<string, mixed>>` | Each entry must have 'url'. |

</details>

<details>
<summary><code>setApiPrefix()</code></summary>

#### `OpenApiRegistry setApiPrefix()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L130)

```php
public function setApiPrefix(string $prefix): void
```

Set the API route prefix used when building path objects in the spec. Must match the prefix used in ApiAddon::registerApiRoutes() so that paths in the spec correspond to actual registered Slim routes.

| Param | Type | Description |
|---|---|---|
| `$prefix` | `string` | Without leading or trailing slash. Empty string = no prefix. |

</details>

<details>
<summary><code>registerRoute()</code></summary>

#### `OpenApiRegistry registerRoute()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L148)

```php
public function registerRoute(string $method, string $version, string $slug, string $path, array $scopes, array $openapi, bool $public): void
```

Register a route for inclusion in the OpenAPI spec. Called by ApiAddon::registerApiRoutes() for every discovered module route.

| Param | Type | Description |
|---|---|---|
| `$method` | `string` | HTTP method (GET, POST, …). |
| `$version` | `string` | Version key, e.g. 'v1'. |
| `$slug` | `string` | Module slug, e.g. 'notes'. |
| `$path` | `string` | Route path relative to slug, e.g. '/' or '/{id}'. |
| `$scopes` | `string[]` | Required scopes for this route. |
| `$openapi` | `array<string, mixed>` | Per-route OpenAPI metadata from module definition. |
| `$public` | `bool` | — |

</details>

<details>
<summary><code>registerTag()</code></summary>

#### `OpenApiRegistry registerTag()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L177)

```php
public function registerTag(string $name, ?string $description): void
```

Register a named tag for grouping operations. Safe to call multiple times with the same name — the last description wins.

| Param | Type | Description |
|---|---|---|
| `$name` | `string` | Tag name matching the 'tags' key on individual routes. |
| `$description` | `string\|null` | Optional human-readable description. |

</details>

<details>
<summary><code>registerComponent()</code></summary>

#### `OpenApiRegistry registerComponent()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L190)

```php
public function registerComponent(string $section, string $name, array $schema): void
```

Register an extra OpenAPI component (schema, example, parameter, etc.).

| Param | Type | Description |
|---|---|---|
| `$section` | `string` | Component section: 'schemas', 'examples', 'parameters', 'responses'. |
| `$name` | `string` | Component name (used as the $ref key). |
| `$schema` | `array<string, mixed>` | OpenAPI component object. |

</details>

<details>
<summary><code>build()</code></summary>

#### `OpenApiRegistry build()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L204)

```php
public function build(): array
```

Build and return the complete OpenAPI 3.1 specification as a PHP array. The result is ready for json_encode(). Tags are auto-collected from all registered routes if not explicitly registered. Standard 401/403 responses are injected automatically for every route that requires scopes.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>collectTags()</code></summary>

#### `OpenApiRegistry collectTags()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L248)

```php
private function collectTags(): array
```

Collect all tags: explicitly registered ones merged with tags derived from routes. Route tags that have no matching explicit registration get an empty description.

**Returns:** `array<string, array{name: string, description: string\|null}>`

</details>

<details>
<summary><code>buildPaths()</code></summary>

#### `OpenApiRegistry buildPaths()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L273)

```php
private function buildPaths(): array
```

Build the OpenAPI paths object. Routes sharing the same full path are grouped under a single path entry, each HTTP method becoming a separate operation object. The path includes the optional API prefix, version, slug, and route path, e.g. /api/v1/notes/{id} when prefix is 'api'.

**Returns:** `array<string, array<string, mixed>>`

</details>

<details>
<summary><code>buildOperation()</code></summary>

#### `OpenApiRegistry buildOperation()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L309)

```php
private function buildOperation(array $scopes, array $openapi, bool $public): array
```

Build a single OpenAPI operation object. Auto-adds BearerAuth security for all routes. Injects 401/403 component references for routes that declare required scopes. The private '_paginated' key in $openapi is consumed here and forwarded to buildResponseObject() so that success response examples include the pagination object. It is never written into the spec output.

| Param | Type | Description |
|---|---|---|
| `$scopes` | `string[]` | — |
| `$openapi` | `array<string, mixed>` | Per-route OpenAPI meta from module. |
| `$public` | `bool` | — |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>buildResponseObject()</code></summary>

#### `OpenApiRegistry buildResponseObject()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L379)

```php
private function buildResponseObject(int $code, array $resp, bool $paginated): array
```

Build a single response object for a declared status code. All responses include a content block with the appropriate standard schema: - 2xx without 'schema' key → $ref StandardSuccessResponse, data: {} - 2xx with 'schema' key    → allOf[StandardSuccessResponse, {properties: {data: <schema>}}] - 4xx/5xx                  → $ref StandardErrorResponse Optional keys in the response entry: 'schema'     — (2xx only) describes the 'data' field payload 'code'       — symbolic error code shown in errors[0].code of the auto-generated example 'example'    — manual override for the entire example object (any status) 'no_content' — when true, omits the content block entirely (e.g. for 3xx redirects) 'headers'    — map of response header definitions passed through to the OpenAPI object When $paginated is true, success examples include a representative pagination object.

| Param | Type | Description |
|---|---|---|
| `$code` | `int` | HTTP status code. |
| `$resp` | `array<string, mixed>` | Declared response entry from module openapi def. |
| `$paginated` | `bool` | Whether this operation supports pagination. |

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>buildSchemas()</code></summary>

#### `OpenApiRegistry buildSchemas()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L461)

```php
private function buildSchemas(): array
```

Build the components/schemas section. Merges schemas from JsonHandler (StandardSuccessResponse, StandardErrorResponse) with any additional schemas registered via registerComponent().

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>buildStandardResponses()</code></summary>

#### `OpenApiRegistry buildStandardResponses()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L472)

```php
private function buildStandardResponses(): array
```

Build standard reusable response components for 401 and 403.

**Returns:** `array<string, mixed>`

</details>

<details>
<summary><code>statusPhrase()</code></summary>

#### `OpenApiRegistry statusPhrase()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/OpenApi/OpenApiRegistry.php#L494)

```php
private static function statusPhrase(int $code): string
```

Return a standard HTTP reason phrase for a status code.

| Param | Type | Description |
|---|---|---|
| `$code` | `int` | — |

**Returns:** `string`

</details>

## `RedirectResponseDriver`

*class* — `rafalmasiarek\DashboardKitApi\ResponseDriver\RedirectResponseDriver` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ResponseDriver/RedirectResponseDriver.php#L16)

Response driver that issues an HTTP redirect. Handler must return ['redirect' => $url] in its result array. Optional 'http' key overrides the status code (default: 302).

implements [`ResponseDriverInterface`](#responsedriverinterface)

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`handle()`](#redirectresponsedriver-handle) | public | `ResponseInterface` | Build a redirect response from the handler result. |

<details>
<summary><code>handle()</code></summary>

#### `RedirectResponseDriver handle()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ResponseDriver/RedirectResponseDriver.php#L25)

```php
public function handle(ResponseInterface $res, array $result): ResponseInterface
```

Build a redirect response from the handler result.

| Param | Type | Description |
|---|---|---|
| `$res` | `ResponseInterface` | — |
| `$result` | `array<string, mixed>` | Handler result; expects 'redirect' key with target URL. |

**Returns:** `ResponseInterface`

</details>

## `ResponseDriverInterface`

*interface* — `rafalmasiarek\DashboardKitApi\ResponseDriverInterface` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ResponseDriverInterface.php#L15)

Contract for custom API response drivers. A response driver overrides the default JSON response building for a specific route. Register an instance in the route definition under the 'driver' key.

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`handle()`](#responsedriverinterface-handle) | public | `ResponseInterface` | Build a PSR-7 response from the handler result array. |

<details>
<summary><code>handle()</code></summary>

#### `ResponseDriverInterface handle()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/ResponseDriverInterface.php#L24)

```php
public function handle(ResponseInterface $res, array $result): ResponseInterface
```

Build a PSR-7 response from the handler result array.

| Param | Type | Description |
|---|---|---|
| `$res` | `ResponseInterface` | Blank PSR-7 response to mutate. |
| `$result` | `array<string, mixed>` | Handler return value. |

**Returns:** `ResponseInterface`

</details>

## `ApiSchemaProvider`

*final class* — `rafalmasiarek\DashboardKitApi\Schema\ApiSchemaProvider` · [source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Schema/ApiSchemaProvider.php#L21)

Creates the database tables required by the API token system. Tables created: user_tokens  — opaque bearer tokens tied to a user_id. scopes       — scope name dictionary (id, name UNIQUE). token_scopes — many-to-many junction between tokens and scopes. Called directly by ApiAddon::register() on every boot (all statements use IF NOT EXISTS).

| Method | Visibility | Returns | Summary |
|---|---|---|---|
| [`createSchema()`](#apischemaprovider-createschema) | public | `void` | {@inheritDoc} |
| [`createMysql()`](#apischemaprovider-createmysql) | private | `void` | Create tables for MySQL. |
| [`createSqlite()`](#apischemaprovider-createsqlite) | private | `void` | Create tables for SQLite. |

<details>
<summary><code>createSchema()</code></summary>

#### `ApiSchemaProvider createSchema()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Schema/ApiSchemaProvider.php#L26)

```php
public function createSchema(PDO $pdo): void
```

{@inheritDoc}

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | — |

</details>

<details>
<summary><code>createMysql()</code></summary>

#### `ApiSchemaProvider createMysql()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Schema/ApiSchemaProvider.php#L43)

```php
private function createMysql(PDO $pdo): void
```

Create tables for MySQL.

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | — |

</details>

<details>
<summary><code>createSqlite()</code></summary>

#### `ApiSchemaProvider createSqlite()`

[source](https://github.com/rafalmasiarek/php-dashboard-kit-addon-api/blob/main/src/Schema/ApiSchemaProvider.php#L103)

```php
private function createSqlite(PDO $pdo): void
```

Create tables for SQLite.

| Param | Type | Description |
|---|---|---|
| `$pdo` | `PDO` | — |

</details>



## License

![License: BUSL-1.1](https://img.shields.io/badge/license-BUSL--1.1-red)

Business Source License 1.1.

Source available; commercial use restricted until 2099-01-01.


For alternative licensing, contact: https://masiarek.pl/contact/?af_subject=Commercial+license+%E2%80%94+dashboard-kit-addon-api&af_message=Hello%2C+I+am+interested+in+a+commercial+license+for+dashboard-kit-addon-api.



See [LICENSE](LICENSE) for full details.
## Authors

- Rafał Masiarek | [website](https://masiarek.pl) | [github](https://github.com/rafalmasiarek)


<!-- references -->

[repo_link]: https://github.com/rafalmasiarek/php-dashboard-kit-addon-api
