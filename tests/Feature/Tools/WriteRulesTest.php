<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\CreateEntry;
use ElSchneider\ContentMcp\Tools\UnpublishEntry;
use ElSchneider\ContentMcp\Tools\UpdateEntry;
use ElSchneider\ContentMcp\Tools\UpdateGlobal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Statamic\Entries\Entry;
use Statamic\Events\EntrySaving;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Events\RevisionSaving;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Tests\Fixtures\HideSecretEntriesPolicy;

/*
 * Rules the CP enforces on what an editor's submission may change.
 */

beforeEach(function () {
    siteContent();
    $this->editor = editor([
        'access content mcp',
        'view blog entries', 'create blog entries', 'edit blog entries', 'publish blog entries',
        'view pages entries', 'create pages entries', 'edit pages entries',
        'edit footer globals',
    ]);
});

/**
 * @return array<string, mixed>
 */
function validationErrors(Laravel\Mcp\Server\Testing\TestResponse $response): array
{
    return json_decode($response->errorText(), true)['errors'] ?? [];
}

it('rejects changes to read-only fields nested in groups, sets and Bard sets', function (string $collection, array $stored, array $data, string $path) {
    // Regression: only top-level handles were checked, so `settings.owner` slipped through.
    $entry = entry($collection, ['title' => 'T', ...$stored]);

    $errors = validationErrors(tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => $data]));

    expect(collect($errors)->filter(fn (array $messages, string $key) => str_ends_with($key, $path))->flatten()->all())->toBe(['This field is read-only.'])
        ->and(Entries::find($entry->id())->data()->all())->toBe(['title' => 'T', ...$stored]);
})->with([
    'group, dot path' => ['pages', ['settings' => ['owner' => 'alice']], ['settings.owner' => 'mallory'], 'settings.owner'],
    'group, whole field' => ['pages', ['settings' => ['owner' => 'alice']], ['settings' => ['owner' => 'mallory']], 'settings.owner'],
    'replicator set' => ['pages', ['page_builder' => [['id' => 's1', 'type' => 'hero', 'heading' => 'H', 'badge' => 'old']]], ['page_builder.s1.badge' => 'new'], 'page_builder.s1.badge'],
    'new replicator set' => ['pages', [], ['page_builder' => [['type' => 'hero', 'heading' => 'H', 'badge' => 'sneaky']]], '.badge'],
    'Bard set' => ['blog', ['content' => [['type' => 'set', 'attrs' => ['id' => 'q1', 'values' => ['type' => 'quote', 'quote' => 'Q', 'source_id' => '1']]]]], ['content.q1.source_id' => '2'], 'content.q1.source_id'],
]);

it('accepts read-only values that are sent back unchanged', function () {
    // Clients resend what get_entry returned; that must not trip the read-only rule.
    $entry = entry('pages', ['title' => 'T', 'settings' => ['owner' => 'alice', 'theme' => 'light']]);

    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => ['settings' => ['owner' => 'alice', 'theme' => 'dark']]])
        ->assertHasNoErrors();

    expect(Entries::find($entry->id())->get('settings'))->toEqual(['owner' => 'alice', 'theme' => 'dark']);
});

it('never takes the published state from entry data, even when the blueprint has such a field', function () {
    // Regression: the stored `published` key decides publication on the next load, so data must not set it.
    Collection::make('news')->save();
    $editor = editor(['access content mcp', 'view news entries', 'create news entries', 'edit news entries'], 'writer@example.com');
    $entry = entry('news', ['title' => 'Draft', 'author' => $editor->id()], published: false);

    $update = validationErrors(tool($editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => ['published' => true]]));
    $create = validationErrors(tool($editor, CreateEntry::class, ['collection' => 'news', 'data' => ['title' => 'New', 'published' => true]]));

    expect($update['published'][0])->toContain('publish_entry')
        ->and($create['published'][0])->toContain('publish_entry')
        ->and(Entries::find($entry->id())->published())->toBeFalse()
        ->and(Statamic\Facades\YAML::file($entry->path())->parse())->not->toHaveKey('published', true)
        ->and(Entries::query()->where('collection', 'news')->count())->toBe(1);
});

