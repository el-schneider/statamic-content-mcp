<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\EntryPresenter;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\Trees;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Title('Move entry')]
#[Description('Moves an entry within the page tree of a structured collection (see `structured` in get_overview), like dragging it in the Control Panel. `parent` is the new parent entry, or null for the top level. `before` or `after` names a sibling under that parent; without them the entry goes to the end. Child pages move with it, and URLs that depend on the tree change. The change is live at once.')]
#[IsDestructive]
#[IsIdempotent]
final class MoveEntry extends ContentTool
{
    protected string $name = 'move_entry';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Entry id.')->required(),
            'parent' => $schema->string()->nullable()->description('Id of the new parent entry; null or omitted for the top level.'),
            'before' => $schema->string()->description('Id of the sibling to place the entry before.'),
            'after' => $schema->string()->description('Id of the sibling to place the entry after.'),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'id' => ['required', 'string'],
            'parent' => ['nullable', 'string'],
            'before' => ['nullable', 'string', 'prohibits:after'],
            'after' => ['nullable', 'string'],
        ]);

        $entry = Find::entry($request->string('id')->toString());

        // The CP's CollectionTreeController authorizes reordering on the collection.
        if (Gate::denies('reorder', $entry->collection())) {
            return Response::error("You may not reorder entries in [{$entry->collectionHandle()}].");
        }

        $related = fn (string $key) => $request->filled($key) ? Find::entry($request->string($key)->toString()) : null;
        [$parent, $before, $after] = [$related('parent'), $related('before'), $related('after')];

        foreach (['parent' => $parent, 'before' => $before, 'after' => $after] as $key => $other) {
            if ($other !== null && ($other->collectionHandle() !== $entry->collectionHandle() || $other->locale() !== $entry->locale())) {
                throw ValidationException::withMessages([$key => ['Must be an entry of the same collection and site.']]);
            }
        }

        Trees::moveEntry($entry, $parent, $before, $after);

        return Response::structured(EntryPresenter::meta(Find::entry($entry->id()), withPreview: false));
    }
}
