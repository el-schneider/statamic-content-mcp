<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\BlueprintDescription;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Fields\Blueprint;

#[Title('Get blueprint')]
#[Description('Returns the fields of a blueprint. Pass exactly one of `collection` (optionally with `blueprint`), `taxonomy` (optionally with `blueprint`), `global`, `navigation` (the fields of its pages) or `asset_container` (the fields of its assets, e.g. alt text). Read this before writing: values must match each field\'s `value` hint, `options`, `sets` and validation rules.')]
#[IsReadOnly]
final class GetBlueprint extends ContentTool
{
    private const array SOURCES = ['collection', 'taxonomy', 'global', 'navigation', 'asset_container'];

    protected string $name = 'get_blueprint';

    public function schema(JsonSchema $schema): array
    {
        return [
            'collection' => $schema->string()->description('Collection handle, from get_overview.'),
            'taxonomy' => $schema->string()->description('Taxonomy handle, from get_overview.'),
            'blueprint' => $schema->string()->description('Blueprint handle within the collection or taxonomy. Defaults to its default blueprint.'),
            'global' => $schema->string()->description('Global set handle.'),
            'navigation' => $schema->string()->description('Navigation handle.'),
            'asset_container' => $schema->string()->description('Asset container handle.'),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate([
            ...array_fill_keys(self::SOURCES, ['nullable', 'string']),
            'blueprint' => ['nullable', 'string'],
        ]);

        $given = array_values(array_filter(self::SOURCES, fn (string $source): bool => $request->filled($source)));

        if (count($given) !== 1) {
            throw ValidationException::withMessages(['collection' => ['Pass exactly one of: '.implode(', ', self::SOURCES).'.']]);
        }

        $source = $given[0];
        $value = $request->string($source)->toString();
        $handle = $request->filled('blueprint') ? $request->string('blueprint')->toString() : null;

        $blueprint = match ($source) {
            'collection' => Find::collection($value)->entryBlueprint($handle),
            'taxonomy' => Find::taxonomy($value)->termBlueprint($handle),
            // Variables fall back to a default blueprint when the set has none.
            'global' => Find::globalSet($value)->makeLocalization(Find::site(null)->handle())->blueprint(),
            'navigation' => Find::navigation($value)->blueprint(),
            'asset_container' => Find::assetContainer($value)->blueprint(),
        };

        if (! $blueprint instanceof Blueprint) {
            throw new NotFound("Blueprint [{$handle}] not found.");
        }

        return Response::structured([
            'handle' => $blueprint->handle(),
            'title' => $blueprint->title(),
            'fields' => BlueprintDescription::fields($blueprint->fields()),
        ]);
    }
}
