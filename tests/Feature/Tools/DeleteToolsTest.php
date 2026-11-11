<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\DeleteAsset;
use ElSchneider\ContentMcp\Tools\DeleteEntry;
use ElSchneider\ContentMcp\Tools\DeleteTerm;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Statamic\Entries\Entry;
use Statamic\Events\AssetDeleting;
use Statamic\Events\EntryDeleting;
use Statamic\Events\TermDeleting;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Site;
use Statamic\Facades\Term;
use Tests\Fixtures\HideSecretEntriesPolicy;

beforeEach(function () {
    siteContent();
    config(['statamic.content-mcp.deletes' => true]);
    $this->editor = editor([
        'access content mcp',
        'view blog entries', 'delete blog entries', 'view pages entries', 'delete pages entries',
        'view tags terms', 'delete tags terms',
        'view assets assets', 'delete assets assets',
    ]);
});

it('offers delete tools only when the site turns them on', function () {
    ContentMcpServer::tools()->assertRegistered([DeleteEntry::class, DeleteTerm::class, DeleteAsset::class]);

    config(['statamic.content-mcp.deletes' => false]);

    ContentMcpServer::tools()->assertNotRegistered([DeleteEntry::class, DeleteTerm::class, DeleteAsset::class]);
    $entry = entry('blog', ['title' => 'Keep']);
    tool($this->editor, DeleteEntry::class, ['id' => $entry->id()])->assertHasErrors(['Tool [delete_entry] not found.']);

    expect(Entries::find($entry->id()))->not->toBeNull();
});

it('deletes an entry with the delete permission only, and hides those a policy denies', function () {
    Gate::policy(Entry::class, HideSecretEntriesPolicy::class);
    $entry = entry('blog', ['title' => 'Old']);
    $secret = entry('blog', ['title' => 'Secret'], slug: 'secret');

    tool(editor(['access content mcp', 'view blog entries'], 'viewer@example.com'), DeleteEntry::class, ['id' => $entry->id()])
        ->assertHasErrors(['You may not delete this entry.']);
    tool($this->editor, DeleteEntry::class, ['id' => $secret->id()])->assertHasErrors(["Entry [{$secret->id()}] not found."]);
    expect(Entries::find($entry->id()))->not->toBeNull();

    tool($this->editor, DeleteEntry::class, ['id' => $entry->id()])->assertStructuredContent(['deleted' => $entry->id()]);

    expect(Entries::find($entry->id()))->toBeNull()
        ->and(Entries::find($secret->id()))->not->toBeNull();
});

it('moves child pages up to the parent, like the CP, and keeps the root page', function () {
    Collection::findByHandle('pages')->structureContents(['root' => true])->save();
    $home = entry('pages', ['title' => 'Home']);
    $about = entry('pages', ['title' => 'About']);
    $team = entry('pages', ['title' => 'Team']);
    $tree = Collection::findByHandle('pages')->structure()->in('default');
    $tree->append($home)->append($about)->appendTo($about->id(), $team)->save();

    tool($this->editor, DeleteEntry::class, ['id' => $home->id()])->assertHasErrors(['root page of a page tree cannot be deleted']);
    tool($this->editor, DeleteEntry::class, ['id' => $about->id()])->assertHasNoErrors();

    // Blink caches the collection's entry ids for the request, the next request reads them fresh.
    Statamic\Facades\Blink::flush();

    expect(Collection::findByHandle('pages')->structure()->in('default')->tree())->toBe([['entry' => $home->id()], ['entry' => $team->id()]])
        ->and(Entries::find($about->id()))->toBeNull();
});

it('needs a choice for an entry localized in other sites, and only detaches where the editor may not go', function () {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'de' => ['name' => 'Deutsch', 'url' => 'http://localhost/de/', 'locale' => 'de_DE'],
    ]);
    Collection::findByHandle('blog')->sites(['default', 'de'])->save();
    $editor = editor(['access content mcp', 'access default site', 'view blog entries', 'delete blog entries'], 'single@example.com');
    $both = editor(['access content mcp', 'access default site', 'access de site', 'view blog entries', 'delete blog entries'], 'both@example.com');
    $make = function (string $title): array {
        $origin = entry('blog', ['title' => $title]);
        $german = $origin->makeLocalization('de');
        $german->save();

        return [$origin, $german];
    };

    [$first, $firstGerman] = $make('First');
    $ask = tool($both, DeleteEntry::class, ['id' => $first->id()])->errorText();
    $forced = tool($editor, DeleteEntry::class, ['id' => $first->id(), 'localizations' => 'delete'])->errorText();
    tool($editor, DeleteEntry::class, ['id' => $first->id(), 'localizations' => 'detach'])->assertHasNoErrors();

    [$second, $secondGerman] = $make('Second');
    tool($both, DeleteEntry::class, ['id' => $second->id(), 'localizations' => 'delete'])->assertHasNoErrors();

    expect($ask)->toContain('versions in other sites')
        ->and($forced)->toContain('sites you may not access')
        ->and(Entries::find($first->id()))->toBeNull()
        ->and(Entries::find($firstGerman->id())?->hasOrigin())->toBeFalse()
        ->and(Entries::find($firstGerman->id())->get('title'))->toBe('First')
        ->and(Entries::find($second->id()))->toBeNull()
        ->and(Entries::find($secondGerman->id()))->toBeNull();
});

it('deletes terms and assets with the delete permission', function () {
    Term::make('old')->taxonomy('tags')->data(['title' => 'Old'])->save();
    Storage::fake('assets');
    AssetContainer::make('assets')->disk('assets')->save();
    Storage::disk('assets')->put('old.txt', 'x');
    $viewer = editor(['access content mcp', 'view tags terms', 'view assets assets'], 'viewer@example.com');

    tool($viewer, DeleteTerm::class, ['taxonomy' => 'tags', 'slug' => 'old'])->assertHasErrors(['You may not delete this term.']);
    tool($viewer, DeleteAsset::class, ['id' => 'assets::old.txt'])->assertHasErrors(['You may not delete this asset.']);
    tool($this->editor, DeleteTerm::class, ['taxonomy' => 'tags', 'slug' => 'old'])->assertHasNoErrors();
    tool($this->editor, DeleteAsset::class, ['id' => 'assets::old.txt'])->assertHasNoErrors();

    expect(Term::find('tags::old')?->path())->not->toBeString()
        ->and(Storage::disk('assets')->exists('old.txt'))->toBeFalse()
        ->and(Asset::find('assets::old.txt'))->toBeNull();
});

it('reports a deletion a site listener refused', function (string $event, string $tool, Closure $arguments) {
    Storage::fake('assets');
    AssetContainer::make('assets')->disk('assets')->save();
    $arguments = $arguments();
    Event::listen($event, fn () => false);

    tool($this->editor, $tool, $arguments)->assertHasErrors(['The site cancelled this deletion']);
})->with([
    'entry' => [EntryDeleting::class, DeleteEntry::class, fn () => ['id' => entry('blog', ['title' => 'Stay'])->id()]],
    'term' => [TermDeleting::class, DeleteTerm::class, function () {
        Term::make('stay')->taxonomy('tags')->data(['title' => 'Stay'])->save();

        return ['taxonomy' => 'tags', 'slug' => 'stay'];
    }],
    'asset' => [AssetDeleting::class, DeleteAsset::class, function () {
        Storage::disk('assets')->put('stay.txt', 'x');

        return ['id' => 'assets::stay.txt'];
    }],
]);
