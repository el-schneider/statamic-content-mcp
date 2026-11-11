<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\FindAssets;
use ElSchneider\ContentMcp\Tools\FindEntries;
use ElSchneider\ContentMcp\Tools\GetBlueprint;
use ElSchneider\ContentMcp\Tools\GetEntry;
use ElSchneider\ContentMcp\Tools\GetGlobal;
use ElSchneider\ContentMcp\Tools\GetOverview;
use ElSchneider\ContentMcp\Tools\ListTerms;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Statamic\Entries\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Term;
use Tests\Fixtures\HideSecretEntriesPolicy;

beforeEach(function () {
    siteContent();
});

it('gives an overview of only what the editor may access, with the site guidelines', function () {
    config(['statamic.content-mcp.instructions' => 'Write in plain English.']);
    $editor = editor(['access content mcp', 'view blog entries', 'create blog entries', 'view tags terms', 'edit footer globals']);

    $data = tool($editor, GetOverview::class)->assertHasNoErrors()->data();

    expect($data['user']['email'])->toBe('editor@example.com')
        ->and($data['collections'])->toBe([[
            'handle' => 'blog',
            'title' => 'Blog',
            'blueprints' => ['post'],
            'dated' => true,
            'structured' => false,
            'can_create' => true,
        ]])
        ->and(collect($data['taxonomies'])->pluck('handle')->all())->toBe(['tags'])
        ->and(collect($data['globals'])->pluck('handle')->all())->toBe(['footer'])
        ->and($data['asset_containers'])->toBe([])
        ->and($data['guidelines'])->toBe('Write in plain English.');
});

it('describes a blueprint with agent formats, sets, hints and read-only fields', function () {
    $fields = collect(tool(editor(['access content mcp', 'view blog entries']), GetBlueprint::class, ['collection' => 'blog'])
        ->assertHasNoErrors()->data()['fields'])->keyBy('handle');

    expect($fields['intro'])->toMatchArray(['type' => 'bard', 'format' => 'html', 'instructions' => 'One short paragraph.'])
        ->and($fields['content']['format'])->toBe('blocks')
        ->and(collect($fields['content']['sets']['quote']['fields'])->pluck('handle')->all())->toBe(['quote', 'cite'])
        ->and($fields['content']['sets']['quote']['fields'][0]['required'])->toBeTrue()
        ->and($fields['tags'])->toMatchArray(['taxonomies' => ['tags'], 'value' => 'List of term slugs.'])
        ->and($fields['rating']['validate'])->toBe(['min:1', 'max:5'])
        ->and($fields['legacy_id']['read_only'])->toBeTrue()
        ->and($fields['legacy_id'])->not->toHaveKey('visibility')
        ->and($fields)->toHaveKeys(['slug', 'date']);
});

it('describes replicator, group and global blueprints recursively', function () {
    $editor = editor(['access content mcp', 'view pages entries', 'edit footer globals']);

    $page = collect(tool($editor, GetBlueprint::class, ['collection' => 'pages'])->data()['fields'])->keyBy('handle');
    $footer = collect(tool($editor, GetBlueprint::class, ['global' => 'footer'])->data()['fields'])->keyBy('handle');

    expect(array_keys($page['page_builder']['sets']))->toBe(['hero', 'rich_text'])
        ->and($page['page_builder']['sets']['rich_text']['fields'][0])->toMatchArray(['handle' => 'text', 'format' => 'html'])
        ->and($page['page_builder']['sets']['hero']['fields'][1])->toMatchArray(['container' => 'assets', 'max_files' => 1, 'value' => 'One asset path, relative to the container.'])
        ->and($page['settings']['fields'][0]['options'])->toBe(['light' => 'Light', 'dark' => 'Dark'])
        ->and(collect($footer['links']['fields'])->pluck('handle')->all())->toBe(['label', 'url']);
});

it('reports a collection the editor may not view exactly like a missing one', function () {
    $editor = editor(['access content mcp', 'view pages entries']);

    $hidden = tool($editor, GetBlueprint::class, ['collection' => 'blog'])->errorText();
    $missing = tool($editor, GetBlueprint::class, ['collection' => 'nope'])->errorText();

    expect($hidden)->toBe('Collection [blog] not found.')
        ->and($missing)->toBe('Collection [nope] not found.');
});

it('lists a dated collection newest first with summaries and a total', function () {
    $old = entry('blog', ['title' => 'Old']);
    $new = entry('blog', ['title' => 'New'])->date('2025-03-01');
    $new->save();

    $data = tool(editor(['access content mcp', 'view blog entries']), FindEntries::class, ['collection' => 'blog'])->data();

    expect($data['total'])->toBe(2)
        ->and(collect($data['entries'])->pluck('id')->all())->toBe([$new->id(), $old->id()])
        ->and($data['entries'][0])->toMatchArray([
            'collection' => 'blog',
            'title' => 'New',
            'slug' => 'new',
            'status' => 'published',
            'url' => 'http://localhost/blog/new',
        ]);
});

