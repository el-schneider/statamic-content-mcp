<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\BlueprintTool;

it('describes a collection blueprint', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('blog')
        ->assertSee('title');
});

it('includes dated flag for dated collections', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('dated');
});

it('describes a taxonomy blueprint', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['taxonomy' => 'tags'])
        ->assertOk()
        ->assertSee('tags')
        ->assertSee('title');
});

it('describes a global set blueprint', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['global' => 'footer'])
        ->assertOk()
        ->assertSee('footer')
        ->assertSee('copyright_text');
});

it('describes an asset container blueprint', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['asset_container' => 'assets'])
        ->assertOk()
        ->assertSee('assets')
        ->assertSee('alt');
});

it('describes bard sets recursively', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['collection' => 'blog'])
        ->assertOk()
        ->assertSee('image')
        ->assertSee('caption');
});

it('describes replicator sets', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['collection' => 'pages'])
        ->assertOk()
        ->assertSee('hero')
        ->assertSee('heading');
});

it('returns error for unknown collection', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['collection' => 'nonexistent'])
        ->assertHasErrors();
});

it('returns error for unknown taxonomy', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['taxonomy' => 'nonexistent'])
        ->assertHasErrors();
});

it('returns error for unknown global set', function () {
    ContentMcpServer::tool(BlueprintTool::class, ['global' => 'nonexistent'])
        ->assertHasErrors();
});

it('returns error when no target is given', function () {
    ContentMcpServer::tool(BlueprintTool::class)
        ->assertHasErrors();
});

it('is registered when blueprints tool config is enabled', function () {
    config(['statamic.content-mcp.tools.blueprints' => true]);

    expect(app(BlueprintTool::class)->eligibleForRegistration())->toBeTrue();
});

it('is not registered when blueprints tool config is disabled', function () {
    config(['statamic.content-mcp.tools.blueprints' => false]);

    expect(app(BlueprintTool::class)->eligibleForRegistration())->toBeFalse();
});

it('returns error for restricted collection', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(BlueprintTool::class, ['collection' => 'blog'])
        ->assertHasErrors();
});
