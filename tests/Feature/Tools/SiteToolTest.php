<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\SiteTool;

it('returns site overview', function () {
    ContentMcpServer::tool(SiteTool::class, ['action' => 'overview'])
        ->assertOk();
});

it('returns collections action', function () {
    ContentMcpServer::tool(SiteTool::class, ['action' => 'collections'])
        ->assertOk();
});

it('defaults to overview when no action given', function () {
    ContentMcpServer::tool(SiteTool::class)
        ->assertOk()
        ->assertSee('collections');
});

it('rejects unknown actions', function () {
    ContentMcpServer::tool(SiteTool::class, ['action' => 'invalid'])
        ->assertHasErrors();
});

it('is registered when site tool config is enabled', function () {
    config(['statamic.content-mcp.tools.site' => true]);

    $tool = app(SiteTool::class);

    expect($tool->eligibleForRegistration())->toBeTrue();
});

it('is not registered when site tool config is disabled', function () {
    config(['statamic.content-mcp.tools.site' => false]);

    $tool = app(SiteTool::class);

    expect($tool->eligibleForRegistration())->toBeFalse();
});
