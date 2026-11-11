<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\CreateTerm;
use ElSchneider\ContentMcp\Tools\GetBlueprint;
use ElSchneider\ContentMcp\Tools\GetTerm;
use ElSchneider\ContentMcp\Tools\UpdateTerm;
use Illuminate\Support\Facades\Event;
use Statamic\Events\TermSaving;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;
use Statamic\Facades\YAML;

beforeEach(function () {
    siteContent();
    $this->editor = editor(['access content mcp', 'view tags terms', 'create tags terms', 'edit tags terms']);
});

/**
 * @return array<string, list<string>>
 */
function termErrors(Laravel\Mcp\Server\Testing\TestResponse $response): array
{
    return json_decode($response->errorText(), true)['errors'] ?? [];
}

it('creates a term with a slug from its title, validated like the CP', function () {
    $data = tool($this->editor, CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Grüne Gärten', 'description' => 'Plants']])->assertHasNoErrors()->data();

    expect($data)->toMatchArray(['taxonomy' => 'tags', 'slug' => 'grune-garten', 'title' => 'Grüne Gärten', 'data' => ['title' => 'Grüne Gärten', 'description' => 'Plants']])
        ->and(Term::find('tags::grune-garten')->get('description'))->toBe('Plants');

    // The CP's UniqueTermValue rule: a second term with the same slug is refused.
    expect(termErrors(tool($this->editor, CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['title' => 'Grüne Gärten']])))->toHaveKey('slug')
        ->and(termErrors(tool($this->editor, CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['description' => 'No title']])))->toHaveKeys(['title', 'slug'])
        ->and(termErrors(tool($this->editor, CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['title' => 'X', 'shade' => 'red']])))->toHaveKey('shade');
});

it('reads and updates a term, and renames it through its slug', function () {
    Term::make('garden')->taxonomy('tags')->data(['title' => 'Garden', 'color' => 'green'])->save();

    expect(tool($this->editor, GetTerm::class, ['taxonomy' => 'tags', 'slug' => 'garden'])->data())->toMatchArray([
        'slug' => 'garden',
        'title' => 'Garden',
        'url' => 'http://localhost/tags/garden',
        'data' => ['title' => 'Garden', 'color' => 'green'],
    ]);

    $oldPath = Term::find('tags::garden')->path();

    tool($this->editor, UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'garden', 'data' => ['description' => 'Outdoors', 'slug' => 'gardening']])->assertHasNoErrors();

    // The Stache's in-memory test cache keeps the old key, so the files tell the truth.
    expect(YAML::file(Term::find('tags::gardening')->path())->parse())->toBe(['title' => 'Garden', 'color' => 'green', 'description' => 'Outdoors', 'updated_by' => $this->editor->id(), 'updated_at' => Term::find('tags::gardening')->get('updated_at')])
        ->and(file_exists($oldPath))->toBeFalse();
});

it('refuses term writes without the permission and hides terms of taxonomies the editor may not view', function () {
    Term::make('garden')->taxonomy('tags')->data(['title' => 'Garden'])->save();
    $reader = editor(['access content mcp', 'view tags terms'], 'reader@example.com');
    $stranger = editor(['access content mcp'], 'stranger@example.com');

    tool($reader, CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['title' => 'New']])->assertHasErrors(['You may not create terms in [tags].']);
    tool($reader, UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'garden', 'data' => ['title' => 'Changed']])->assertHasErrors(['You may not edit this term.']);
    tool($stranger, GetTerm::class, ['taxonomy' => 'tags', 'slug' => 'garden'])->assertHasErrors(['Taxonomy [tags] not found.']);
    tool($this->editor, GetTerm::class, ['taxonomy' => 'tags', 'slug' => 'nope'])->assertHasErrors(['Term [nope] not found in [tags].']);

    expect(Term::find('tags::garden')->get('title'))->toBe('Garden')
        ->and(Term::find('tags::new'))->toBeNull();
});

it('reports a term save a site listener cancelled', function () {
    Term::make('garden')->taxonomy('tags')->data(['title' => 'Garden'])->save();
    Event::listen(TermSaving::class, fn () => false);

    tool($this->editor, UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'garden', 'data' => ['title' => 'Changed']])->assertHasErrors(['The site cancelled this save']);
    tool($this->editor, CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['title' => 'New']])->assertHasErrors(['The site cancelled this save']);
});

it('creates a term in another site with its default-site version, and keeps non-localizable fields there', function () {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'default' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'de' => ['name' => 'Deutsch', 'url' => 'http://localhost/de/', 'locale' => 'de_DE'],
    ]);
    Taxonomy::findByHandle('tags')->sites(['default', 'de'])->save();
    $editor = editor(['access content mcp', 'access default site', 'access de site', 'view tags terms', 'create tags terms', 'edit tags terms'], 'multi@example.com');

    $created = tool($editor, CreateTerm::class, ['taxonomy' => 'tags', 'site' => 'de', 'data' => ['title' => 'Musik', 'color' => 'blue']])->assertHasNoErrors()->data();
    $errors = termErrors(tool($editor, UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'musik', 'site' => 'de', 'data' => ['color' => 'red']]));

    expect($created)->toMatchArray(['site' => 'de', 'slug' => 'musik'])
        ->and(Term::find('tags::musik')->in('default')->get('title'))->toBe('Musik')
        ->and($errors['color'][0])->toContain('Not localizable')
        ->and(YAML::file(Term::find('tags::musik')->path())->parse()['color'])->toBe('blue');

    // Sending back what get_term showed keeps inherited values inherited.
    $jazz = Term::make('jazz')->taxonomy('tags')->data(['title' => 'Jazz', 'description' => 'Smooth', 'color' => 'gold']);
    $jazz->dataForLocale('de', ['title' => 'Jazz DE']);
    $jazz->save();
    $data = tool($editor, GetTerm::class, ['taxonomy' => 'tags', 'slug' => 'jazz', 'site' => 'de'])->data()['data'];

    tool($editor, UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'jazz', 'site' => 'de', 'data' => [...$data, 'title' => 'Jazzmusik']])->assertHasNoErrors();

    expect(YAML::file(Term::find('tags::jazz')->path())->parse()['localizations']['de'])->toBe(['title' => 'Jazzmusik', 'updated_by' => $editor->id(), 'updated_at' => Term::find('tags::jazz')->in('de')->get('updated_at')]);
});

it('describes taxonomy blueprints and takes exactly one source', function () {
    $fields = collect(tool($this->editor, GetBlueprint::class, ['taxonomy' => 'tags'])->assertHasNoErrors()->data()['fields'])->pluck('handle');

    expect($fields->all())->toContain('title', 'slug', 'description', 'color');

    tool($this->editor, GetBlueprint::class, ['taxonomy' => 'tags', 'collection' => 'blog'])->assertHasErrors(['Pass exactly one of']);
});
