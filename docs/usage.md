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
