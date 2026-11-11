<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Tools\CreateEntry;
use ElSchneider\ContentMcp\Tools\CreateTerm;
use ElSchneider\ContentMcp\Tools\UpdateEntry;
use ElSchneider\ContentMcp\Tools\UpdateTerm;
use Illuminate\Support\Facades\Gate;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Contracts\Taxonomies\Term as TermContract;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as Entries;
use Statamic\Facades\Term;
use Statamic\Taxonomies\LocalizedTerm;
use Tests\Fixtures\OpenFormsOnlyEntryPolicy;
use Tests\Fixtures\OpenFormsOnlyTermPolicy;

/*
 * The CP authorizes saving with `store` and `update`, not with `create` and `edit`
 * (EntriesController::store()/update(), TermsController::store()/update()). A site
 * policy that only opens the forms must not let anything be saved.
 */

beforeEach(function () {
    siteContent();
    // Class-string checks (`store`) resolve the contract's policy, object checks the concrete class's.
    Gate::policy(EntryContract::class, OpenFormsOnlyEntryPolicy::class);
    Gate::policy(Entry::class, OpenFormsOnlyEntryPolicy::class);
    Gate::policy(TermContract::class, OpenFormsOnlyTermPolicy::class);
    Gate::policy(LocalizedTerm::class, OpenFormsOnlyTermPolicy::class);
    $this->editor = editor(['access content mcp', 'view blog entries', 'create blog entries', 'edit blog entries', 'view tags terms', 'create tags terms', 'edit tags terms']);
});

it('refuses to create an entry the site may not store', function () {
    tool($this->editor, CreateEntry::class, ['collection' => 'blog', 'data' => ['title' => 'New']])
        ->assertHasErrors(['You may not create entries in [blog].']);

    expect(Entries::query()->where('collection', 'blog')->count())->toBe(0);
});

it('refuses to change an entry the site may not update', function () {
    $entry = entry('blog', ['title' => 'Before']);

    tool($this->editor, UpdateEntry::class, ['id' => $entry->id(), 'data' => ['title' => 'After']])
        ->assertHasErrors(['You may not edit this entry.']);

    expect(Entries::find($entry->id())->get('title'))->toBe('Before');
});

it('refuses to create a term the site may not store', function () {
    tool($this->editor, CreateTerm::class, ['taxonomy' => 'tags', 'data' => ['title' => 'New']])
        ->assertHasErrors(['You may not create terms in [tags].']);

    expect(Term::find('tags::new'))->toBeNull();
});

it('refuses to change a term the site may not update', function () {
    Term::make('garden')->taxonomy('tags')->data(['title' => 'Garden'])->save();

    tool($this->editor, UpdateTerm::class, ['taxonomy' => 'tags', 'slug' => 'garden', 'data' => ['title' => 'Changed']])
        ->assertHasErrors(['You may not edit this term.']);

    expect(Term::find('tags::garden')->get('title'))->toBe('Garden');
});
