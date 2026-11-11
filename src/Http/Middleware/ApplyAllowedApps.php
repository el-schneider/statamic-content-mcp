<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Middleware;

use Closure;
use ElSchneider\ContentMcp\Settings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * laravel/mcp's client registration reads `mcp.redirect_domains`, whose own default
 * lets any app register. Set per request, because the effective value can come from
 * the Control Panel settings, which config:cache knows nothing of.
 */
final class ApplyAllowedApps
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        config(['mcp.redirect_domains' => Settings::redirectDomains()]);

        return $next($request);
    }
}
