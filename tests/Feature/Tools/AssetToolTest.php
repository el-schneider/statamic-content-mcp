<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\AssetTool;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

beforeEach(function () {
    config(['filesystems.disks.assets' => [
        'driver' => 'local',
        'root' => storage_path('app/assets'),
        'url' => '/assets',
    ]]);

    $container = AssetContainer::make('assets')->title('Assets')->disk('assets');
    $container->save();
});

it('lists asset containers', function () {
    ContentMcpServer::tool(AssetTool::class, [
        'action' => 'list_containers',
    ])
        ->assertOk()
        ->assertSee('assets')
        ->assertSee('Assets');
});

it('lists assets in a container', function () {
    ContentMcpServer::tool(AssetTool::class, [
        'action' => 'list',
        'container' => 'assets',
    ])
        ->assertOk()
        ->assertSee('total');
});

it('returns error for unknown container on list', function () {
    ContentMcpServer::tool(AssetTool::class, [
        'action' => 'list',
        'container' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('gets an asset by id', function () {
    Storage::disk('assets')->put('images/photo.jpg', 'fake-image-content');

    $asset = Asset::make()->container('assets')->path('images/photo.jpg');
    $asset->save();

    ContentMcpServer::tool(AssetTool::class, [
        'action' => 'get',
        'id' => 'assets::images/photo.jpg',
    ])
        ->assertOk()
        ->assertSee('images/photo.jpg')
        ->assertSee('jpg');
});

it('returns error for unknown asset on get', function () {
    ContentMcpServer::tool(AssetTool::class, [
        'action' => 'get',
        'id' => 'assets::nonexistent.jpg',
    ])
        ->assertHasErrors();
});

it('rejects unknown actions', function () {
    ContentMcpServer::tool(AssetTool::class, [
        'action' => 'invalid',
    ])
        ->assertHasErrors();
});

it('is registered when assets tool config is enabled', function () {
    config(['statamic.content-mcp.tools.assets' => true]);

    expect(app(AssetTool::class)->eligibleForRegistration())->toBeTrue();
});

it('is not registered when assets tool config is disabled', function () {
    config(['statamic.content-mcp.tools.assets' => false]);

    expect(app(AssetTool::class)->eligibleForRegistration())->toBeFalse();
});
