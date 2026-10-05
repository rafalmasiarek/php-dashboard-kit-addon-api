<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

use rafalmasiarek\DashboardKit\Model\Model;

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
     * @param string $tblUsers       Users table (default: 'users').
     * @param string $tblTokens      API tokens table (default: 'user_tokens').
     * @param string $tblScopes      Scopes dictionary (default: 'scopes').
     * @param string $tblTokenScopes Token-scopes junction (default: 'token_scopes').
     */
    public function __construct(
        string $tblUsers = 'users',
        string $tblTokens = 'user_tokens',
        string $tblScopes = 'scopes',
        string $tblTokenScopes = 'token_scopes'
    ) {
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
        $rows = Model::on($this->tblTokens)
            ->select(
                "{$this->tblTokens}.token",
                "{$this->tblTokens}.user_id",
                "{$this->tblTokens}.subject",
                "{$this->tblTokens}.expires_at",
                "{$this->tblUsers}.email",
            )
            ->join($this->tblUsers, "{$this->tblUsers}.id", '=', "{$this->tblTokens}.user_id")
            ->orderBy('token')
            ->get()
            ->toArray();

        return $this->mapByToken($rows);
    }

    /**
     * Return all tokens for a specific user.
     *
     * @param string $userId UUID string, as stored by AuthKit PdoUserStorage with UuidUserIdPolicy.
     * @return array<string,array<string,mixed>>
     */
    public function allByUser(string $userId): array
    {
        $rows = Model::on($this->tblTokens)
            ->select(
                "{$this->tblTokens}.token",
                "{$this->tblTokens}.user_id",
                "{$this->tblTokens}.subject",
                "{$this->tblTokens}.expires_at",
                "{$this->tblUsers}.email",
            )
            ->join($this->tblUsers, "{$this->tblUsers}.id", '=', "{$this->tblTokens}.user_id")
            ->where('user_id', $userId)
            ->orderBy('token')
            ->get()
            ->toArray();

        return $this->mapByToken($rows);
    }

    /**
     * {@inheritDoc}
     */
    public function get(string $token): ?array
    {
        $row = Model::on($this->tblTokens)
            ->select(
                "{$this->tblTokens}.token",
                "{$this->tblTokens}.user_id",
                "{$this->tblTokens}.subject",
                "{$this->tblTokens}.expires_at",
                "{$this->tblUsers}.email",
            )
            ->join($this->tblUsers, "{$this->tblUsers}.id", '=', "{$this->tblTokens}.user_id")
            ->where('token', $token)
            ->first();

        return $row !== null ? $this->toClaims($row) : null;
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

        Model::on($this->tblTokens)->upsert(
            ['token' => $token, 'user_id' => $userId, 'subject' => $subject, 'expires_at' => $exp],
            ['token'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function delete(string $token): bool
    {
        return Model::on($this->tblTokens)->where('token', $token)->forceDelete() > 0;
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

        $ids = $names !== []
            ? Model::on($this->tblScopes)->select('id')->whereIn('name', $names)->get()->pluck('id')
            : [];

        Model::transaction(function () use ($token, $ids): void {
            Model::on($this->tblTokenScopes)->where('token', $token)->forceDelete();

            foreach ($ids as $scopeId) {
                Model::on($this->tblTokenScopes)->insert(['token' => $token, 'scope_id' => (int) $scopeId]);
            }
        });
    }

    /**
     * Return scope names assigned to a token.
     *
     * @param string $token
     * @return string[]
     */
    public function getTokenScopesByNames(string $token): array
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

    /**
     * Converts a list of joined token+email rows into a token => claims map.
     *
     * @param  list<array<string,mixed>> $rows
     * @return array<string,array<string,mixed>>
     */
    private function mapByToken(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['token']] = $this->toClaims($row);
        }
        return $out;
    }

    /**
     * Converts one joined token+email row into the claims shape.
     *
     * @param  array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function toClaims(array $row): array
    {
        return [
            'user_id' => (string) $row['user_id'],
            'sub'     => (string) $row['email'],
            'subject' => isset($row['subject']) ? (string) $row['subject'] : null,
            'exp'     => $this->toUnix((string) ($row['expires_at'] ?? '')),
            'scopes'  => $this->getTokenScopesByNames((string) $row['token']),
        ];
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
}
