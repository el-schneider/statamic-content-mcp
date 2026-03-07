<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use ElSchneider\ContentMcp\Tools\TaxonomyTool;
use Statamic\Facades\Taxonomy;
use Statamic\Facades\Term;

beforeEach(function () {
    Taxonomy::make('tags')->title('Tags')->save();
    Taxonomy::make('categories')->title('Categories')->save();

    Term::make()->taxonomy('tags')->slug('tutorials')->data(['title' => 'Tutorials'])->save();
    Term::make()->taxonomy('tags')->slug('news')->data(['title' => 'News'])->save();
});

it('lists taxonomies', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'list',
    ])
        ->assertOk()
        ->assertSee('Tags')
        ->assertSee('tags')
        ->assertSee('Categories')
        ->assertSee('categories')
        ->assertSee('term_count');
});

it('gets terms from a taxonomy', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'get_terms',
        'taxonomy' => 'tags',
    ])
        ->assertOk()
        ->assertSee('Tutorials')
        ->assertSee('tutorials')
        ->assertSee('News')
        ->assertSee('news');
});

it('paginates terms', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'get_terms',
        'taxonomy' => 'tags',
        'limit' => 1,
    ])
        ->assertOk()
        ->assertSee('has_more');
});

it('creates a term', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'create_term',
        'taxonomy' => 'tags',
        'data' => ['title' => 'Reviews'],
    ])
        ->assertOk()
        ->assertSee('created')
        ->assertSee('reviews')
        ->assertSee('Reviews');

    $term = Term::find('tags::reviews');
    expect($term)->not->toBeNull()
        ->and($term->get('title'))->toBe('Reviews');
});

it('creates a term with explicit slug', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'create_term',
        'taxonomy' => 'tags',
        'slug' => 'custom-slug',
        'data' => ['title' => 'Custom Term'],
    ])
        ->assertOk()
        ->assertSee('custom-slug')
        ->assertSee('Custom Term');

    $term = Term::find('tags::custom-slug');
    expect($term)->not->toBeNull();
});

it('updates a term', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'update_term',
        'taxonomy' => 'tags',
        'slug' => 'tutorials',
        'data' => ['title' => 'Updated Tutorials'],
    ])
        ->assertOk()
        ->assertSee('updated')
        ->assertSee('Updated Tutorials');

    $term = Term::find('tags::tutorials');
    expect($term->get('title'))->toBe('Updated Tutorials');
});

it('deletes a term', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'delete_term',
        'taxonomy' => 'tags',
        'slug' => 'news',
    ])
        ->assertOk()
        ->assertSee('deleted')
        ->assertSee('News');

    expect(Term::find('tags::news'))->toBeNull();
});

it('returns error for unknown taxonomy on get_terms', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'get_terms',
        'taxonomy' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('returns error for unknown taxonomy on create_term', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'create_term',
        'taxonomy' => 'nonexistent',
        'data' => ['title' => 'Test'],
    ])
        ->assertHasErrors();
});

it('returns error for unknown term on update_term', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'update_term',
        'taxonomy' => 'tags',
        'slug' => 'nonexistent',
        'data' => ['title' => 'Nope'],
    ])
        ->assertHasErrors();
});

it('returns error for unknown term on delete_term', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'delete_term',
        'taxonomy' => 'tags',
        'slug' => 'nonexistent',
    ])
        ->assertHasErrors();
});

it('rejects unknown actions', function () {
    ContentMcpServer::tool(TaxonomyTool::class, [
        'action' => 'invalid',
    ])
        ->assertHasErrors();
});

it('is registered when taxonomies tool config is enabled', function () {
    config(['statamic.content-mcp.tools.taxonomies' => true]);

    expect(app(TaxonomyTool::class)->eligibleForRegistration())->toBeTrue();
});

it('is not registered when taxonomies tool config is disabled', function () {
    config(['statamic.content-mcp.tools.taxonomies' => false]);

    expect(app(TaxonomyTool::class)->eligibleForRegistration())->toBeFalse();
});
