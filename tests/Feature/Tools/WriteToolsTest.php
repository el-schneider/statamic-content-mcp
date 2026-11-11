<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\CreateEntry;
use ElSchneider\ContentMcp\Tools\PublishEntry;
use ElSchneider\ContentMcp\Tools\UnpublishEntry;
use ElSchneider\ContentMcp\Tools\UpdateEntry;
use ElSchneider\ContentMcp\Tools\UpdateGlobal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Statamic\CP\LivePreview;
use Statamic\Entries\Entry;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Token;
use Statamic\Facades\User;
use Tests\Fixtures\HideSecretEntriesPolicy;

beforeEach(function () {
    siteContent();
});

function blogWriter(): Statamic\Auth\User
{
    return editor(['access content mcp', 'view blog entries', 'create blog entries', 'edit blog entries', 'publish blog entries']);
}

it('creates a draft even for editors who may publish, and returns preview and edit links', function () {
    Event::fake([EntrySaved::class]);

    $data = tool(blogWriter(), CreateEntry::class, [
        'collection' => 'blog',
        'data' => ['title' => 'Hello World', 'intro' => '<p>First <strong>post</strong></p>', 'tags' => ['news']],
        'date' => '2025-02-03',
    ])->assertHasNoErrors()->data();

    $entry = Entries::find($data['id']);

    expect($entry->published())->toBeFalse()
        ->and($entry->slug())->toBe('hello-world')
        ->and($entry->date()->toDateString())->toBe('2025-02-03')
        ->and($entry->get('intro'))->toEqual([['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [
            ['type' => 'text', 'text' => 'First '],
            ['type' => 'text', 'text' => 'post', 'marks' => [['type' => 'bold']]],
        ]]])
        ->and($entry->get('tags'))->toBe(['news'])
        ->and($entry->lastModifiedBy()?->email())->toBe('editor@example.com')
        ->and($data['status'])->toBe('draft')
        ->and($data['edit_url'])->toBe($entry->editUrl());

    // The preview link carries a Live Preview token for this draft.
    parse_str((string) parse_url($data['preview_url'], PHP_URL_QUERY), $query);
    expect(app(LivePreview::class)->item(Token::find($query['token']))->id())->toBe($entry->id());

    // Static caching and Git automation listen for the normal save events.
    Event::assertDispatched(EntrySaved::class);
});

it('places new entries of a structured collection under the parent or at the top level', function () {
    $editor = editor(['access content mcp', 'view pages entries', 'create pages entries']);
    $parent = entry('pages', ['title' => 'About']);
    $tree = Collection::findByHandle('pages')->structure()->in('default');
    $tree->append($parent)->save();

    $child = tool($editor, CreateEntry::class, ['collection' => 'pages', 'data' => ['title' => 'Team'], 'parent' => $parent->id()])->data();
    $top = tool($editor, CreateEntry::class, ['collection' => 'pages', 'data' => ['title' => 'Contact']])->data();

    $tree = Collection::findByHandle('pages')->structure()->in('default');
    expect($tree->find($child['id'])->parent()->id())->toBe($parent->id())
        ->and(collect($tree->tree())->pluck('entry')->all())->toBe([$parent->id(), $top['id']])
        ->and($child['url'])->toBe('http://localhost/about/team');
});

it('returns validation errors per field so the model can correct itself', function () {
    $response = tool(blogWriter(), CreateEntry::class, ['collection' => 'blog', 'data' => ['rating' => 9]]);

    $error = json_decode($response->errorText(), true);

    expect($error['errors'])->toHaveKeys(['title', 'rating'])
        ->and($error['errors']['rating'][0])->toContain('5')
        ->and(Entries::query()->where('collection', 'blog')->count())->toBe(0);
});

it('rejects unknown and read-only fields instead of storing them', function (array $data, string $field, string $message) {
    $error = json_decode(tool(blogWriter(), CreateEntry::class, ['collection' => 'blog', 'data' => ['title' => 'X', ...$data]])->errorText(), true);

    expect($error['errors'][$field][0])->toContain($message)
        ->and(Entries::query()->where('collection', 'blog')->count())->toBe(0);
})->with([
    'unknown' => [['subtitle' => 'Nope'], 'subtitle', 'Unknown field'],
    'read-only' => [['legacy_id' => '42'], 'legacy_id', 'read-only'],
    'unknown set type' => [['content' => [['type' => 'video', 'url' => 'x']]], 'content', 'Unknown set type [video]'],
]);

it('refuses to create entries without the create permission', function () {
    tool(editor(['access content mcp', 'view blog entries']), CreateEntry::class, ['collection' => 'blog', 'data' => ['title' => 'X']])
        ->assertHasErrors(['You may not create entries in [blog].']);

    expect(Entries::query()->where('collection', 'blog')->count())->toBe(0);
});

it('updates an entry without changing its published state', function (bool $published) {
    $entry = entry('blog', ['title' => 'Before', 'rating' => 2], published: $published);

    $data = tool(blogWriter(), UpdateEntry::class, ['id' => $entry->id(), 'data' => ['title' => 'After'], 'slug' => 'after'])->assertHasNoErrors()->data();

    $fresh = Entries::find($entry->id());
    expect($fresh->get('title'))->toBe('After')
        ->and($fresh->slug())->toBe('after')
        ->and($fresh->get('rating'))->toBe(2)
        ->and($fresh->published())->toBe($published)
        ->and($data['published'])->toBe($published);
})->with(['published' => [true], 'draft' => [false]]);

