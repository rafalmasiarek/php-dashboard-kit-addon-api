<?php

declare(strict_types=1);

namespace rafalmasiarek\DashboardKitApi;

use Psr\Http\Message\ResponseInterface;

/**
 * Contract for custom API response drivers.
 *
 * A response driver overrides the default JSON response building for a specific route.
 * Register an instance in the route definition under the 'driver' key.
 */
interface ResponseDriverInterface
{
    /**
     * Build a PSR-7 response from the handler result array.
     *
     * @param  ResponseInterface    $res    Blank PSR-7 response to mutate.
     * @param  array<string, mixed> $result Handler return value.
     * @return ResponseInterface
     */
    public function handle(ResponseInterface $res, array $result): ResponseInterface;
}
