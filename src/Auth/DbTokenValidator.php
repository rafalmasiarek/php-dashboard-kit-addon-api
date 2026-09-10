<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

use PDO;

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
     * @var PDO
     */
    private PDO $pdo;

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
     * @param PDO    $pdo
     * @param string $tblUsers       Users table (default: 'users').
     * @param string $tblTokens      API tokens table (default: 'user_tokens').
     * @param string $tblTokenScopes Token-scopes junction table (default: 'token_scopes').
     * @param string $tblScopes      Scopes dictionary table (default: 'scopes').
     */
    public function __construct(
        PDO $pdo,
        string $tblUsers = 'users',
        string $tblTokens = 'user_tokens',
        string $tblTokenScopes = 'token_scopes',
        string $tblScopes = 'scopes'
    ) {
        $this->pdo            = $pdo;
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
        $sql = \sprintf(
            'SELECT t.user_id, t.expires_at, u.email
             FROM %s t
             JOIN %s u ON u.id = t.user_id
             WHERE t.token = :token
             LIMIT 1',
            $this->qi($this->tblTokens),
            $this->qi($this->tblUsers)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

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
        $sql = \sprintf(
            'SELECT s.name
             FROM %s ts
             JOIN %s s ON s.id = ts.scope_id
             WHERE ts.token = :token
             ORDER BY s.name',
            $this->qi($this->tblTokenScopes),
            $this->qi($this->tblScopes)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

        return \array_values(\array_map('strval', $rows));
    }

    /**
     * Backtick-quote an identifier (trusted table names only).
     *
     * @param string $ident
     * @return string
     */
    private function qi(string $ident): string
    {
        return '`' . \str_replace('`', '``', $ident) . '`';
    }
}
