<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Http\Controllers;

use ElSchneider\ContentMcp\ContentMcpServiceProvider;
use ElSchneider\ContentMcp\Settings;
use Inertia\Inertia;
use Inertia\Response;
use Statamic\Http\Controllers\CP\CpController;

/**
 * Where editors find the URL to add to their AI assistant, and their own connections.
 */
final class ConnectController extends CpController
{
    public function __invoke(): Response
    {
        $this->authorize(ContentMcpServiceProvider::PERMISSION);

        return Inertia::render('content-mcp::Connect', [
            'endpoint' => url(config()->string('statamic.content-mcp.route')),
            'deletes' => Settings::deletes(),
            'connections' => ConnectionsController::present(Area::currentUserId()),
            'tabs' => Area::tabs('connect'),
        ]);
    }
}
