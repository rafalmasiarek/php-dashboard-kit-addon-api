<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi\ResponseDriver;

use Psr\Http\Message\ResponseInterface;
use rafalmasiarek\DashboardKitApi\ResponseDriverInterface;

/**
 * Response driver that issues an HTTP redirect.
 *
 * Handler must return ['redirect' => $url] in its result array.
 * Optional 'http' key overrides the status code (default: 302).
 */
class RedirectResponseDriver implements ResponseDriverInterface
{
    /**
     * Build a redirect response from the handler result.
     *
     * @param  ResponseInterface    $res
     * @param  array<string, mixed> $result Handler result; expects 'redirect' key with target URL.
     * @return ResponseInterface
     */
    public function handle(ResponseInterface $res, array $result): ResponseInterface
    {
        return $res
            ->withHeader('Location', (string) $result['redirect'])
            ->withStatus((int) ($result['http'] ?? 302));
    }
}
