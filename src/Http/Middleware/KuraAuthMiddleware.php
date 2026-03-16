<?php

namespace Kura\Http\Middleware;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates warm endpoint requests via Bearer token.
 *
 * Token is configured in kura.warm.token.
 * If no token is configured, all requests are denied.
 */
class KuraAuthMiddleware
{
    /**
     * @param  \Closure(Request): Response  $next
     */
    public function handle(Request $request, \Closure $next): Response
    {
        /** @var string $configuredToken */
        $configuredToken = config('kura.warm.token', '');

        if ($configuredToken === '') {
            return new \Illuminate\Http\JsonResponse(
                ['message' => 'Warm endpoint is not configured. Set kura.warm.token.'],
                403,
            );
        }

        $bearerToken = $request->bearerToken();

        if ($bearerToken === null || ! hash_equals($configuredToken, $bearerToken)) {
            return new \Illuminate\Http\JsonResponse(
                ['message' => 'Unauthorized.'],
                401,
            );
        }

        return $next($request);
    }
}