it('reports a parent the editor may not view exactly like a missing one', function () {
    Gate::policy(Entry::class, HideSecretEntriesPolicy::class);
    $secret = entry('pages', ['title' => 'Secret'], slug: 'secret');
    Collection::findByHandle('pages')->structure()->in('default')->append($secret)->save();

    $hidden = tool($this->editor, CreateEntry::class, ['collection' => 'pages', 'data' => ['title' => 'Child'], 'parent' => $secret->id()])->errorText();
    $missing = tool($this->editor, CreateEntry::class, ['collection' => 'pages', 'data' => ['title' => 'Child'], 'parent' => 'nope'])->errorText();

    expect($hidden)->toBe("Entry [{$secret->id()}] not found.")
        ->and($missing)->toBe('Entry [nope] not found.')
        ->and(Entries::query()->where('collection', 'pages')->count())->toBe(1);
});

it('derives the slug from the title when omitted, and still enforces unique slugs', function () {
    // Regression: the CP fills the slug in the browser; without it a required slug field failed validation.
    $first = tool($this->editor, CreateEntry::class, ['collection' => 'blog', 'data' => ['title' => 'MCP Testbeitrag']])->assertHasNoErrors()->data();

    expect($first['slug'])->toBe('mcp-testbeitrag');

    $errors = validationErrors(tool($this->editor, CreateEntry::class, ['collection' => 'blog', 'data' => ['title' => 'MCP Testbeitrag']]));

    expect($errors)->toHaveKey('slug')
        ->and(Entries::query()->where('collection', 'blog')->count())->toBe(1);
});

it('derives the slug of auto-titled entries from the submitted data', function () {
    // Regression: the title format was evaluated before the data was assigned, giving an empty slug.
    Collection::make('notes')->titleFormats('Note {body}')->save();
    $editor = editor(['access content mcp', 'view notes entries', 'create notes entries'], 'notes@example.com');

    $data = tool($editor, CreateEntry::class, ['collection' => 'notes', 'data' => ['body' => 'Hello there']])->assertHasNoErrors()->data();

    expect($data['slug'])->toBe('note-hello-there');
});

it('reports a save a site listener cancelled as an error, not as success', function (string $event, string $tool, Closure $arguments) {
    // Regression: Statamic's save() returns false when a saving listener vetoes it; that was ignored.
    $arguments = $arguments();
    Event::listen($event, fn () => false);

    tool($this->editor, $tool, $arguments)->assertHasErrors(['The site cancelled this save']);
})->with([
    'create' => [EntrySaving::class, CreateEntry::class, fn () => ['collection' => 'blog', 'data' => ['title' => 'New']]],
    'update' => [EntrySaving::class, UpdateEntry::class, fn () => ['id' => entry('blog', ['title' => 'Old'])->id(), 'data' => ['title' => 'New']]],
    'unpublish' => [EntrySaving::class, UnpublishEntry::class, fn () => ['id' => entry('blog', ['title' => 'Live'])->id()]],
    'global' => [GlobalVariablesSaving::class, UpdateGlobal::class, fn () => ['handle' => 'footer', 'data' => ['copyright' => 'New']]],
]);

it('reports a cancelled working copy as an error', function () {
    config(['statamic.revisions.enabled' => true, 'statamic.revisions.path' => __DIR__.'/../../__fixtures__/dev-null/revisions']);
    Collection::findByHandle('blog')->revisionsEnabled(true)->save();
    $entry = entry('blog', ['title' => 'Live']);
    Event::listen(RevisionSaving::class, fn () => false);

    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => ['title' => 'Proposed']])
        ->assertHasErrors(['The site cancelled this save']);

    expect(Entries::find($entry->id())->hasWorkingCopy())->toBeFalse();
});

it('clears optional fields set to null instead of writing their default', function () {
    // Regression: Statamic's preProcess replaces null with the field default.
    $entry = entry('blog', ['title' => 'T', 'layout' => 'wide']);
    $page = entry('pages', ['title' => 'P', 'settings' => ['theme' => 'dark', 'accent' => 'red']]);

    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => ['layout' => null]])->assertHasNoErrors();
    tool($this->editor, UpdateEntry::class, ['id' => $page->id(), 'data' => ['settings.accent' => null]])->assertHasNoErrors();
    $created = tool($this->editor, CreateEntry::class, ['collection' => 'blog', 'data' => ['title' => 'New', 'layout' => null]])->data();
    $defaulted = tool($this->editor, CreateEntry::class, ['collection' => 'blog', 'data' => ['title' => 'Other']])->data();

    expect(Entries::find($entry->id())->get('layout'))->toBeNull()
        ->and(Entries::find($page->id())->get('settings'))->toBe(['theme' => 'dark'])
        ->and(Entries::find($created['id'])->get('layout'))->toBeNull()
        ->and(Entries::find($defaulted['id'])->get('layout'))->toBe('standard');
});
