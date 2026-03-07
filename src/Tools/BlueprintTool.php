<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Support\BlueprintDescriber;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Collection;
use Statamic\Facades\GlobalSet;
use Statamic\Facades\Taxonomy;
use Statamic\Fields\Blueprint;

#[IsReadOnly]
final class BlueprintTool extends Tool
{
    protected string $name = 'blueprints';

    protected string $description = 'Describe the fields of a blueprint for a collection, taxonomy, global set, or asset container.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['describe'])
                ->default('describe')
                ->description('Action to perform.'),
            'collection' => $schema->string()
                ->description('Collection handle.'),
            'taxonomy' => $schema->string()
                ->description('Taxonomy handle.'),
            'global' => $schema->string()
                ->description('Global set handle.'),
            'asset_container' => $schema->string()
                ->description('Asset container handle.'),
            'blueprint' => $schema->string()
                ->description('Blueprint handle (when collection has multiple blueprints).'),
        ];
    }

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.tools.blueprints', true);
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'action' => ['sometimes', 'string', 'in:describe'],
        ]);

        return match (true) {
            $request->get('collection') !== null => $this->describeCollection($request),
            $request->get('taxonomy') !== null => $this->describeTaxonomy($request),
            $request->get('global') !== null => $this->describeGlobal($request),
            $request->get('asset_container') !== null => $this->describeAssetContainer($request),
            default => Response::error('One of collection, taxonomy, global, or asset_container is required.'),
        };
    }

    private function describeCollection(Request $request): Response
    {
        $handle = $request->get('collection');

        $allowed = config('statamic.content-mcp.collections');

        if ($allowed !== null && ! in_array($handle, $allowed, strict: true)) {
            return Response::error("Collection [{$handle}] is not accessible.");
        }

        $collection = Collection::findByHandle($handle);

        if (! $collection) {
            return Response::error("Collection [{$handle}] not found.");
        }

        $blueprintHandle = $request->get('blueprint') ?? $collection->entryBlueprints()->first()?->handle();
        $blueprint = $collection->entryBlueprint($blueprintHandle);

        if (! $blueprint) {
            return Response::error("Blueprint not found for collection [{$handle}].");
        }

        $data = [
            'collection' => $handle,
            'blueprint' => $blueprint->handle(),
            'fields' => BlueprintDescriber::describe($blueprint),
        ];

        if ($collection->dated()) {
            $data['dated'] = true;
        }

        if ($collection->hasStructure()) {
            $data['structured'] = true;
        }

        if (! config('statamic.content-mcp.default_published', false)) {
            $data['notes'] = 'Entries are created as drafts by default. Use entries.publish to make them live.';
        }

        return Response::json($data);
    }

    private function describeTaxonomy(Request $request): Response
    {
        $handle = $request->get('taxonomy');

        $taxonomy = Taxonomy::findByHandle($handle);

        if (! $taxonomy) {
            return Response::error("Taxonomy [{$handle}] not found.");
        }

        $blueprint = $taxonomy->termBlueprint();

        if (! $blueprint instanceof Blueprint) {
            return Response::error("Blueprint not found for taxonomy [{$handle}].");
        }

        return Response::json([
            'taxonomy' => $handle,
            'blueprint' => $blueprint->handle(),
            'fields' => BlueprintDescriber::describe($blueprint),
        ]);
    }

    private function describeGlobal(Request $request): Response
    {
        $handle = $request->get('global');

        $globalSet = GlobalSet::findByHandle($handle);

        if (! $globalSet) {
            return Response::error("Global set [{$handle}] not found.");
        }

        $blueprint = $globalSet->blueprint();

        if (! $blueprint instanceof Blueprint) {
            return Response::error("Blueprint not found for global set [{$handle}].");
        }

        return Response::json([
            'global' => $handle,
            'blueprint' => $blueprint->handle(),
            'fields' => BlueprintDescriber::describe($blueprint),
        ]);
    }

    private function describeAssetContainer(Request $request): Response
    {
        $handle = $request->get('asset_container');

        $container = AssetContainer::findByHandle($handle);

        if (! $container) {
            return Response::error("Asset container [{$handle}] not found.");
        }

        $blueprint = $container->blueprint();

        if (! $blueprint instanceof Blueprint) {
            return Response::error("Blueprint not found for asset container [{$handle}].");
        }

        return Response::json([
            'asset_container' => $handle,
            'blueprint' => $blueprint->handle(),
            'fields' => BlueprintDescriber::describe($blueprint),
        ]);
    }
}
