<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Middleware;

use Closure;
use ElSchneider\ContentMcp\Connections;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On Passport's token route: holds the client's lock through the whole exchange,
 * validation included. league/oauth2-server checks a refresh token or code for
 * revocation before it issues new tokens and not after, so without the lock a
 * Disconnect between the two would leave the new tokens working.
 */
final class LockTokenExchange
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Where league/oauth2-server reads it: the body, else HTTP Basic.
        $clientId = $request->post('client_id', $request->getUser());

        if (! is_string($clientId) || $clientId === '') {
            return $next($request);
        }

        return Connections::lock($clientId)->block(Connections::LOCK_WAIT, fn (): Response => $next($request));
    }
}
