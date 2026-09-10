<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Audit;

use Psr\Log\LoggerInterface;

/**
 * Writes structured audit entries for API token requests.
 *
 * Each method logs a single event with a consistent context shape.
 * Mirrors the pattern of DashboardKit's core AuditLog — one method per event,
 * PSR-3 logger injected via constructor, named events in dot notation.
 *
 * Event names:
 *   api.token.allowed — token valid and scopes satisfied (INFO)
 *   api.token.denied  — 401 (missing/invalid token) or 403 (insufficient scope) (WARNING)
 *
 * Token values are never logged in full. Only the first 8 characters are stored
 * as a correlation prefix so individual requests can be traced without exposing
 * the credential.
 *
 * @package rafalmasiarek\DashboardKitApi\Audit
 */
final class ApiTokenAuditLog
{
    /**
     * PSR-3 logger receiving audit entries.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param LoggerInterface $logger PSR-3 logger (typically the 'api' or 'audit' channel).
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Log a successful API request — token valid, scopes satisfied.
     *
     * @param  string   $tokenPrefix   First 8 characters of the bearer token.
     * @param  string   $userId        User ID from token claims.
     * @param  string   $email         User email from token claims (sub).
     * @param  string   $ip            Client IP address.
     * @param  string   $method        HTTP method (GET, POST, …).
     * @param  string   $path          Request path (e.g. /v1/notes).
     * @param  string[] $scopesRequired Scopes required by the route.
     * @param  string[] $scopesGranted  Scopes present in the token.
     * @return void
     */
    public function allowed(
        string $tokenPrefix,
        string $userId,
        string $email,
        string $ip,
        string $method,
        string $path,
        array $scopesRequired,
        array $scopesGranted
    ): void {
        $this->logger->info('api.token.allowed', [
            'token'           => $tokenPrefix,
            'user_id'         => $userId,
            'email'           => $email,
            'ip'              => $ip,
            'method'          => $method,
            'path'            => $path,
            'scopes_required' => $scopesRequired,
            'scopes_granted'  => $scopesGranted,
        ]);
    }

    /**
     * Log a denied API request — missing/invalid token or insufficient scope.
     *
     * @param  string      $reason        Deny reason: 'missing_token' | 'invalid_token' | 'insufficient_scope'.
     * @param  string      $ip            Client IP address.
     * @param  string      $method        HTTP method.
     * @param  string      $path          Request path.
     * @param  string[]    $scopesRequired Scopes required by the route.
     * @param  string|null $tokenPrefix   First 8 chars of the token, null when no token was present.
     * @param  string|null $userId        User ID from claims, null on auth failure.
     * @param  string|null $email         User email from claims, null on auth failure.
     * @return void
     */
    public function denied(
        string $reason,
        string $ip,
        string $method,
        string $path,
        array $scopesRequired,
        ?string $tokenPrefix = null,
        ?string $userId = null,
        ?string $email = null
    ): void {
        $context = [
            'deny_reason'     => $reason,
            'ip'              => $ip,
            'method'          => $method,
            'path'            => $path,
            'scopes_required' => $scopesRequired,
        ];

        if ($tokenPrefix !== null) {
            $context['token'] = $tokenPrefix;
        }
        if ($userId !== null) {
            $context['user_id'] = $userId;
        }
        if ($email !== null) {
            $context['email'] = $email;
        }

        $this->logger->warning('api.token.denied', $context);
    }
}
