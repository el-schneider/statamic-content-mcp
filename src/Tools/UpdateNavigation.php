<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\Trees;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Title('Update navigation')]
#[Description(<<<'TEXT'
    Replaces the tree of a navigation (a menu) in one site; changes are live at once. Send the whole tree in the shape get_navigation returns: nodes with an `id` keep and update that node, nodes without `id` are added, nodes you leave out are removed. A node links an entry (`entry`: its id; the title defaults to the entry's) or is a plain link (`title` and/or `url`). `data` holds the extra fields of the navigation's blueprint.
    Returns the tree as the site saved it.
    TEXT)]
#[IsDestructive]
#[IsIdempotent]
final class UpdateNavigation extends ContentTool
{
    protected string $name = 'update_navigation';

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Navigation handle, from get_overview.')->required(),
            'site' => self::siteArgument($schema),
            'tree' => $schema->array()->description('The complete list of top-level nodes, each with optional `children`.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['handle' => ['required', 'string'], 'site' => ['nullable', 'string'], 'tree' => ['present', 'array']]);

        $handle = $request->string('handle')->toString();
        $site = $this->site($request);
        $tree = Find::navigationTree($handle, $site);

        if (Gate::denies('edit', $tree)) {
            return Response::error('You may not edit this navigation.');
        }

        /** @var list<mixed> $nodes */
        $nodes = array_values($request->array('tree'));
        Trees::updateNavigation($tree, $nodes);

        // Read back: a site listener may have changed the tree while it was saved.
        return Response::structured(['handle' => $handle, 'tree' => Trees::navigation(Find::navigationTree($handle, $site))]);
    }
}
