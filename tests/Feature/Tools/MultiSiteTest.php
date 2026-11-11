<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\CreateEntry;
use ElSchneider\ContentMcp\Tools\FindEntries;
use ElSchneider\ContentMcp\Tools\GetEntry;
use ElSchneider\ContentMcp\Tools\GetGlobal;
use ElSchneider\ContentMcp\Tools\GetOverview;
use ElSchneider\ContentMcp\Tools\ListTerms;
use ElSchneider\ContentMcp\Tools\LocalizeEntry;
use ElSchneider\ContentMcp\Tools\UpdateEntry;
use ElSchneider\ContentMcp\Tools\UpdateGlobal;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\YAML;

/*
 * Three sites; the editor may access `default` and `de`, not `fr`.
 */

beforeEach(function () {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'de' => ['name' => 'Deutsch', 'url' => 'http://localhost/de/', 'locale' => 'de_DE'],
        'fr' => ['name' => 'Français', 'url' => 'http://localhost/fr/', 'locale' => 'fr_FR'],
    ]);
    Collection::make('events')->title('Events')->routes('/events/{slug}')->sites(['default', 'de', 'fr'])->save();
    GlobalSet::make('contact')->title('Contact')->sites(['default' => null, 'de' => 'default', 'fr' => 'default'])->save();
    Taxonomy::make('tags')->title('Tags')->sites(['default', 'de'])->save();

    $this->editor = editor([
        'access content mcp', 'access default site', 'access de site',
        'view events entries', 'create events entries', 'edit events entries',
        'edit contact globals', 'view tags terms',
    ]);
});

/**
 * An English event with a German localization that overrides the title only.
 *
 * @return array{0: Entry, 1: Entry}
 */
function localizedEvent(): array
{
    $origin = Entries::make()->collection('events')->locale('default')->slug('concert')
        ->data(['title' => 'Concert', 'summary' => 'An evening of music', 'venue' => 'Town hall']);
    $origin->save();

    $german = $origin->makeLocalization('de')->slug('konzert')->data(['title' => 'Konzert']);
    $german->save();

    return [$origin, $german];
}

it('lists the accessible sites and where collections and globals exist', function () {
    $data = tool($this->editor, GetOverview::class)->data();

    expect($data['sites'])->toBe([
        ['handle' => 'default', 'name' => 'English', 'locale' => 'en_US', 'url' => 'http://localhost'],
        ['handle' => 'de', 'name' => 'Deutsch', 'locale' => 'de_DE', 'url' => 'http://localhost/de'],
    ])
        ->and(collect($data['collections'])->firstWhere('handle', 'events')['sites'])->toBe(['default', 'de'])
        ->and(collect($data['globals'])->firstWhere('handle', 'contact')['sites'])->toBe(['default', 'de']);
});

it('reports a site the editor may not access exactly like an unknown one', function (string $tool, array $arguments) {
    $hidden = tool($this->editor, $tool, [...$arguments, 'site' => 'fr'])->errorText();
    $unknown = tool($this->editor, $tool, [...$arguments, 'site' => 'xx'])->errorText();

    expect($hidden)->toBe('Site [fr] not found.')
        ->and($unknown)->toBe('Site [xx] not found.');
})->with([
    'find_entries' => [FindEntries::class, ['collection' => 'events']],
    'create_entry' => [CreateEntry::class, ['collection' => 'events', 'data' => ['title' => 'X']]],
    'get_global' => [GetGlobal::class, ['handle' => 'contact']],
    'update_global' => [UpdateGlobal::class, ['handle' => 'contact', 'data' => ['hours' => 'x']]],
    'list_terms' => [ListTerms::class, ['taxonomy' => 'tags']],
]);

it('checks access to the default site when no site is given', function (string $tool, array $arguments) {
    // Regression: an omitted `site` skipped the check an explicit `site: default` gets.
    $german = editor(['access content mcp', 'access de site', 'view events entries', 'create events entries', 'edit contact globals', 'view tags terms', 'create tags terms', 'edit tags terms'], 'german@example.com');
    Term::make('music')->taxonomy('tags')->data(['title' => 'Music'])->save();

    expect(tool($german, $tool, $arguments)->errorText())->toBe('Site [default] not found.')
        ->and(tool($german, $tool, [...$arguments, 'site' => 'de'])->errorText())->not->toContain('Site [');
})->with([
    'find_entries' => [FindEntries::class, ['collection' => 'events']],
    'create_entry' => [CreateEntry::class, ['collection' => 'events', 'data' => ['title' => 'X']]],
    'get_global' => [GetGlobal::class, ['handle' => 'contact']],
    'update_global' => [UpdateGlobal::class, ['handle' => 'contact', 'data' => ['hours' => 'x']]],
    'list_terms' => [ListTerms::class, ['taxonomy' => 'tags']],
    'get_term' => [ElSchneider\ContentMcp\Tools\GetTerm::class, ['taxonomy' => 'tags', 'slug' => 'music']],
    'create_term' => [ElSchneider\ContentMcp\Tools\CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Jazz']]],
    'update_term' => [ElSchneider\ContentMcp\Tools\UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'music', 'data' => ['title' => 'Musik']]],
]);

