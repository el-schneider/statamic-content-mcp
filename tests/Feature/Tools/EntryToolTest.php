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

// --- Create tests ---

it('creates an entry with scalar fields', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'data' => [
            'title' => 'New Blog Post',
            'meta_title' => 'New Blog Post SEO',
        ],
    ])
        ->assertOk()
        ->assertSee('New Blog Post');
});

it('creates an entry with bard markdown content', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'data' => [
            'title' => 'Markdown Post',
            'content' => "# Hello\n\nThis is **bold** text.",
        ],
    ])
        ->assertOk()
        ->assertSee('Markdown Post');

    $entry = Collection::findByHandle('blog')->queryEntries()->where('slug', 'markdown-post')->first();
    expect($entry)->not->toBeNull();

    $content = $entry->get('content');
    expect($content)->toBeArray()
        ->and($content[0]['type'])->toBe('text')
        ->and($content[0]['text'])->toContain('<h1>Hello</h1>')
        ->and($content[0]['text'])->toContain('<strong>bold</strong>');
});

it('creates entries as drafts by default', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'data' => ['title' => 'Draft Entry'],
    ])
        ->assertOk()
        ->assertSee('draft');
});

it('creates published entries when requested', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'published' => true,
        'data' => ['title' => 'Published Entry'],
    ])
        ->assertOk()
        ->assertSee('published');
});

it('generates slug from title on create', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'data' => ['title' => 'My Great Blog Post'],
    ])
        ->assertOk()
        ->assertSee('my-great-blog-post');
});

it('uses explicit slug on create', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'slug' => 'custom-slug',
        'data' => ['title' => 'Custom Slug Post'],
    ])
        ->assertOk()
        ->assertSee('custom-slug');
});

it('sets date for dated collections on create', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'date' => '2026-04-15',
        'data' => ['title' => 'Dated Post'],
    ])
        ->assertOk()
        ->assertSee('2026-04-15');
});

it('returns error for restricted collection on create', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'data' => ['title' => 'Blocked'],
    ])
        ->assertHasErrors();
});

it('returns error for unknown collection on create', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'nonexistent',
        'data' => ['title' => 'Blocked'],
    ])
        ->assertHasErrors();
});

it('returns validation error for missing required field', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'data' => ['meta_title' => 'No title provided'],
    ])
        ->assertHasErrors();
});

// --- Update tests ---

it('updates an entry with partial data', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'update',
        'id' => 'entry-published',
        'data' => ['title' => 'Updated Title'],
    ])
        ->assertOk()
        ->assertSee('Updated Title');

    $entry = Entry::find('entry-published');
    expect($entry->get('title'))->toBe('Updated Title')
        ->and($entry->get('tags'))->toBe(['tutorials']);
});

it('updates bard content with markdown', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'update',
        'id' => 'entry-published',
        'data' => ['content' => '# Updated heading'],
    ])
        ->assertOk();

    $entry = Entry::find('entry-published');
    $content = $entry->get('content');
    expect($content[0]['text'])->toContain('<h1>Updated heading</h1>');
});

it('updates slug when provided', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'update',
        'id' => 'entry-published',
        'slug' => 'new-slug',
        'data' => ['title' => 'Published Post'],
    ])
        ->assertOk()
        ->assertSee('new-slug');
});

it('updates date for dated collections', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'update',
        'id' => 'entry-published',
        'date' => '2026-06-01',
        'data' => ['title' => 'Published Post'],
    ])
        ->assertOk()
        ->assertSee('2026-06-01');
});

it('returns error for nonexistent entry on update', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'update',
        'id' => 'nonexistent',
        'data' => ['title' => 'Nope'],
    ])
        ->assertHasErrors();
});

it('returns error for restricted collection on update', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'update',
        'id' => 'entry-published',
        'data' => ['title' => 'Blocked'],
    ])
        ->assertHasErrors();
});

it('returns validation error on update', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'update',
        'id' => 'entry-published',
        'data' => ['title' => ''],
    ])
        ->assertHasErrors();
});

// --- Delete tests ---

it('deletes an entry', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'delete',
        'id' => 'entry-draft',
    ])
        ->assertOk()
        ->assertSee('deleted')
        ->assertSee('Draft Post');

    expect(Entry::find('entry-draft'))->toBeNull();
});

it('returns error for nonexistent entry on delete', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'delete',
        'id' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('returns error for restricted collection on delete', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'delete',
        'id' => 'entry-published',
    ])
        ->assertHasErrors();
});

// --- Publish/Unpublish tests ---

it('publishes a draft entry', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'publish',
        'id' => 'entry-draft',
    ])
        ->assertOk()
        ->assertSee('published');

    expect(Entry::find('entry-draft')->published())->toBeTrue();
});

it('unpublishes a published entry', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'unpublish',
        'id' => 'entry-published',
    ])
        ->assertOk()
        ->assertSee('draft');

    expect(Entry::find('entry-published')->published())->toBeFalse();
});

it('sets date when publishing', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'publish',
        'id' => 'entry-draft',
        'date' => '2026-12-25',
    ])
        ->assertOk()
        ->assertSee('2026-12-25');
});

it('returns scheduled status for future-dated published entry', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'publish',
        'id' => 'entry-draft',
        'date' => '2099-01-01',
    ])
        ->assertOk()
        ->assertSee('scheduled');
});

it('returns error for nonexistent entry on publish', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'publish',
        'id' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('returns error for restricted collection on publish', function () {
    config(['statamic.content-mcp.collections' => ['pages']]);

    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'publish',
        'id' => 'entry-draft',
    ])
        ->assertHasErrors();
});

// --- Structured content tests (task 3.3) ---

it('creates an entry with bard blocks including sets', function () {
    ContentMcpServer::tool(EntryTool::class, [
        'action' => 'create',
        'collection' => 'blog',
        'data' => [
            'title' => 'Bard Sets Post',
            'content' => [
                ['type' => 'text', 'text' => 'Before the image'],
                ['type' => 'image', 'asset' => ['photo.jpg'], 'caption' => 'A photo'],
                ['type' => 'text', 'text' => 'After the image'],
            ],
        ],
    ])
        ->assertOk()
        ->assertSee('Bard Sets Post');

    $entry = Collection::findByHandle('blog')->queryEntries()->where('slug', 'bard-sets-post')->first();
    $content = $entry->get('content');
    expect($content)->toHaveCount(3)
        ->and($content[0]['type'])->toBe('text')
        ->and($content[1]['type'])->toBe('set')
        ->and($content[1]['attrs']['values']['type'])->toBe('image');
});

it('is registered when entries tool config is enabled', function () {
    config(['statamic.content-mcp.tools.entries' => true]);

    expect(app(EntryTool::class)->eligibleForRegistration())->toBeTrue();
});

it('is not registered when entries tool config is disabled', function () {
    config(['statamic.content-mcp.tools.entries' => false]);

    expect(app(EntryTool::class)->eligibleForRegistration())->toBeFalse();
});
