<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\GetBlueprint;
use ElSchneider\ContentMcp\Tools\GetNavigation;
use ElSchneider\ContentMcp\Tools\GetOverview;
use ElSchneider\ContentMcp\Tools\MoveEntry;
use ElSchneider\ContentMcp\Tools\UpdateNavigation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Statamic\Entries\Entry;
use Statamic\Events\CollectionTreeSaving;
use Statamic\Events\NavTreeSaving;
use Statamic\Facades\Collection;
use Statamic\Facades\Nav;
use Tests\Fixtures\HideSecretEntriesPolicy;

beforeEach(function () {
    siteContent();
    $this->editor = editor(['access content mcp', 'view main nav', 'edit main nav', 'view pages entries', 'edit pages entries', 'reorder pages entries', 'view blog entries']);
});

/**
 * @return array<string, Entry>
 */
function pages(string ...$titles): array
{
    $tree = Collection::findByHandle('pages')->structure()->in('default');
    $pages = [];

    foreach ($titles as $title) {
        $pages[$title] = entry('pages', ['title' => $title]);
        $tree->append($pages[$title]);
    }

    $tree->save();

    return $pages;
}

/**
 * The page tree as titles: ['About' => ['Team' => []], …].
 *
 * @return array<string, mixed>
 */
function pageTree(?array $branches = null): array
{
    $branches ??= Collection::findByHandle('pages')->structure()->in('default')->tree();

    return collect($branches)->mapWithKeys(fn (array $branch) => [
        Statamic\Facades\Entry::find($branch['entry'])->value('title') => pageTree($branch['children'] ?? []),
    ])->all();
}

function mainNav(array $tree): void
{
    $nav = Nav::make('main')->title('Main')->collections(['pages'])->maxDepth(2);
    $nav->save();
    $nav->makeTree('default', $tree)->save();
}

function storedNav(): array
{
    return Nav::find('main')->in('default')->tree();
}

function structureErrors(Laravel\Mcp\Server\Testing\TestResponse $response): array
{
    return json_decode($response->errorText(), true)['errors'] ?? [];
}

it('returns a navigation tree with entry titles and urls and the node data', function () {
    ['About' => $about] = pages('About');
    mainNav([
        ['id' => 'n1', 'entry' => $about->id(), 'data' => ['icon' => 'info'], 'children' => [
            ['id' => 'n2', 'title' => 'Shop', 'url' => 'https://shop.example.com'],
        ]],
    ]);

    $data = tool($this->editor, GetNavigation::class, ['handle' => 'main'])->assertHasNoErrors()->data();

    expect($data)->toMatchArray(['handle' => 'main', 'max_depth' => 2, 'collections' => ['pages']])
        ->and($data['tree'])->toBe([[
            'id' => 'n1', 'title' => 'About', 'url' => 'http://localhost/about', 'entry' => $about->id(), 'data' => ['icon' => 'info'],
            'children' => [['id' => 'n2', 'title' => 'Shop', 'url' => 'https://shop.example.com', 'entry' => null, 'data' => [], 'children' => []]],
        ]])
        ->and(collect(tool($this->editor, GetOverview::class)->data()['navigations'])->pluck('handle')->all())->toBe(['main']);

    tool(editor(['access content mcp'], 'other@example.com'), GetNavigation::class, ['handle' => 'main'])->assertHasErrors(['Navigation [main] not found.']);
});

it('keeps a navigation byte-identical when the tree it returned is sent back', function () {
    ['About' => $about, 'Contact' => $contact] = pages('About', 'Contact');
    $stored = [
        ['id' => 'n1', 'entry' => $about->id(), 'title' => 'About us', 'data' => ['icon' => 'info', 'tracking' => 'x1'], 'children' => [
            ['id' => 'n2', 'url' => 'https://shop.example.com'],
        ]],
        // Shown with the entry's title and URL, which must not become custom ones.
        ['id' => 'n3', 'entry' => $contact->id()],
    ];
    mainNav($stored);

    $tree = tool($this->editor, GetNavigation::class, ['handle' => 'main'])->data()['tree'];
    tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => $tree])->assertHasNoErrors();

    expect(storedNav())->toBe($stored);
});

it('adds, changes, reorders and removes nodes', function () {
    ['About' => $about, 'Contact' => $contact] = pages('About', 'Contact');
    mainNav([
        ['id' => 'n1', 'entry' => $about->id()],
        ['id' => 'n2', 'title' => 'Old link', 'url' => 'https://old.example.com'],
    ]);

    $data = tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => [
        ['entry' => $contact->id(), 'data' => ['priority' => 3]],
        ['id' => 'n1', 'title' => 'About us', 'children' => [['title' => 'Blog', 'url' => '/blog']]],
    ]])->assertHasNoErrors()->data();

    $stored = storedNav();
    expect($stored[0])->toMatchArray(['entry' => $contact->id(), 'data' => ['priority' => 3]])
        ->and($stored[0]['id'])->toBeString()->not->toBe('n2')
        ->and(array_diff_key($stored[1], ['children' => 1]))->toBe(['id' => 'n1', 'entry' => $about->id(), 'title' => 'About us'])
        ->and($stored[1]['children'][0])->toMatchArray(['title' => 'Blog', 'url' => '/blog'])
        ->and(collect($stored)->pluck('id')->all())->not->toContain('n2')
        ->and($data['tree'][1]['children'][0]['title'])->toBe('Blog');
});

