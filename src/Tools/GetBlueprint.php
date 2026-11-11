<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\BlueprintDescription;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Title('Get blueprint')]
#[Description('Returns the fields of an entry blueprint (pass `collection`, optionally `blueprint`) or of a global set (pass `global`). Read this before writing: values must match each field\'s `value` hint, `options`, `sets` and validation rules.')]
#[IsReadOnly]
final class GetBlueprint extends ContentTool
{
    protected string $name = 'get_blueprint';

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle, from get_overview.'),
            'blueprint' => $schema->string()->description('Blueprint handle within the collection. Defaults to the collection\'s default blueprint.'),
            'global' => $schema->string()->description('Global set handle, instead of a collection.'),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate([
            'collection' => ['required_without:global', 'prohibits:global', 'string'],
            'blueprint' => ['nullable', 'string'],
            'global' => ['required_without:collection', 'string'],
        ]);

        if ($request->filled('global')) {
            $blueprint = Find::globals($request->string('global')->toString(), Find::site(null))->blueprint();
        } else {
            $collection = Find::collection($request->string('collection')->toString());
            $handle = $request->filled('blueprint') ? $request->string('blueprint')->toString() : null;
            $blueprint = $collection->entryBlueprint($handle) ?? throw new NotFound("Blueprint [{$handle}] not found.");
        }

        return Response::structured([
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'fields' => BlueprintDescription::fields($blueprint->fields()),
        ]);
    }
}
