<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

use PDO;

/**
 * Persists API tokens in the database.
 *
 * Tables (see ApiSchemaProvider):
 *   user_tokens (token PK, user_id FK, subject NULL, expires_at NULL)
 *   scopes      (id PK AUTO_INCREMENT, name UNIQUE)
 *   token_scopes (token, scope_id) PK(token, scope_id)
 *
 * @package rafalmasiarek\DashboardKitApi\Auth
 */
final class DbTokenRepository implements TokenRepositoryInterface
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
    private string $tblScopes;

    /**
     * @var string
     */
    private string $tblTokenScopes;

    /**
     * @param PDO    $pdo
     * @param string $tblUsers       Users table (default: 'users').
     * @param string $tblTokens      API tokens table (default: 'user_tokens').
     * @param string $tblScopes      Scopes dictionary (default: 'scopes').
     * @param string $tblTokenScopes Token-scopes junction (default: 'token_scopes').
     */
    public function __construct(
        PDO $pdo,
        string $tblUsers = 'users',
        string $tblTokens = 'user_tokens',
        string $tblScopes = 'scopes',
        string $tblTokenScopes = 'token_scopes'
    ) {
        $this->pdo            = $pdo;
        $this->tblUsers       = $tblUsers;
        $this->tblTokens      = $tblTokens;
        $this->tblScopes      = $tblScopes;
        $this->tblTokenScopes = $tblTokenScopes;
    }

    /**
     * Return all tokens as token => claims map.
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array
    {
        $sql = \sprintf(
            'SELECT t.token, t.user_id, t.subject, t.expires_at, u.email
             FROM %s t
             JOIN %s u ON u.id = t.user_id
             ORDER BY t.token ASC',
            $this->qi($this->tblTokens),
            $this->qi($this->tblUsers)
        );

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out  = [];

        foreach ($rows as $r) {
            $token        = (string) $r['token'];
            $out[$token]  = [
                'user_id' => (string) $r['user_id'],
                'sub'     => (string) $r['email'],
                'subject' => isset($r['subject']) ? (string) $r['subject'] : null,
                'exp'     => $this->toUnix((string) ($r['expires_at'] ?? '')),
                'scopes'  => $this->getTokenScopesByNames($token),
            ];
        }

        return $out;
    }

    /**
     * Return all tokens for a specific user.
     *
     * @param string $userId UUID string, as stored by AuthKit PdoUserStorage with UuidUserIdPolicy.
     * @return array<string,array<string,mixed>>
     */
    public function allByUser(string $userId): array
    {
        $sql = \sprintf(
            'SELECT t.token, t.user_id, t.subject, t.expires_at, u.email
             FROM %s t
             JOIN %s u ON u.id = t.user_id
             WHERE t.user_id = :uid
             ORDER BY t.token ASC',
            $this->qi($this->tblTokens),
            $this->qi($this->tblUsers)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out  = [];

        foreach ($rows as $r) {
            $token        = (string) $r['token'];
            $out[$token]  = [
                'user_id' => (string) $r['user_id'],
                'sub'     => (string) $r['email'],
                'subject' => isset($r['subject']) ? (string) $r['subject'] : null,
                'exp'     => $this->toUnix((string) ($r['expires_at'] ?? '')),
                'scopes'  => $this->getTokenScopesByNames($token),
            ];
        }

        return $out;
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $token): ?array
    {
        $sql = \sprintf(
            'SELECT t.token, t.user_id, t.subject, t.expires_at, u.email
             FROM %s t
             JOIN %s u ON u.id = t.user_id
             WHERE t.token = :token
             LIMIT 1',
            $this->qi($this->tblTokens),
            $this->qi($this->tblUsers)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($r === null) {
            return null;
        }

        return [
            'user_id' => (string) $r['user_id'],
            'sub'     => (string) $r['email'],
            'subject' => isset($r['subject']) ? (string) $r['subject'] : null,
            'exp'     => $this->toUnix((string) ($r['expires_at'] ?? '')),
            'scopes'  => $this->getTokenScopesByNames($token),
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @throws \InvalidArgumentException When claims['user_id'] is missing or invalid.
     */
    public function put(string $token, array $claims): void
    {
        $userId = (string) ($claims['user_id'] ?? '');
        if ($userId === '') {
            throw new \InvalidArgumentException('DbTokenRepository::put requires claims["user_id"].');
        }

        $exp = null;
        if (isset($claims['exp']) && \is_int($claims['exp'])) {
            $exp = \date('Y-m-d H:i:s', $claims['exp']);
        }

        $subject = null;
        if (isset($claims['subject']) && \is_string($claims['subject']) && $claims['subject'] !== '') {
            $subject = $claims['subject'];
        }

        $sql = \sprintf(
            'INSERT INTO %s (token, user_id, subject, expires_at)
             VALUES (:token, :uid, :subject, :exp)
             ON DUPLICATE KEY UPDATE
               user_id    = VALUES(user_id),
               subject    = VALUES(subject),
               expires_at = VALUES(expires_at)',
            $this->qi($this->tblTokens)
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':token'   => $token,
            ':uid'     => $userId,
            ':subject' => $subject,
            ':exp'     => $exp,
        ]);
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $token): bool
    {
        $sql  = \sprintf('DELETE FROM %s WHERE token = :token', $this->qi($this->tblTokens));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':token' => $token]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Replace scope assignments for a token with an exact set of scope names.
     *
     * @param string   $token
     * @param string[] $names Scope names (e.g. ['cron:run', 'cron:status']).
     * @return void
     */
    public function setTokenScopesByNames(string $token, array $names): void
    {
        $names = \array_values(\array_unique(\array_filter(\array_map('strval', $names))));

        $ids = [];
        if ($names !== []) {
            $in  = \implode(',', \array_fill(0, \count($names), '?'));
            $sql = \sprintf('SELECT id FROM %s WHERE name IN (%s)', $this->qi($this->tblScopes), $in);
            $st  = $this->pdo->prepare($sql);
            $st->execute($names);
            $ids = \array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0) ?: []);
        }

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                \sprintf('DELETE FROM %s WHERE token = :token', $this->qi($this->tblTokenScopes))
            )->execute([':token' => $token]);

            if ($ids !== []) {
                $ins = $this->pdo->prepare(
                    \sprintf('INSERT INTO %s (token, scope_id) VALUES (:token, :sid)', $this->qi($this->tblTokenScopes))
                );
                foreach ($ids as $sid) {
                    $ins->execute([':token' => $token, ':sid' => $sid]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Return scope names assigned to a token.
     *
     * @param string $token
     * @return string[]
     */
    public function getTokenScopesByNames(string $token): array
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
     * Convert a DATETIME string to unix timestamp or null.
     *
     * @param string $datetime
     * @return int|null
     */
    private function toUnix(string $datetime): ?int
    {
        $datetime = \trim($datetime);
        if ($datetime === '') {
            return null;
        }
        $ts = \strtotime($datetime);
        return $ts === false ? null : $ts;
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
