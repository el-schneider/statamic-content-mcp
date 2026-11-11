<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Taxonomies\LocalizedTerm;

#[Title('List taxonomy terms')]
#[Description('Lists the terms of a taxonomy (e.g. tags or categories) with slug and title, ordered by title. `query` matches title or slug. `has_more` tells whether another page follows. Terms fields store term slugs.')]
#[IsReadOnly]
final class ListTerms extends ContentTool
{
    protected string $name = 'list_terms';

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, from get_overview.')->required(),
            'site' => self::siteArgument($schema),
            'query' => $schema->string()->description('Text to match in the title or slug.'),
            'limit' => $schema->integer()->min(1)->max(500)->default(100),
            'offset' => $schema->integer()->min(0)->default(0),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate([
            'taxonomy' => ['required', 'string'],
            'site' => ['nullable', 'string'],
            'query' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $query = Find::taxonomy($request->string('taxonomy')->toString())->queryTerms()->where('site', $this->site($request)->handle())->orderBy('title');

        if ($request->filled('query')) {
            $like = '%'.addcslashes($request->string('query')->toString(), '%_\\').'%';
            $query->where(fn ($query) => $query->where('title', 'like', $like)->orWhere('slug', 'like', $like));
        }

        $limit = $request->integer('limit', 100);

        // The policy needs the term, so terms are loaded in chunks until the page and one more are found.
        $page = $query->lazy(200)
            ->filter(fn (LocalizedTerm $term): bool => Gate::allows('view', $term))
            ->skip($request->integer('offset'))
            ->take($limit + 1)
            ->map(fn (LocalizedTerm $term): array => ['slug' => $term->slug(), 'title' => $term->title()])
            ->values()
            ->all();

        return Response::structured([
            'terms' => array_slice($page, 0, $limit),
            'has_more' => count($page) > $limit,
        ]);
    }
}
