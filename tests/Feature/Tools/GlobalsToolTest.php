<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\GlobalsTool;
use Statamic\Facades\GlobalSet;

beforeEach(function () {
    $set = GlobalSet::make('social')->title('Social Links');
    $set->save();

    $variables = $set->makeLocalization('default');
    $variables->data([
        'twitter_url' => 'https://twitter.com/example',
        'facebook_url' => 'https://facebook.com/example',
    ]);
    $variables->save();
});

it('lists global sets', function () {
    ContentMcpServer::tool(GlobalsTool::class, [
        'action' => 'list',
    ])
        ->assertOk()
        ->assertSee('social')
        ->assertSee('Social Links');
});

it('gets a global set with values', function () {
    ContentMcpServer::tool(GlobalsTool::class, [
        'action' => 'get',
        'handle' => 'social',
    ])
        ->assertOk()
        ->assertSee('social')
        ->assertSee('Social Links')
        ->assertSee('https://twitter.com/example')
        ->assertSee('https://facebook.com/example')
        ->assertSee('/cp/globals/social');
});

it('updates a global set', function () {
    ContentMcpServer::tool(GlobalsTool::class, [
        'action' => 'update',
        'handle' => 'social',
        'data' => ['twitter_url' => 'https://twitter.com/updated'],
    ])
        ->assertOk()
        ->assertSee('https://twitter.com/updated');

    $set = GlobalSet::findByHandle('social');
    $variables = $set->inDefaultSite();
    expect($variables->get('twitter_url'))->toBe('https://twitter.com/updated')
        ->and($variables->get('facebook_url'))->toBe('https://facebook.com/example');
});

it('returns error for unknown global set on get', function () {
    ContentMcpServer::tool(GlobalsTool::class, [
        'action' => 'get',
        'handle' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('returns error for unknown global set on update', function () {
    ContentMcpServer::tool(GlobalsTool::class, [
        'action' => 'update',
        'handle' => 'nonexistent',
        'data' => ['twitter_url' => 'https://twitter.com/test'],
    ])
        ->assertHasErrors();
});

it('returns validation error on update', function () {
    ContentMcpServer::tool(GlobalsTool::class, [
        'action' => 'update',
        'handle' => 'social',
        'data' => ['twitter_url' => ''],
    ])
        ->assertHasErrors();
});

it('is registered when globals tool config is enabled', function () {
    config(['statamic.content-mcp.tools.globals' => true]);

    expect(app(GlobalsTool::class)->eligibleForRegistration())->toBeTrue();
});

it('is not registered when globals tool config is disabled', function () {
    config(['statamic.content-mcp.tools.globals' => false]);

    expect(app(GlobalsTool::class)->eligibleForRegistration())->toBeFalse();
});

it('rejects unknown actions', function () {
    ContentMcpServer::tool(GlobalsTool::class, [
        'action' => 'invalid',
    ])
        ->assertHasErrors();
});