it('searches title, slug and content text across viewable collections and returns a snippet', function () {
    entry('blog', ['title' => 'Gardening', 'intro' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Tomatoes need full sun and plenty of water every day.']]]]]);
    entry('blog', ['title' => 'Sunflowers']);
    entry('blog', ['title' => 'Unrelated']);
    entry('pages', ['title' => 'Sun deck']);

    $data = tool(editor(['access content mcp', 'view blog entries']), FindEntries::class, ['query' => 'SUN'])->data();

    expect(collect($data['entries'])->pluck('title')->sort()->values()->all())->toBe(['Gardening', 'Sunflowers'])
        ->and(collect($data['entries'])->firstWhere('title', 'Gardening')['snippet'])->toBe('Tomatoes need full sun and plenty of water every day.');
});

it('filters by status and pages with limit and offset', function () {
    entry('blog', ['title' => 'Draft one'], published: false);
    entry('blog', ['title' => 'Draft two'], published: false);
    entry('blog', ['title' => 'Live']);
    $editor = editor(['access content mcp', 'view blog entries']);

    $drafts = tool($editor, FindEntries::class, ['collection' => 'blog', 'status' => 'draft', 'limit' => 1, 'offset' => 1])->data();

    expect($drafts['total'])->toBe(2)
        ->and($drafts['entries'])->toHaveCount(1)
        ->and($drafts['entries'][0]['status'])->toBe('draft');
});

it('hides entries a site policy denies, in lists and by id', function () {
    // The role alone would allow viewing the secret entry; only the Gate knows otherwise.
    Gate::policy(Entry::class, HideSecretEntriesPolicy::class);
    $secret = entry('blog', ['title' => 'Secret'], slug: 'secret');
    entry('blog', ['title' => 'Public']);
    $editor = editor(['access content mcp', 'view blog entries']);

    $listed = tool($editor, FindEntries::class, ['collection' => 'blog'])->data();
    $searched = tool($editor, FindEntries::class, ['query' => 'secret'])->data();

    expect(collect($listed['entries'])->pluck('title')->all())->toBe(['Public'])
        ->and($searched['total'])->toBe(0);
    tool($editor, GetEntry::class, ['id' => $secret->id()])->assertHasErrors(["Entry [{$secret->id()}] not found."]);
});

it('returns an entry with meta and its data in agent form', function () {
    $entry = entry('blog', [
        'title' => 'Hello',
        'intro' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hi']]]],
        'rating' => 4,
    ], published: false);

    $data = tool(editor(['access content mcp', 'view blog entries']), GetEntry::class, ['id' => $entry->id()])->assertHasNoErrors()->data();

    expect($data)->toMatchArray([
        'id' => $entry->id(),
        'collection' => 'blog',
        'blueprint' => 'post',
        'slug' => 'hello',
        'published' => false,
        'status' => 'draft',
        'edit_url' => $entry->editUrl(),
    ])
        ->and($data['preview_url'])->toStartWith('http://localhost/blog/hello?live-preview=')
        ->and($data['data'])->toBe(['title' => 'Hello', 'intro' => '<p>Hi</p>', 'rating' => 4]);
});

it('returns global values only to editors of that global set', function () {
    GlobalSet::findByHandle('footer')->inDefaultSite()->data(['copyright' => '© Example'])->save();

    tool(editor(['access content mcp', 'edit footer globals']), GetGlobal::class, ['handle' => 'footer'])
        ->assertStructuredContent(['handle' => 'footer', 'title' => 'Footer', 'data' => ['copyright' => '© Example']]);

    tool(editor(['access content mcp'], 'other@example.com'), GetGlobal::class, ['handle' => 'footer'])
        ->assertHasErrors(['Global set [footer] not found.']);
});

it('finds assets by filename or alt text in viewable containers', function () {
    Storage::fake('assets');
    AssetContainer::make('assets')->title('Assets')->disk('assets')->save();
    AssetContainer::make('private')->title('Private')->disk('assets')->save();
    Storage::disk('assets')->putFileAs('images', UploadedFile::fake()->image('hero.jpg', 640, 480), 'hero.jpg');
    Storage::disk('assets')->putFileAs('images', UploadedFile::fake()->image('team.jpg', 10, 10), 'team.jpg');
    Asset::find('assets::images/team.jpg')->set('alt', 'Our hero team')->save();

    $data = tool(editor(['access content mcp', 'view assets assets']), FindAssets::class, ['query' => 'hero'])->data();

    expect($data['total'])->toBe(2)
        ->and($data['assets'][0])->toMatchArray([
            'id' => 'assets::images/hero.jpg',
            'container' => 'assets',
            'path' => 'images/hero.jpg',
            'mime_type' => 'image/jpeg',
            'width' => 640,
            'height' => 480,
        ])
        ->and($data['assets'][1]['alt'])->toBe('Our hero team');

    tool(editor(['access content mcp', 'view assets assets'], 'other@example.com'), FindAssets::class, ['container' => 'private'])
        ->assertHasErrors(['Asset container [private] not found.']);
});

it('lists the terms of a viewable taxonomy', function () {
    Term::make('garden')->taxonomy('tags')->data(['title' => 'Garden'])->save();
    Term::make('apple')->taxonomy('tags')->data(['title' => 'Apple'])->save();

    tool(editor(['access content mcp', 'view tags terms']), ListTerms::class, ['taxonomy' => 'tags'])
        ->assertStructuredContent(['terms' => [['slug' => 'apple', 'title' => 'Apple'], ['slug' => 'garden', 'title' => 'Garden']]]);

    tool(editor(['access content mcp'], 'other@example.com'), ListTerms::class, ['taxonomy' => 'tags'])
        ->assertHasErrors(['Taxonomy [tags] not found.']);
});

it('only lists the collections a user may view', function () {
    Collection::make('secret_stuff')->save();

    $collections = tool(editor(['access content mcp', 'view pages entries']), GetOverview::class)->data()['collections'];

    expect(collect($collections)->pluck('handle')->all())->toBe(['pages']);
});
