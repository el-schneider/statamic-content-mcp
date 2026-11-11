<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\GetEntry;
use ElSchneider\ContentMcp\Tools\UpdateEntry;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\YAML;
use Statamic\Fieldtypes\Bard\Augmentor;

/*
 * The agent form travels through get_entry and update_entry, so it is tested there.
 */

beforeEach(function () {
    siteContent();
    $this->editor = editor(['access content mcp', 'view blog entries', 'edit blog entries', 'view pages entries', 'edit pages entries']);
});

function paragraph(string $text, array $marks = []): array
{
    // No attrs: Statamic's HTML parser would add textAlign, so this node does not survive an HTML round trip.
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, ...($marks ? ['marks' => $marks] : [])]]];
}

function quoteSet(string $id, string $quote): array
{
    return ['type' => 'set', 'attrs' => ['id' => $id, 'values' => ['type' => 'quote', 'quote' => $quote, 'cite' => 'Someone']]];
}

/**
 * The field as stored in the entry's YAML file.
 */
function storedOnDisk(Entry $entry, string $field): mixed
{
    return YAML::file(Entries::find($entry->id())->path())->parse()[$field] ?? null;
}

function agentData(Entry $entry): array
{
    return tool(test()->editor, GetEntry::class, ['id' => $entry->id()])->assertHasNoErrors()->data()['data'];
}

function update(Entry $entry, array $data): void
{
    tool(test()->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => $data])->assertHasNoErrors();
}

it('turns Bard without sets into HTML and HTML back into ProseMirror', function () {
    $entry = entry('blog', ['title' => 'T', 'intro' => [paragraph('Hello', [['type' => 'italic']])]]);

    expect(agentData($entry)['intro'])->toBe('<p><em>Hello</em></p>');

    update($entry, ['intro' => '<h2>Big</h2><p>New <strong>text</strong></p>']);

    expect(storedOnDisk($entry, 'intro'))->toEqual([
        ['type' => 'heading', 'attrs' => ['textAlign' => 'left', 'level' => 2], 'content' => [['type' => 'text', 'text' => 'Big']]],
        ['type' => 'paragraph', 'attrs' => ['textAlign' => 'left'], 'content' => [
            ['type' => 'text', 'text' => 'New '],
            ['type' => 'text', 'text' => 'text', 'marks' => [['type' => 'bold']]],
        ]],
    ]);
});

it('turns Bard with sets into text and set blocks', function () {
    $entry = entry('blog', ['title' => 'T', 'content' => [paragraph('Before'), quoteSet('q1', 'Wise words'), paragraph('After')]]);

    expect(agentData($entry)['content'])->toBe([
        ['type' => 'text', 'text' => '<p>Before</p>'],
        ['type' => 'quote', 'id' => 'q1', 'quote' => 'Wise words', 'cite' => 'Someone'],
        ['type' => 'text', 'text' => '<p>After</p>'],
    ]);
});

it('converts Bard nested in a replicator set', function () {
    $entry = entry('pages', ['title' => 'T', 'page_builder' => [
        ['id' => 's1', 'type' => 'rich_text', 'text' => [paragraph('Nested')]],
    ]]);

    expect(agentData($entry)['page_builder'])->toBe([['id' => 's1', 'type' => 'rich_text', 'text' => '<p>Nested</p>']]);

    update($entry, ['page_builder.s1.text' => '<p>Changed</p>']);

    expect(storedOnDisk($entry, 'page_builder')[0]['text'][0]['content'][0]['text'])->toBe('Changed');
});

