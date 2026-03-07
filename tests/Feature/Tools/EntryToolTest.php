<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\EntryTool;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

beforeEach(function () {
    Collection::make('blog')->title('Blog')->dated(true)->routes('/blog/{slug}')->save();

    Taxonomy::make('tags')->title('Tags')->save();

    Term::make()->taxonomy('tags')->slug('tutorials')->data(['title' => 'Tutorials'])->save();
    Term::make()->taxonomy('tags')->slug('news')->data(['title' => 'News'])->save();

    $published = Entry::make()
        ->collection('blog')
        ->slug('published-post')
        ->id('entry-published')
        ->data([
            'title' => 'Published Post',
            'content' => [
                ['type' => 'text', 'text' => '<p>Hello world</p>'],
            ],
            'tags' => ['tutorials'],
        ])
        ->published(true)
        ->date('2026-03-01');
    $published->save();

    $draft = Entry::make()
        ->collection('blog')
        ->slug('draft-post')
        ->id('entry-draft')
        ->data([
            'title' => 'Draft Post',
            'content' => [
                ['type' => 'text', 'text' => '<p>Draft content</p>'],
            ],
        ])
        ->published(false)
        ->date('2026-03-02');
    $draft->save();
});

it('lists entries from a collection', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'list',
        'collection' => 'blog',
    ])
        ->assertOk()
        ->assertSee('Published Post')
        ->assertSee('Draft Post');
});

it('lists entries with status filter', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'list',
        'collection' => 'blog',
        'status' => 'published',
    ])
        ->assertOk()
        ->assertSee('Published Post')
        ->assertDontSee('Draft Post');
});

it('lists entries with draft filter', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'list',
        'collection' => 'blog',
        'status' => 'draft',
    ])
        ->assertOk()
        ->assertSee('Draft Post')
        ->assertDontSee('Published Post');
});

it('paginates entry list', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'list',
        'collection' => 'blog',
        'limit' => 1,
    ])
        ->assertOk()
        ->assertSee('has_more');
});

it('paginates with offset', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'list',
        'collection' => 'blog',
        'limit' => 1,
        'offset' => 1,
    ])
        ->assertOk()
        ->assertSee('total');
});

it('returns error for unknown collection on list', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'list',
        'collection' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('returns error for restricted collection on list', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'list',
        'collection' => 'blog',
    ])
        ->assertHasErrors();
});

it('gets an entry by id', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'id' => 'entry-published',
    ])
        ->assertOk()
        ->assertSee('Published Post')
        ->assertSee('entry-published');
});

it('gets an entry by slug and collection', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'collection' => 'blog',
        'slug' => 'published-post',
    ])
        ->assertOk()
        ->assertSee('Published Post');
});

it('returns formatted bard content as markdown on get', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'id' => 'entry-published',
    ])
        ->assertOk()
        ->assertSee('Hello world');
});

it('returns formatted terms on get', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'id' => 'entry-published',
    ])
        ->assertOk()
        ->assertSee('tutorials')
        ->assertSee('Tutorials');
});

it('returns entry status', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'id' => 'entry-draft',
    ])
        ->assertOk()
        ->assertSee('draft');
});

it('returns error when entry not found', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'id' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('returns error when no id or slug given for get', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
    ])
        ->assertHasErrors();
});

it('returns error when slug given without collection', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'slug' => 'published-post',
    ])
        ->assertHasErrors();
});

it('returns error for restricted collection on get', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'get',
        'id' => 'entry-published',
    ])
        ->assertHasErrors();
});

it('rejects unknown actions', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'invalid',
    ])
        ->assertHasErrors();
});

it('requires action parameter', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'collection' => 'blog',
    ])
        ->assertHasErrors();
});

it('is registered when entries tool config is enabled', function () {
    config(['statamic.content-mcp.tools.entries' => true]);

    expect(app(EntryTool::class)->eligibleForRegistration())->toBeTrue();
});

it('is not registered when entries tool config is disabled', function () {
    config(['statamic.content-mcp.tools.entries' => false]);

    expect(app(EntryTool::class)->eligibleForRegistration())->toBeFalse();
});