it('saves a working copy instead of the live entry when revisions are enabled', function () {
    config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => __DIR__.'/../../__fixtures__/dev-null/revisions']);
    Collection::findByHandle('blog')->revisionsEnabled(true)->save();
    $entry = entry('blog', ['title' => 'Live title']);

    $data = tool(blogWriter(), UpdateEntry::class, ['id' => $entry->id(), 'data' => ['title' => 'Proposed title']])->assertHasNoErrors()->data();

    $fresh = Entries::find($entry->id());
    expect($fresh->get('title'))->toBe('Live title')
        ->and($fresh->fromWorkingCopy()->get('title'))->toBe('Proposed title')
        ->and($data['has_unpublished_changes'])->toBeTrue();

    tool(blogWriter(), PublishEntry::class, ['id' => $entry->id()])->assertHasNoErrors();
    expect(Entries::find($entry->id())->get('title'))->toBe('Proposed title');
});

it('refuses edits a site policy denies even though the role allows them', function () {
    Gate::policy(Entry::class, HideSecretEntriesPolicy::class);
    $entry = entry('blog', ['title' => 'Secret'], slug: 'secret');

    tool(blogWriter(), UpdateEntry::class, ['id' => $entry->id(), 'data' => ['title' => 'Leaked']])
        ->assertHasErrors(["Entry [{$entry->id()}] not found."]);

    expect(Entries::find($entry->id())->get('title'))->toBe('Secret');
});

it('refuses edits without the edit permission', function () {
    $entry = entry('blog', ['title' => 'Before']);

    tool(editor(['access content mcp', 'view blog entries']), UpdateEntry::class, ['id' => $entry->id(), 'data' => ['title' => 'After']])
        ->assertHasErrors(['You may not edit this entry.']);

    expect(Entries::find($entry->id())->get('title'))->toBe('Before');
});

it('makes the editor the author and keeps authorship read-only, like the CP, without the other-authors permission', function () {
    Collection::make('news')->save();
    $editor = editor(['access content mcp', 'view news entries', 'create news entries', 'edit news entries']);
    $other = User::make()->email('other@example.com');
    $other->save();

    $created = tool($editor, CreateEntry::class, ['collection' => 'news', 'data' => ['title' => 'Mine']])->assertHasNoErrors()->data();

    expect(Entries::find($created['id'])->get('author'))->toBe($editor->id());

    $error = json_decode(tool($editor, UpdateEntry::class, ['id' => $created['id'], 'data' => ['author' => $other->id()]])->errorText(), true);

    expect($error['errors']['author'][0])->toBe('This field is read-only.')
        ->and(Entries::find($created['id'])->get('author'))->toBe($editor->id());
});

it('publishes and unpublishes only with the publish permission', function () {
    $entry = entry('blog', ['title' => 'Draft'], published: false);
    $editorWithoutPublish = editor(['access content mcp', 'view blog entries', 'edit blog entries'], 'other@example.com');

    tool($editorWithoutPublish, PublishEntry::class, ['id' => $entry->id()])->assertHasErrors(['You may not publish this entry.']);
    expect(Entries::find($entry->id())->published())->toBeFalse();

    tool(blogWriter(), PublishEntry::class, ['id' => $entry->id()])->assertHasNoErrors();
    expect(Entries::find($entry->id())->published())->toBeTrue();

    tool($editorWithoutPublish, UnpublishEntry::class, ['id' => $entry->id()])->assertHasErrors(['You may not unpublish this entry.']);
    tool(blogWriter(), UnpublishEntry::class, ['id' => $entry->id()])->assertHasNoErrors();
    expect(Entries::find($entry->id())->published())->toBeFalse();
});

it('updates globals, patching a grid row by id', function () {
    GlobalSet::findByHandle('footer')->inDefaultSite()->data([
        'copyright' => '© Old',
        'links' => [
            ['id' => 'aaa', 'label' => 'Imprint', 'url' => '/imprint'],
            ['id' => 'bbb', 'label' => 'Privacy', 'url' => '/privacy'],
        ],
    ])->save();

    tool(editor(['access content mcp', 'edit footer globals']), UpdateGlobal::class, [
        'handle' => 'footer',
        'data' => ['copyright' => '© New', 'links.bbb.label' => 'Data privacy'],
    ])->assertHasNoErrors();

    expect(GlobalSet::findByHandle('footer')->inDefaultSite()->data()->all())->toBe([
        'copyright' => '© New',
        'links' => [
            ['id' => 'aaa', 'label' => 'Imprint', 'url' => '/imprint'],
            ['id' => 'bbb', 'label' => 'Data privacy', 'url' => '/privacy'],
        ],
    ]);
});

it('validates global updates and needs the global permission', function () {
    GlobalSet::findByHandle('footer')->inDefaultSite()->data(['copyright' => '© Old'])->save();

    tool(editor(['access content mcp', 'edit footer globals']), UpdateGlobal::class, ['handle' => 'footer', 'data' => ['copyright' => null]])
        ->assertHasErrors(['copyright']);
    tool(editor(['access content mcp'], 'other@example.com'), UpdateGlobal::class, ['handle' => 'footer', 'data' => ['copyright' => 'x']])
        ->assertHasErrors(['Global set [footer] not found.']);

    expect(GlobalSet::findByHandle('footer')->inDefaultSite()->get('copyright'))->toBe('© Old');
});
