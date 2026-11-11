<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\GetAsset;
use ElSchneider\ContentMcp\Tools\GetEntry;
use ElSchneider\ContentMcp\Tools\GetGlobal;
use ElSchneider\ContentMcp\Tools\GetNavigation;
use ElSchneider\ContentMcp\Tools\GetTerm;
use ElSchneider\ContentMcp\Tools\UpdateAsset;
use ElSchneider\ContentMcp\Tools\UpdateEntry;
use ElSchneider\ContentMcp\Tools\UpdateGlobal;
use ElSchneider\ContentMcp\Tools\UpdateNavigation;
use ElSchneider\ContentMcp\Tools\UpdateTerm;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Statamic\Events\AssetSaving;
use Statamic\Events\EntrySaving;
use Statamic\Events\GlobalVariablesSaving;
use Statamic\Events\NavTreeSaving;
use Statamic\Events\TermSaving;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Nav;
use Statamic\Facades\Term;

/*
 * Regression: sending back what a get_* tool returned rewrote the file and fired the
 * save events (static cache, Git commits) although nothing changed.
 */

beforeEach(function () {
    siteContent();
    $this->editor = editor(['access content mcp', 'view blog entries', 'edit blog entries', 'edit footer globals', 'view tags terms', 'edit tags terms', 'view assets assets', 'edit assets assets', 'view main nav', 'edit main nav']);
    $this->saves = 0;
});

function countSaves(string $event): void
{
    Event::listen($event, function () {
        test()->saves++;
    });
}

it('does not save an entry when nothing changes', function () {
    $entry = entry('blog', ['title' => 'Same', 'rating' => 3]);
    $data = tool($this->editor, GetEntry::class, ['id' => $entry->id()])->data()['data'];
    countSaves(EntrySaving::class);

    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => $data])->assertHasNoErrors();
    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => [], 'slug' => 'same'])->assertHasNoErrors();

    expect($this->saves)->toBe(0);

    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => ['rating' => 4]])->assertHasNoErrors();

    expect($this->saves)->toBe(1);
});

it('does not save a global set, a term, an asset or a navigation when nothing changes', function () {
    GlobalSet::findByHandle('footer')->inDefaultSite()->data(['copyright' => '© Same'])->save();
    Term::make('same')->taxonomy('tags')->data(['title' => 'Same'])->save();
    Storage::fake('assets');
    AssetContainer::make('assets')->disk('assets')->save();
    Storage::disk('assets')->put('same.txt', 'x');
    Statamic\Facades\Asset::find('assets::same.txt')->set('alt', 'Same')->save();
    $nav = Nav::make('main')->title('Main');
    $nav->save();
    $nav->makeTree('default', [['id' => 'n1', 'url' => '/a', 'title' => 'A']])->save();
    foreach ([GlobalVariablesSaving::class, TermSaving::class, AssetSaving::class, NavTreeSaving::class] as $event) {
        countSaves($event);
    }

    tool($this->editor, UpdateGlobal::class, ['handle' => 'footer', 'data' => tool($this->editor, GetGlobal::class, ['handle' => 'footer'])->data()['data']])->assertHasNoErrors();
    tool($this->editor, UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'same', 'data' => tool($this->editor, GetTerm::class, ['taxonomy' => 'tags', 'slug' => 'same'])->data()['data']])->assertHasNoErrors();
    tool($this->editor, UpdateAsset::class, ['id' => 'assets::same.txt', 'data' => tool($this->editor, GetAsset::class, ['id' => 'assets::same.txt'])->data()['data']])->assertHasNoErrors();
    tool($this->editor, UpdateNavigation::class, ['handle' => 'main', 'tree' => tool($this->editor, GetNavigation::class, ['handle' => 'main'])->data()['tree']])->assertHasNoErrors();

    expect($this->saves)->toBe(0);
});
