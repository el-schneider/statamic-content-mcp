<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\Trees;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Title('Get navigation')]
#[Description('Returns the tree of a navigation (a menu): nodes with `id`, `title`, `url`, `entry` (the id of the linked entry, or null for a plain link), `data` (extra fields from get_blueprint with `navigation`) and `children`. A node marked `restricted` links an entry you may not view; keep it as it is. update_navigation takes the same shape.')]
#[IsReadOnly]
final class GetNavigation extends ContentTool
{
    protected string $name = 'get_navigation';

    public function schema(JsonSchema $schema): array
    {
        return [
            'handle' => $schema->string()->description('Navigation handle, from get_overview.')->required(),
            'site' => self::siteArgument($schema),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate(['handle' => ['required', 'string'], 'site' => ['nullable', 'string']]);

        $tree = Find::navigationTree($request->string('handle')->toString(), $this->site($request));
        $nav = $tree->structure();

        return Response::structured([
            'handle' => $nav->handle(),
            'title' => $nav->title(),
            'max_depth' => $nav->maxDepth(),
            'collections' => $nav->collections()->map->handle()->values()->all(),
            'tree' => Trees::navigation($tree),
        ]);
    }
}
