<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\SearchTool;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;

beforeEach(function () {
    Collection::make('blog')->title('Blog')->dated(true)->save();
    Collection::make('pages')->title('Pages')->save();

    Entry::make()
        ->collection('blog')
        ->slug('hello-world')
        ->id('entry-hello')
        ->data(['title' => 'Hello World'])
        ->published(true)
        ->date('2026-03-01')
        ->save();

    Entry::make()
        ->collection('blog')
        ->slug('goodbye-world')
        ->id('entry-goodbye')
        ->data(['title' => 'Goodbye World'])
        ->published(false)
        ->date('2026-03-02')
        ->save();

    Entry::make()
        ->collection('pages')
        ->slug('about')
        ->id('entry-about')
        ->data(['title' => 'About Us'])
        ->published(true)
        ->save();
});

it('searches entries by title', function () {
    ContentMcpServer::tool(SearchTool::class, [
        'query' => 'World',
    ])
        ->assertOk()
        ->assertSee('Hello World')
        ->assertSee('Goodbye World')
        ->assertDontSee('About Us');
});

it('searches within a specific collection', function () {
    ContentMcpServer::tool(SearchTool::class, [
        'query' => 'World',
        'collection' => 'blog',
    ])
        ->assertOk()
        ->assertSee('Hello World');
});

it('filters search results by status', function () {
    ContentMcpServer::tool(SearchTool::class, [
        'query' => 'World',
        'status' => 'published',
    ])
        ->assertOk()
        ->assertSee('Hello World')
        ->assertDontSee('Goodbye World');
});

it('limits search results', function () {
    ContentMcpServer::tool(SearchTool::class, [
        'query' => 'World',
        'limit' => 1,
    ])
        ->assertOk()
        ->assertSee('total');
});

it('respects collection restrictions', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(SearchTool::class, [
        'query' => 'World',
    ])
        ->assertOk()
        ->assertDontSee('Hello World');
});

it('returns error for restricted collection', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(SearchTool::class, [
        'query' => 'test',
        'collection' => 'blog',
    ])
        ->assertHasErrors();
});

it('returns error for unknown collection', function () {
    ContentMcpServer::tool(SearchTool::class, [
        'query' => 'test',
        'collection' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('returns error when query is missing', function () {
    ContentMcpServer::tool(SearchTool::class, [])
        ->assertHasErrors();
});

it('is registered when search tool config is enabled', function () {
    config(['statamic.content-mcp.tools.search' => true]);

    expect(app(SearchTool::class)->eligibleForRegistration())->toBeTrue();
});

it('is not registered when search tool config is disabled', function () {
    config(['statamic.content-mcp.tools.search' => false]);

    expect(app(SearchTool::class)->eligibleForRegistration())->toBeFalse();
});
