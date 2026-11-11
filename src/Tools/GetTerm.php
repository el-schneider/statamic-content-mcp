<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\TermPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Title('Get taxonomy term')]
#[Description('Returns one taxonomy term: its title, url, edit_url and `data`, the field values in the same shape update_term accepts.')]
#[IsReadOnly]
final class GetTerm extends ContentTool
{
    protected string $name = 'get_term';

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, from get_overview.')->required(),
            'slug' => $schema->string()->description('Term slug, from list_terms.')->required(),
            'site' => self::siteArgument($schema),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate(['taxonomy' => ['required', 'string'], 'slug' => ['required', 'string'], 'site' => ['nullable', 'string']]);

        $term = Find::term($request->string('taxonomy')->toString(), $request->string('slug')->toString(), $this->site($request));

        return Response::structured(TermPresenter::present($term));
    }
}
