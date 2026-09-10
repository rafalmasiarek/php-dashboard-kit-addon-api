<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use rafalmasiarek\DashboardKitApi\Http\JsonHandler;

/**
 * PSR-15 middleware that extracts and validates a Bearer token.
 *
 * On success, attaches token claims to the request attribute (default: 'auth').
 * On failure, returns 401 JSON with WWW-Authenticate header.
 *
 * Must run before TokenScopeMiddleware (add in reverse order in Slim):
 *   $route->add(new TokenScopeMiddleware(...))->add(new TokenAuthMiddleware(...));
 *
 * @package rafalmasiarek\DashboardKitApi\Auth
 */
final class TokenAuthMiddleware implements MiddlewareInterface
{
    /**
     * @var TokenValidatorInterface
     */
    private TokenValidatorInterface $validator;

    /**
     * @var ResponseFactoryInterface
     */
    private ResponseFactoryInterface $responses;

    /**
     * @var string Request attribute name for attaching claims.
     */
    private string $attribute;

    /**
     * @var bool Whether to also accept ?access_token= query param.
     */
    private bool $allowQueryParam;

    /**
     * @param TokenValidatorInterface  $validator
     * @param ResponseFactoryInterface $responses
     * @param string                   $attribute       Request attribute for claims (default: 'auth').
     * @param bool                     $allowQueryParam Accept ?access_token= fallback (default: false).
     */
    public function __construct(
        TokenValidatorInterface $validator,
        ResponseFactoryInterface $responses,
        string $attribute = 'auth',
        bool $allowQueryParam = false
    ) {
        $this->validator       = $validator;
        $this->responses       = $responses;
        $this->attribute       = $attribute;
        $this->allowQueryParam = $allowQueryParam;
    }

    /**
     * {@inheritDoc}
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return JsonHandler::unauthorized(
                $this->responses->createResponse(),
                'Missing bearer token.'
            )->withHeader('X-Auth-Error', 'missing_token');
        }

        $claims = $this->validator->validate($token);

        if ($claims === null) {
            return JsonHandler::unauthorized(
                $this->responses->createResponse(),
                'Invalid or expired token.'
            )->withHeader('X-Auth-Error', 'invalid_or_expired');
        }

        return $handler->handle($request->withAttribute($this->attribute, $claims));
    }

    /**
     * Extract bearer token from Authorization header or optional query param.
     *
     * @param ServerRequestInterface $request
     * @return string|null
     */
    private function extractToken(ServerRequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '') {
            if (\preg_match('/^\s*Bearer\s+(.+)\s*$/i', $auth, $m)) {
                return \trim($m[1]);
            }
            return null;
        }

        if ($this->allowQueryParam) {
            $q = $request->getQueryParams();
            if (!empty($q['access_token']) && \is_string($q['access_token'])) {
                return $q['access_token'];
            }
        }

        return null;
    }
}
