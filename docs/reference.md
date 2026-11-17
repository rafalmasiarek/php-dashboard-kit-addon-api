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