it('validates node data, ids, entries, the allowed collections and the max depth', function (array $tree, string $path, string $message) {
    ['About' => $about] = pages('About');
    entry('blog', ['title' => 'Post'], slug: 'post');
    mainNav([['id' => 'n1', 'entry' => $about->id()]]);
    $tree = json_decode(str_replace(['ABOUT', 'POST'], [$about->id(), Statamic\Facades\Entry::query()->where('collection', 'blog')->first()->id()], json_encode($tree)), true);

    $errors = structureErrors(tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => $tree]));

    expect($errors[$path][0] ?? null)->toContain($message)
        ->and(storedNav())->toBe([['id' => 'n1', 'entry' => $about->id()]]);
})->with([
    'data validation' => [[['id' => 'n1', 'data' => ['priority' => 99]]], 'tree.n1.data.priority', '10'],
    'read-only data' => [[['id' => 'n1', 'data' => ['tracking' => 'new']]], 'tree.n1.data.tracking', 'read-only'],
    'unknown data field' => [[['id' => 'n1', 'data' => ['colour' => 'red']]], 'tree.n1.data.colour', 'Unknown field'],
    'nested new node by index' => [[['id' => 'n1', 'children' => [['url' => '/a', 'data' => ['priority' => 'many']]]]], 'tree.n1.children.0.data.priority', 'integer'],
    'unknown id' => [[['id' => 'nope', 'title' => 'X']], 'tree.nope.id', 'No node with id [nope]'],
    'link without title or url' => [[['data' => []]], 'tree.0.title', 'needs a title or a url'],
    'missing entry' => [[['entry' => 'missing']], 'tree.0.entry', 'Entry [missing] not found.'],
    'entry of another collection' => [[['entry' => 'POST']], 'tree.0.entry', 'these collections only: pages'],
    'too deep' => [[['id' => 'n1', 'children' => [['url' => '/a', 'children' => [['url' => '/b']]]]]], 'tree', 'at most 2 levels'],
]);

it('keeps nodes that link entries the editor may not view', function () {
    Gate::policy(Entry::class, HideSecretEntriesPolicy::class);
    $secret = entry('pages', ['title' => 'Secret'], slug: 'secret');
    mainNav([['id' => 'n1', 'entry' => $secret->id(), 'title' => 'Hidden'], ['id' => 'n2', 'url' => '/a']]);

    $tree = tool($this->editor, GetNavigation::class, ['handle' => 'main'])->data()['tree'];
    $dropped = structureErrors(tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => [$tree[1]]]));
    tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => [$tree[1], ['id' => 'n1', 'title' => 'Changed', 'restricted' => true]]])->assertHasNoErrors();

    expect($tree[0])->toBe(['id' => 'n1', 'restricted' => true, 'children' => []])
        ->and($dropped['tree'][0])->toContain('Keep it in the tree')
        ->and(storedNav())->toBe([['id' => 'n2', 'url' => '/a'], ['id' => 'n1', 'entry' => $secret->id(), 'title' => 'Hidden']]);
});

it('needs the edit permission and reports a cancelled save, and returns the tree as saved', function () {
    mainNav([['id' => 'n1', 'url' => '/a']]);

    tool(editor(['access content mcp', 'view main nav'], 'viewer@example.com'), UpdateNavigation::class, ['handle' => 'main', 'tree' => []])
        ->assertHasErrors(['You may not edit this navigation.']);

    // A site listener that adds a node on every save.
    Event::listen(NavTreeSaving::class, function (NavTreeSaving $event): void {
        $event->tree->tree([...$event->tree->tree(), ['id' => 'legal', 'url' => '/legal']]);
    });
    $saved = tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => []])->assertHasNoErrors()->data();

    expect(collect($saved['tree'])->pluck('id')->all())->toBe(['legal']);

    Event::listen(NavTreeSaving::class, fn () => false);
    tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => []])->assertHasErrors(['The site cancelled this save']);
});

it('describes the navigation blueprint without title and url', function () {
    mainNav([]);

    expect(collect(tool($this->editor, GetBlueprint::class, ['navigation' => 'main'])->data()['fields'])->pluck('handle')->all())->toContain('icon', 'priority');
});

it('moves an entry under a parent, before or after a sibling, or to the top level', function () {
    ['About' => $about, 'Team' => $team, 'Jobs' => $jobs, 'Contact' => $contact] = pages('About', 'Team', 'Jobs', 'Contact');

    tool($this->editor, MoveEntry::class, ['id' => $jobs->id(), 'parent' => $about->id()])->assertHasNoErrors();
    $moved = tool($this->editor, MoveEntry::class, ['id' => $team->id(), 'parent' => $about->id(), 'before' => $jobs->id()])->assertHasNoErrors()->data();
    tool($this->editor, MoveEntry::class, ['id' => $contact->id(), 'before' => $about->id()])->assertHasNoErrors();

    expect(pageTree())->toBe(['Contact' => [], 'About' => ['Team' => [], 'Jobs' => []]])
        ->and($moved)->toMatchArray(['parent' => $about->id(), 'url' => 'http://localhost/about/team']);

    tool($this->editor, MoveEntry::class, ['id' => $jobs->id(), 'after' => $contact->id()])->assertHasNoErrors();

    expect(pageTree())->toBe(['Contact' => [], 'Jobs' => [], 'About' => ['Team' => []]]);
});