it('finds and creates entries in the given site', function () {
    [, $german] = localizedEvent();

    $found = tool($this->editor, FindEntries::class, ['collection' => 'events', 'site' => 'de'])->data();
    $created = tool($this->editor, CreateEntry::class, ['collection' => 'events', 'site' => 'de', 'data' => ['title' => 'Straßenfest']])->assertHasNoErrors()->data();

    expect(collect($found['entries'])->pluck('id')->all())->toBe([$german->id()])
        ->and($created['site'])->toBe('de')
        ->and($created['slug'])->toBe('strassenfest')
        ->and(Entries::find($created['id'])->locale())->toBe('de');
});

it('returns a localization with what it resolves to, its origin and its overrides', function () {
    [$origin, $german] = localizedEvent();

    $data = tool($this->editor, GetEntry::class, ['id' => $german->id()])->assertHasNoErrors()->data();

    expect($data)->toMatchArray([
        'site' => 'de',
        'origin' => $origin->id(),
        'localizations' => ['default' => $origin->id(), 'de' => $german->id()],
        'localized_fields' => ['title'],
    ])->and($data['data'])->toBe(['title' => 'Konzert', 'summary' => 'An evening of music', 'venue' => 'Town hall']);

    expect(tool($this->editor, GetEntry::class, ['id' => $origin->id()])->data())->toMatchArray(['origin' => null, 'localized_fields' => null]);
});

it('writes changes of a localization to that localization only', function () {
    [$origin, $german] = localizedEvent();

    tool($this->editor, UpdateEntry::class, ['id' => $german->id(), 'data' => ['summary' => 'Ein Abend mit Musik']])->assertHasNoErrors();

    expect(YAML::file(Entries::find($german->id())->path())->parse())->toMatchArray(['title' => 'Konzert', 'summary' => 'Ein Abend mit Musik'])
        ->and(YAML::file(Entries::find($german->id())->path())->parse())->not->toHaveKey('venue')
        ->and(Entries::find($origin->id())->get('summary'))->toBe('An evening of music');
});

it('refuses non-localizable fields on a localization, like the CP', function () {
    [$origin, $german] = localizedEvent();

    $errors = json_decode(tool($this->editor, UpdateEntry::class, ['id' => $german->id(), 'data' => ['venue' => 'Rathaus']])->errorText(), true)['errors'];

    expect($errors['venue'][0])->toContain("[{$origin->id()}]")
        ->and(Entries::find($german->id())->data()->has('venue'))->toBeFalse();
});

it('reads and updates globals per site, writing localized values only', function () {
    $contact = GlobalSet::findByHandle('contact');
    $contact->in('default')->data(['hours' => '9–5', 'phone' => '123'])->save();

    tool($this->editor, UpdateGlobal::class, ['handle' => 'contact', 'site' => 'de', 'data' => ['hours' => '9–17 Uhr']])->assertHasNoErrors();
    $german = tool($this->editor, GetGlobal::class, ['handle' => 'contact', 'site' => 'de'])->data();
    $phone = tool($this->editor, UpdateGlobal::class, ['handle' => 'contact', 'site' => 'de', 'data' => ['phone' => '456']])->errorText();

    expect($german['data'])->toBe(['hours' => '9–17 Uhr', 'phone' => '123'])
        ->and(GlobalSet::findByHandle('contact')->in('de')->data()->all())->toBe(['hours' => '9–17 Uhr'])
        ->and(GlobalSet::findByHandle('contact')->in('default')->get('hours'))->toBe('9–5')
        ->and($phone)->toContain('phone');
});

it('lists the terms of the given site', function () {
    $term = Term::make('music')->taxonomy('tags')->data(['title' => 'Music']);
    $term->dataForLocale('de', ['title' => 'Musik']);
    $term->save();

    expect(tool($this->editor, ListTerms::class, ['taxonomy' => 'tags', 'site' => 'de'])->data()['terms'])->toBe([['slug' => 'music', 'title' => 'Musik']]);
});

it('creates a localization the way the CP does, once', function () {
    $english = Entries::make()->collection('events')->locale('default')->slug('market')->data(['title' => 'Market', 'venue' => 'Square']);
    $english->save();

    $data = tool($this->editor, LocalizeEntry::class, ['id' => $english->id(), 'site' => 'de'])->assertHasNoErrors()->data();
    $again = tool($this->editor, LocalizeEntry::class, ['id' => $english->id(), 'site' => 'de'])->errorText();
    $forbidden = tool($this->editor, LocalizeEntry::class, ['id' => $english->id(), 'site' => 'fr'])->errorText();

    $localization = Entries::find($data['id']);
    expect($localization->origin()->id())->toBe($english->id())
        ->and($localization->locale())->toBe('de')
        ->and($localization->published())->toBe($english->published())
        ->and($localization->data()->except(['updated_by', 'updated_at'])->all())->toBe([])
        ->and($localization->lastModifiedBy()?->id())->toBe($this->editor->id())
        ->and($data['localizations'])->toBe(['default' => $english->id(), 'de' => $data['id']])
        ->and($again)->toContain("already exists in [de]: [{$data['id']}]")
        ->and($forbidden)->toBe('Site [fr] not found.');
});

it('registers localize_entry only on multi-site installs', function () {
    ContentMcpServer::tools()->assertRegistered(LocalizeEntry::class);

    config(['statamic.system.multisite' => false]);

    ContentMcpServer::tools()->assertNotRegistered(LocalizeEntry::class);
});
