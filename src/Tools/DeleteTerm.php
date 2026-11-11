<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotSaved;
use ElSchneider\ContentMcp\Settings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Title('Delete taxonomy term')]
#[Description('Deletes a taxonomy term for good, in every site; there is no trash. Entries that reference it keep its slug. Only call this when the editor explicitly asks to delete.')]
#[IsDestructive]
#[IsIdempotent]
final class DeleteTerm extends ContentTool
{
    protected string $name = 'delete_term';

    public function shouldRegister(): bool
    {
        return Settings::deletes();
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'taxonomy' => $schema->string()->description('Taxonomy handle, from get_overview.')->required(),
            'slug' => $schema->string()->description('Term slug.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['taxonomy' => ['required', 'string'], 'slug' => ['required', 'string']]);

        $term = Find::term($request->string('taxonomy')->toString(), $request->string('slug')->toString(), Find::site(null));

        if (Gate::denies('delete', $term)) {
            return Response::error('You may not delete this term.');
        }

        NotSaved::unless($term->delete(), deletion: true);

        return Response::structured(['deleted' => $term->id()]);
    }
}
