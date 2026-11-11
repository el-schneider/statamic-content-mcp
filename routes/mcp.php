<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Http\Middleware\AuthenticateBearerToken;
use ElSchneider\ContentMcp\Server\ContentMcpServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

// Throttled after authentication so the limit applies per editor, not per Claude egress IP.
Mcp::web(config()->string('statamic.content-mcp.route'), ContentMcpServer::class)
    ->middleware([AuthenticateBearerToken::class, 'throttle:120,1']);

// Discovery documents and dynamic client registration. Registration writes a row
// per call and laravel/mcp does not throttle it.
Route::middleware('throttle:60,1')->group(fn () => Mcp::oauthRoutes());
