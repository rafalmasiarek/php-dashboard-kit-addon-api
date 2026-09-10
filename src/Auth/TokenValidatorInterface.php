<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

/**
 * Validates an opaque bearer token and returns its claims on success.
 *
 * Returned claims shape:
 *   sub    (int)      User ID.
 *   email  (string)   User email.
 *   exp    (int|null) Expiry unix timestamp, null = never.
 *   scopes (string[]) Granted scope names, e.g. ['cron:run', 'cron:status'].
 *
 * @package rafalmasiarek\DashboardKitApi\Auth
 */
interface TokenValidatorInterface
{
    /**
     * Validate a raw bearer token.
     *
     * @param string $token Raw token value from Authorization: Bearer header.
     * @return array<string,mixed>|null Claims on success, null when invalid or expired.
     */
    public function validate(string $token): ?array;
}
