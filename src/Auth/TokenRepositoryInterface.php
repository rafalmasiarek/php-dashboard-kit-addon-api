<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

/**
 * Persistence abstraction for API access tokens.
 *
 * @package rafalmasiarek\DashboardKitApi\Auth
 */
interface TokenRepositoryInterface
{
    /**
     * Return all tokens as token => claims map.
     *
     * @return array<string,array<string,mixed>>
     */
    public function all(): array;

    /**
     * Return claims for a token, or null if not found.
     *
     * @param string $token
     * @return array<string,mixed>|null
     */
    public function get(string $token): ?array;

    /**
     * Insert or update token claims. Claims must include 'user_id' (int).
     *
     * Optional claims: 'exp' (int unix timestamp), 'subject' (string label).
     *
     * @param string              $token
     * @param array<string,mixed> $claims
     * @return void
     */
    public function put(string $token, array $claims): void;

    /**
     * Delete a token.
     *
     * @param string $token
     * @return bool True when a row was actually removed.
     */
    public function delete(string $token): bool;
}
