<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\EntryPresenter;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Site;

#[Title('Create draft entry')]
#[Description('Creates a new entry as an unpublished draft. `data` maps field handles from get_blueprint to values in the shape described there. Returns the entry with `edit_url` and `preview_url`; show the preview link to the editor. Validation errors come back per field so you can correct them and retry.')]
#[IsDestructive(false)]
#[IsIdempotent(false)]
final class CreateEntry extends ContentTool
{
    protected string $name = 'create_entry';

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle, from get_overview.')->required(),
            'blueprint' => $schema->string()->description('Blueprint handle. Defaults to the collection\'s default blueprint.'),
            'data' => $schema->object()->description('Field values keyed by field handle, e.g. {"title": "…", "content": "<p>…</p>"}.')->required(),
            'slug' => $schema->string()->description('URL slug. Generated from the title when omitted.'),
            'date' => $schema->string()->description('Entry date for dated collections, e.g. "2025-01-31". Defaults to now.'),
            'parent' => $schema->string()->description('Structured collections only: id of the parent entry. Defaults to the top level.'),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'collection' => ['required', 'string'],
            'blueprint' => ['nullable', 'string'],
            'data' => ['required', 'array'],
            'slug' => ['nullable', 'string'],
            'date' => ['nullable', 'string'],
            'parent' => ['nullable', 'string'],
        ]);

        $collection = Find::collection($request->string('collection')->toString());
        $site = Site::default();

        if (Gate::denies('create', [EntryContract::class, $collection, $site])) {
            return Response::error("You may not create entries in [{$collection->handle()}].");
        }

        $handle = $request->filled('blueprint') ? $request->string('blueprint')->toString() : null;
        $blueprint = $collection->entryBlueprint($handle) ?? throw new NotFound("Blueprint [{$handle}] not found.");

        /** @var array<string, mixed> $data */
        $data = [...$request->array('data'), ...array_filter($request->only(['slug', 'date']))];
        // A parent the editor may not view is reported like a missing one.
        $parent = $request->filled('parent') ? Find::entry($request->string('parent')->toString())->id() : null;

        $entry = ContentWriter::createEntry($this->user(), $collection, $blueprint, $site, $data, $parent);

        return Response::structured(EntryPresenter::meta($entry, withPreview: true));
    }
}
