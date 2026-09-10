<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use rafalmasiarek\DashboardKitApi\Http\JsonHandler;
use Slim\Routing\RouteContext;

/**
 * PSR-15 middleware that enforces per-route scope requirements.
 *
 * Reads required scopes from the Slim route argument 'required_scopes'
 * (JSON-encoded string array). Reads granted scopes from the request attribute
 * set by TokenAuthMiddleware (default: 'auth' → claims['scopes']).
 *
 * Super scopes '*' and 'admin:*' bypass all checks.
 * A 'namespace:*' scope satisfies any 'namespace:action' requirement.
 *
 * Add in reverse order in Slim (last added runs first):
 *   $route->add(new TokenScopeMiddleware(...))->add(new TokenAuthMiddleware(...));
 *
 * @package rafalmasiarek\DashboardKitApi\Auth
 */
final class TokenScopeMiddleware implements MiddlewareInterface
{
    /**
     * @var ResponseFactoryInterface
     */
    private ResponseFactoryInterface $responses;

    /**
     * @var string Request attribute carrying auth claims.
     */
    private string $claimsAttribute;

    /**
     * @var string Slim route argument carrying JSON-encoded required scopes.
     */
    private string $routeArg;

    /**
     * @param ResponseFactoryInterface $responses
     * @param string                   $claimsAttribute Request attribute for claims (default: 'auth').
     * @param string                   $routeArg        Route argument name (default: 'required_scopes').
     */
    public function __construct(
        ResponseFactoryInterface $responses,
        string $claimsAttribute = 'auth',
        string $routeArg = 'required_scopes'
    ) {
        $this->responses       = $responses;
        $this->claimsAttribute = $claimsAttribute;
        $this->routeArg        = $routeArg;
    }

    /**
     * {@inheritDoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $required = $this->requiredScopesFromRoute($request);

        if ($required === []) {
            return $handler->handle($request);
        }

        $request = $request->withAttribute('required_scopes', $required);

        /** @var mixed $claims */
        $claims = $request->getAttribute($this->claimsAttribute);

        if (!\is_array($claims)) {
            return JsonHandler::unauthorized(
                $this->responses->createResponse(),
                'Missing authentication.'
            )
                ->withHeader('X-Auth-Error', 'missing_token')
                ->withHeader('X-Required-Scopes', \implode(',', $required));
        }

        $granted = $this->normalizeGrantedScopes($claims);

        if ($this->hasSuperScope($granted)) {
            return $handler->handle($request);
        }

        if (!$this->scopesSatisfy($granted, $required)) {
            return JsonHandler::forbidden(
                $this->responses->createResponse(),
                'Insufficient scope.',
                $required
            )
                ->withHeader('X-Auth-Error', 'missing_scope')
                ->withHeader('X-Required-Scopes', \implode(',', $required));
        }

        return $handler->handle($request);
    }

    /**
     * Extract required scopes from the current Slim route argument.
     *
     * @param ServerRequestInterface $request
     * @return string[]
     */
    private function requiredScopesFromRoute(ServerRequestInterface $request): array
    {
        $route = RouteContext::fromRequest($request)->getRoute();
        if ($route === null) {
            return [];
        }

        /** @var mixed $raw */
        $raw = $route->getArgument($this->routeArg);
        if ($raw === null || $raw === '') {
            return [];
        }

        if (\is_array($raw)) {
            return \array_values(\array_filter(\array_map('strval', $raw), static fn($s) => $s !== ''));
        }

        if (!\is_string($raw)) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
            if (!\is_array($decoded)) {
                return [];
            }
            $out = [];
            foreach ($decoded as $v) {
                if (\is_string($v) && $v !== '') {
                    $out[] = $v;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Normalize granted scopes from claims array.
     *
     * Accepts claims['scopes'] as string[] or space/comma-delimited string.
     *
     * @param array<string,mixed> $claims
     * @return string[]
     */
    private function normalizeGrantedScopes(array $claims): array
    {
        $raw = $claims['scopes'] ?? null;

        $list = [];
        if (\is_string($raw)) {
            $list = \preg_split('/[\s,]+/', \trim($raw)) ?: [];
        } elseif (\is_array($raw)) {
            foreach ($raw as $item) {
                if (\is_string($item)) {
                    $list[] = $item;
                }
            }
        }

        return \array_values(\array_unique(\array_filter(\array_map('strval', $list), static fn($s) => $s !== '')));
    }

    /**
     * True when token has a super scope that bypasses all checks.
     *
     * @param string[] $granted
     * @return bool
     */
    private function hasSuperScope(array $granted): bool
    {
        return \in_array('*', $granted, true) || \in_array('admin:*', $granted, true);
    }

    /**
     * True when all required scopes are satisfied by granted scopes.
     *
     * @param string[] $granted
     * @param string[] $required
     * @return bool
     */
    private function scopesSatisfy(array $granted, array $required): bool
    {
        foreach ($required as $req) {
            if (!$this->isScopeSatisfied($granted, $req)) {
                return false;
            }
        }
        return true;
    }

    /**
     * True when a single required scope is satisfied by any granted scope.
     *
     * @param string[] $granted
     * @param string   $required
     * @return bool
     */
    private function isScopeSatisfied(array $granted, string $required): bool
    {
        foreach ($granted as $own) {
            if ($this->scopeMatches($own, $required)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Match a granted scope against a required scope.
     *
     * Rules: exact match, '*', 'namespace:*' matches 'namespace:action'.
     *
     * @param string $own      Granted scope.
     * @param string $required Required scope.
     * @return bool
     */
    private function scopeMatches(string $own, string $required): bool
    {
        if ($own === '*' || $own === $required) {
            return true;
        }
        if (\str_ends_with($own, ':*')) {
            $prefix = \substr($own, 0, -2);
            return $required === $prefix || \str_starts_with($required, $prefix . ':');
        }
        return false;
    }
}