it('keeps statamic:// link and asset refs unchanged in both directions', function () {
    // Regression: Statamic's own rendering resolves these refs to URLs, which would rewrite every internal link.
    $link = [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::abc-123']]];
    $entry = entry('blog', ['title' => 'T', 'intro' => [paragraph('Read this', $link)]]);

    expect(agentData($entry)['intro'])->toContain('href="statamic://entry::abc-123"');

    update($entry, ['intro' => '<p><a href="statamic://entry::xyz-789">Other</a> <img src="statamic://asset::assets::photo.jpg"></p>']);

    $nodes = storedOnDisk($entry, 'intro')[0]['content'];
    expect($nodes[0]['marks'][0]['attrs']['href'])->toBe('statamic://entry::xyz-789')
        ->and(collect($nodes)->firstWhere('type', 'image')['attrs']['src'])->toBe('asset::assets::photo.jpg');
});

it('leaves untouched Bard byte-identical when a sibling field is patched', function () {
    $intro = [paragraph('Lossy', [['type' => 'link', 'attrs' => ['href' => 'https://example.com']]])];
    $entry = entry('blog', ['title' => 'T', 'intro' => $intro]);

    // The guard only matters because the naive round trip changes the stored value.
    $bard = Statamic\Facades\Blueprint::find('collections.blog.post')->field('intro')->fieldtype();
    $roundTrip = (new Augmentor($bard))->renderHtmlToProsemirror((new Augmentor($bard))->withStatamicImageUrls()->renderProsemirrorToHtml(['type' => 'doc', 'content' => $intro]))['content'];
    expect($roundTrip)->not->toBe($intro);

    update($entry, ['title' => 'New title']);

    expect(storedOnDisk($entry, 'intro'))->toBe($intro)
        ->and(storedOnDisk($entry, 'title'))->toBe('New title');

    // Clients also resend everything they read, with one field changed.
    update($entry, [...agentData($entry), 'title' => 'Newer title']);

    expect(storedOnDisk($entry, 'intro'))->toBe($intro)
        ->and(storedOnDisk($entry, 'title'))->toBe('Newer title');
});

it('leaves untouched sets byte-identical when the whole page builder is resent with one block changed', function () {
    $entry = entry('pages', ['title' => 'T', 'page_builder' => [
        ['id' => 's1', 'type' => 'hero', 'heading' => 'Old heading'],
        ['id' => 's2', 'type' => 'rich_text', 'text' => [paragraph('Lossy')]],
    ]]);

    $builder = agentData($entry)['page_builder'];
    $builder[0]['heading'] = 'New heading';
    update($entry, ['page_builder' => $builder]);

    expect(storedOnDisk($entry, 'page_builder'))->toBe([
        ['id' => 's1', 'type' => 'hero', 'heading' => 'New heading'],
        ['id' => 's2', 'type' => 'rich_text', 'text' => [paragraph('Lossy')]],
    ]);
});

it('leaves untouched text and sets byte-identical when Bard blocks are resent with one set changed', function () {
    $entry = entry('blog', ['title' => 'T', 'content' => [paragraph('Lossy intro'), quoteSet('q1', 'Old quote'), paragraph('Lossy outro')]]);

    $blocks = agentData($entry)['content'];
    $blocks[1]['quote'] = 'New quote';
    $blocks[] = ['type' => 'quote', 'quote' => 'Added', 'cite' => 'Me'];
    update($entry, ['content' => $blocks]);

    $stored = storedOnDisk($entry, 'content');
    expect(array_slice($stored, 0, 3))->toBe([paragraph('Lossy intro'), quoteSet('q1', 'New quote'), paragraph('Lossy outro')])
        ->and($stored[3]['attrs']['values'])->toBe(['type' => 'quote', 'quote' => 'Added', 'cite' => 'Me'])
        ->and($stored[3]['attrs']['id'])->toBeString()->not->toBeEmpty();
});

it('patches one set by id with a dot path and leaves its siblings alone', function () {
    $entry = entry('pages', ['title' => 'T', 'page_builder' => [
        ['id' => 's1', 'type' => 'hero', 'heading' => 'One'],
        ['id' => 's2', 'type' => 'hero', 'heading' => 'Two'],
    ]]);

    update($entry, ['page_builder.s2.heading' => 'Second', 'settings.theme' => 'dark']);

    expect(storedOnDisk($entry, 'page_builder'))->toBe([
        ['id' => 's1', 'type' => 'hero', 'heading' => 'One'],
        ['id' => 's2', 'type' => 'hero', 'heading' => 'Second'],
        // A group the entry never had gets its defaults, as when the CP saves it.
    ])->and(storedOnDisk($entry, 'settings'))->toBe(['theme' => 'dark', 'accent' => 'blue']);
});

it('keeps stored group keys the blueprint does not know when patching a sibling', function () {
    // Regression: Group::process() rebuilds the group from its configured fields and dropped the rest.
    $entry = entry('pages', ['title' => 'T', 'settings' => ['theme' => 'light', 'legacy_note' => 'Keep']]);

    update($entry, ['settings.theme' => 'dark']);

    expect(storedOnDisk($entry, 'settings'))->toBe(['theme' => 'dark', 'legacy_note' => 'Keep']);

    // Replacing the whole group is how a client removes such keys.
    update($entry, ['settings' => ['theme' => 'light']]);

    expect(storedOnDisk($entry, 'settings'))->toBe(['theme' => 'light']);
});

it('patches a Bard set by id and rejects paths to sets that do not exist', function () {
    $entry = entry('blog', ['title' => 'T', 'content' => [paragraph('Text'), quoteSet('q1', 'Quote')]]);

    update($entry, ['content.q1.cite' => 'Socrates']);

    expect(storedOnDisk($entry, 'content'))->toBe([
        paragraph('Text'),
        ['type' => 'set', 'attrs' => ['id' => 'q1', 'values' => ['type' => 'quote', 'quote' => 'Quote', 'cite' => 'Socrates']]],
    ]);

    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => ['content.nope.cite' => 'x']])
        ->assertHasErrors(['No item with id [nope]']);
});
