<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Audit;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use rafalmasiarek\RealIpResolver;
use Slim\Routing\RouteContext;

/**
 * PSR-15 middleware that writes an audit entry for every versioned API request.
 *
 * Must be added OUTSIDE TokenAuthMiddleware and TokenScopeMiddleware so that it
 * wraps the entire auth+scope chain and can inspect the final response:
 *
 *   ->add(TokenScopeMiddleware)   // innermost — per-route
 *   ->add(TokenAuthMiddleware)
 *   ->add(ApiTokenAuditMiddleware) // outermost — sees the final response
 *
 * Outcome detection (post-response):
 *   X-Auth-Error: missing_token       → denied, reason 'missing_token'
 *   X-Auth-Error: invalid_or_expired  → denied, reason 'invalid_token'
 *   HTTP 403                          → denied, reason 'insufficient_scope'
 *   HTTP 2xx/3xx/4xx (no auth error)  → allowed
 *
 * Token logging:
 *   Only the first 8 characters of the Bearer token are recorded (e.g. "a1b2c3d4…")
 *   to allow per-request correlation without storing the full credential.
 *
 * @package rafalmasiarek\DashboardKitApi\Audit
 */
final class ApiTokenAuditMiddleware implements MiddlewareInterface
{
    /**
     * @var ApiTokenAuditLog
     */
    private ApiTokenAuditLog $auditLog;

    /** @var RealIpResolver|null */
    private ?RealIpResolver $resolver;

    /**
     * @param ApiTokenAuditLog  $auditLog Audit logger for token events.
     * @param RealIpResolver|null $resolver Real IP resolver; falls back to REMOTE_ADDR when null.
     */
    public function __construct(ApiTokenAuditLog $auditLog, ?RealIpResolver $resolver = null)
    {
        $this->auditLog = $auditLog;
        $this->resolver = $resolver;
    }

    /**
     * {@inheritDoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $status   = $response->getStatusCode();
        $ip       = $this->resolver !== null
            ? ($this->resolver->getIp() ?: '')
            : (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        $method   = $request->getMethod();
        $path     = $request->getUri()->getPath();

        // Required scopes: read from Slim route argument (set at registration time)
        // so they are available even when TokenScopeMiddleware returns early with 403.
        $scopesRequired = $this->requiredScopesFromRoute($request);

        // Token prefix extracted from the Authorization header — never log the full token.
        $tokenPrefix = $this->extractTokenPrefix($request);

        // Auth claims are stored by TokenAuthMiddleware on success.
        /** @var mixed $claims */
        $claims  = $request->getAttribute('auth');
        $isArray = \is_array($claims);

        $userId  = $isArray ? (string) ($claims['user_id'] ?? ($claims['sub'] ?? '')) : null;
        $email   = $isArray ? (string) ($claims['sub']     ?? '')                     : null;
        $granted = $isArray ? (array)  ($claims['scopes']  ?? [])                     : [];

        $authError = $response->getHeaderLine('X-Auth-Error');

        if ($authError === 'missing_token') {
            $this->auditLog->denied('missing_token', $ip, $method, $path, $scopesRequired);
            return $response;
        }

        if ($authError === 'invalid_or_expired') {
            $this->auditLog->denied('invalid_token', $ip, $method, $path, $scopesRequired, $tokenPrefix);
            return $response;
        }

        if ($status === 403 || $authError === 'missing_scope') {
            $this->auditLog->denied('insufficient_scope', $ip, $method, $path, $scopesRequired, $tokenPrefix, $userId, $email);
            return $response;
        }

        // Any other response (2xx, 4xx from the route handler itself) is a passed-through request.
        if ($isArray) {
            $this->auditLog->allowed($tokenPrefix ?? '', $userId ?? '', $email ?? '', $ip, $method, $path, $scopesRequired, $granted);
        }

        return $response;
    }

    /**
     * Extract the first 8 characters of the Bearer token as a correlation prefix.
     *
     * @param  ServerRequestInterface $request
     * @return string|null Null when no Authorization header is present.
     */
    private function extractTokenPrefix(ServerRequestInterface $request): ?string
    {
        $header = $request->getHeaderLine('Authorization');
        if (\preg_match('/^\s*Bearer\s+(.{8})/i', $header, $m)) {
            return $m[1] . '…';
        }
        return null;
    }

    /**
     * Read required scopes directly from the Slim route argument.
     *
     * Reading from the route argument (set at registration time) rather than from
     * a request attribute ensures the value is available even when TokenScopeMiddleware
     * returns early with 403 before it can set the request attribute.
     *
     * @param  ServerRequestInterface $request
     * @return string[]
     */
    private function requiredScopesFromRoute(ServerRequestInterface $request): array
    {
        try {
            $route = RouteContext::fromRequest($request)->getRoute();
            if ($route === null) {
                return [];
            }
            $raw = $route->getArgument('required_scopes');
            if ($raw === null || $raw === '') {
                return [];
            }
            /** @var mixed $decoded */
            $decoded = \json_decode((string) $raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                return [];
            }
            return \array_values(\array_filter(\array_map('strval', $decoded)));
        } catch (\Throwable) {
            return [];
        }
    }
}
