<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy as TaxonomyModel;

#[Title('List taxonomy terms')]
#[Description('Lists the terms of a taxonomy (e.g. tags or categories) with slug and title. Terms fields store term slugs. New terms cannot be created with these tools.')]
#[IsReadOnly]
final class ListTerms extends ContentTool
{
    protected string $name = 'list_terms';

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, from get_overview.')->required(),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate(['taxonomy' => ['required', 'string']]);

        $handle = $request->string('taxonomy')->toString();
        $taxonomy = Taxonomy::findByHandle($handle);

        if (! $taxonomy instanceof TaxonomyModel || Gate::denies('view', $taxonomy)) {
            throw new NotFound("Taxonomy [{$handle}] not found.");
        }

        return Response::structured([
            'terms' => $taxonomy->queryTerms()
                ->where('site', Site::default()->handle())
                ->orderBy('title')
                ->get()
                ->filter(fn (LocalizedTerm $term): bool => Gate::allows('view', $term))
                ->map(fn (LocalizedTerm $term): array => ['slug' => $term->slug(), 'title' => $term->title()])
                ->values()->all(),
        ]);
    }
}
