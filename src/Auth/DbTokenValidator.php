<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

use rafalmasiarek\DashboardKit\Model\Model;

/**
 * Validates bearer tokens against the database.
 *
 * Expected schema (see ApiSchemaProvider):
 *   user_tokens (token PK, user_id, subject NULL, expires_at NULL)
 *   token_scopes (token, scope_id) PK(token, scope_id)
 *   scopes (id PK, name UNIQUE)
 *
 * Returned claims shape:
 *   sub    (int)      User ID from user_tokens.user_id.
 *   email  (string)   User email from users.email.
 *   exp    (int|null) Expiry unix timestamp, null = never.
 *   scopes (string[]) Scope names assigned to the token.
 *
 * Note: AuthKit's users table does not carry is_active/is_suspend/is_admin.
 * Those checks are the application's responsibility, not the token validator's.
 *
 * @package rafalmasiarek\DashboardKitApi\Auth
 */
final class DbTokenValidator implements TokenValidatorInterface
{
    /**
     * @var string
     */
    private string $tblUsers;

    /**
     * @var string
     */
    private string $tblTokens;

    /**
     * @var string
     */
    private string $tblTokenScopes;

    /**
     * @var string
     */
    private string $tblScopes;

    /**
     * @param string $tblUsers       Users table (default: 'users').
     * @param string $tblTokens      API tokens table (default: 'user_tokens').
     * @param string $tblTokenScopes Token-scopes junction table (default: 'token_scopes').
     * @param string $tblScopes      Scopes dictionary table (default: 'scopes').
     */
    public function __construct(
        string $tblUsers = 'users',
        string $tblTokens = 'user_tokens',
        string $tblTokenScopes = 'token_scopes',
        string $tblScopes = 'scopes'
    ) {
        $this->tblUsers       = $tblUsers;
        $this->tblTokens      = $tblTokens;
        $this->tblTokenScopes = $tblTokenScopes;
        $this->tblScopes      = $tblScopes;
    }

    /**
     * {@inheritDoc}
     */
    public function validate(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $claims = $this->fetchClaims($token);
        if ($claims === null) {
            return null;
        }

        $claims['scopes'] = $this->fetchScopes($token);

        return $claims;
    }

    /**
     * Fetch base claims from user_tokens JOIN users.
     *
     * @param string $token
     * @return array<string,mixed>|null Null when not found or expired.
     */
    private function fetchClaims(string $token): ?array
    {
        $row = Model::on($this->tblTokens)
            ->select("{$this->tblTokens}.user_id", "{$this->tblTokens}.expires_at", "{$this->tblUsers}.email")
            ->join($this->tblUsers, "{$this->tblUsers}.id", '=', "{$this->tblTokens}.user_id")
            ->where('token', $token)
            ->first();

        if ($row === null) {
            return null;
        }

        $exp = null;
        if (!empty($row['expires_at'])) {
            $ts = \strtotime((string) $row['expires_at']);
            if ($ts !== false) {
                if ($ts < \time()) {
                    return null;
                }
                $exp = $ts;
            }
        }

        return [
            'sub'   => (string) $row['user_id'],
            'email' => (string) $row['email'],
            'exp'   => $exp,
        ];
    }

    /**
     * Fetch scope names assigned to a token.
     *
     * @param string $token
     * @return string[]
     */
    private function fetchScopes(string $token): array
    {
        $names = Model::on($this->tblTokenScopes)
            ->select("{$this->tblScopes}.name")
            ->join($this->tblScopes, "{$this->tblScopes}.id", '=', "{$this->tblTokenScopes}.scope_id")
            ->where('token', $token)
            ->orderBy('name')
            ->get()
            ->pluck('name');

        return \array_values(\array_map('strval', $names));
    }
}