it('refuses moves the CP would not allow', function (Closure $arguments, string $key, string $message) {
    $pages = pages('About', 'Team', 'Contact');
    $tree = Collection::findByHandle('pages')->structure()->in('default');
    $tree->move($pages['Team']->id(), $pages['About']->id());
    $tree->save();
    $before = pageTree();

    $errors = structureErrors(tool($this->editor, MoveEntry::class, $arguments($pages)));

    expect($errors[$key][0] ?? null)->toContain($message)
        ->and(pageTree())->toBe($before);
})->with([
    'below itself' => [fn ($p) => ['id' => $p['About']->id(), 'parent' => $p['Team']->id()], 'parent', 'below itself'],
    'sibling of another parent' => [fn ($p) => ['id' => $p['Contact']->id(), 'parent' => $p['About']->id(), 'before' => $p['About']->id()], 'before', 'child of the new parent'],
    'another collection' => [fn ($p) => ['id' => $p['Contact']->id(), 'parent' => entry('blog', ['title' => 'Post'])->id()], 'parent', 'same collection'],
]);

it('respects the max depth, the root page and unique urls of the collection tree', function () {
    $pages = pages('Home', 'About', 'Team');
    Collection::findByHandle('pages')->structureContents(['root' => true, 'max_depth' => 2])->save();
    $tree = Collection::findByHandle('pages')->structure()->in('default');
    $tree->move($pages['Team']->id(), $pages['About']->id());
    $tree->save();
    $twin = entry('pages', ['title' => 'Team twin'], slug: 'team');
    Collection::findByHandle('pages')->structure()->in('default')->append($twin)->save();

    $deep = structureErrors(tool($this->editor, MoveEntry::class, ['id' => $twin->id(), 'parent' => $pages['Team']->id()]));
    $root = structureErrors(tool($this->editor, MoveEntry::class, ['id' => $pages['Home']->id(), 'parent' => $pages['About']->id()]));
    $beforeRoot = structureErrors(tool($this->editor, MoveEntry::class, ['id' => $pages['About']->id(), 'before' => $pages['Home']->id()]));
    $underRoot = structureErrors(tool($this->editor, MoveEntry::class, ['id' => $pages['About']->id(), 'parent' => $pages['Home']->id()]));
    $duplicate = structureErrors(tool($this->editor, MoveEntry::class, ['id' => $twin->id(), 'parent' => $pages['About']->id()]));

    expect($deep['parent'][0])->toContain('at most 2 levels')
        ->and($root['id'][0])->toContain('root page cannot be moved')
        ->and($beforeRoot['before'][0])->toContain('before the root page')
        ->and($underRoot['parent'][0])->toContain('root page cannot have children')
        ->and($duplicate['parent'][0])->toContain('/about/team');
});

it('reorders a flat collection', function () {
    Collection::findByHandle('pages')->structureContents(['max_depth' => 1])->save();
    ['One' => $one, 'Two' => $two, 'Three' => $three] = pages('One', 'Two', 'Three');

    tool($this->editor, MoveEntry::class, ['id' => $three->id(), 'before' => $one->id()])->assertHasNoErrors();
    $nested = structureErrors(tool($this->editor, MoveEntry::class, ['id' => $two->id(), 'parent' => $one->id()]));

    expect(pageTree())->toBe(['Three' => [], 'One' => [], 'Two' => []])
        ->and($nested['parent'][0])->toContain('at most 1 levels');
});

it('needs the reorder permission, hides entries a policy denies and reports a cancelled save', function () {
    Gate::policy(Entry::class, HideSecretEntriesPolicy::class);
    $pages = pages('About', 'Contact');
    $secret = entry('pages', ['title' => 'Secret'], slug: 'secret');
    Collection::findByHandle('pages')->structure()->in('default')->append($secret)->save();

    tool(editor(['access content mcp', 'view pages entries', 'edit pages entries'], 'writer@example.com'), MoveEntry::class, ['id' => $pages['Contact']->id(), 'before' => $pages['About']->id()])
        ->assertHasErrors(['You may not reorder entries in [pages].']);
    tool($this->editor, MoveEntry::class, ['id' => $pages['Contact']->id(), 'parent' => $secret->id()])
        ->assertHasErrors(["Entry [{$secret->id()}] not found."]);

    Event::listen(CollectionTreeSaving::class, fn () => false);
    tool($this->editor, MoveEntry::class, ['id' => $pages['Contact']->id(), 'before' => $pages['About']->id()])->assertHasErrors(['The site cancelled this save']);

    expect(array_keys(pageTree()))->toBe(['About', 'Contact', 'Secret']);
});
