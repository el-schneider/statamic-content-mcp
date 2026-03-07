<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

#[IsReadOnly]
final class AssetTool extends Tool
{
    protected string $name = 'assets';

    protected string $description = 'List asset containers, list assets within a container, and get asset details.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->enum(['list_containers', 'list', 'get'])
                ->description('Action to perform.'),
            'container' => $schema->string()
                ->description('Container handle (required for list).'),
            'folder' => $schema->string()
                ->description('Folder path within container (for list, default: /).'),
            'id' => $schema->string()
                ->description('Asset ID in format container::path (for get).'),
            'limit' => $schema->integer()
                ->description('Results per page (default: 20, max: 100).'),
            'offset' => $schema->integer()
                ->description('Pagination offset.'),
        ];
    }

    public function shouldRegister(): bool
    {
        return (bool) config('statamic.content-mcp.tools.assets', true);
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'action' => ['required', 'string', 'in:list_containers,list,get'],
        ]);

        return match ($request->get('action')) {
            'list_containers' => $this->listContainers(),
            'list' => $this->list($request),
            'get' => $this->get($request),
            default => Response::error('Unknown action.'),
        };
    }

    private function listContainers(): Response
    {
        $containers = AssetContainer::all()->map(fn ($container) => [
            'handle' => $container->handle(),
            'title' => $container->title(),
            'disk' => $container->diskHandle(),
        ])->values()->all();

        return Response::json([
            'data' => $containers,
        ]);
    }

    private function list(Request $request): Response
    {
        $request->validate([
            'container' => ['required', 'string'],
        ]);

        $handle = $request->get('container');
        $container = AssetContainer::findByHandle($handle);

        if (! $container) {
            return Response::error("Asset container [{$handle}] not found.");
        }

        $folder = $request->get('folder');
        $limit = min(
            (int) ($request->get('limit') ?? config('statamic.content-mcp.pagination.default_limit', 20)),
            (int) config('statamic.content-mcp.pagination.max_limit', 100)
        );
        $offset = (int) ($request->get('offset') ?? 0);

        $query = $container->queryAssets();

        if ($folder !== null) {
            $query->where('folder', mb_ltrim($folder, '/'));
        }

        $assets = $query->get();
        $total = $assets->count();
        $paged = $assets->slice($offset, $limit)->values();

        return Response::json([
            'data' => $paged->map(fn ($asset) => $this->formatAssetForList($asset))->all(),
            'total' => $total,
            'has_more' => ($offset + $limit) < $total,
        ]);
    }

    private function get(Request $request): Response
    {
        $request->validate([
            'id' => ['required', 'string'],
        ]);

        $id = $request->get('id');
        $asset = Asset::find($id);

        if (! $asset) {
            return Response::error("Asset [{$id}] not found.");
        }

        return Response::json($this->formatAssetForGet($asset));
    }

    /** @return array<string, mixed> */
    private function formatAssetForList(\Statamic\Contracts\Assets\Asset $asset): array
    {
        return [
            'id' => $asset->id(),
            'path' => $asset->path(),
            'url' => $asset->url(),
            'extension' => $asset->extension(),
            'size' => $asset->size(),
            'last_modified' => $asset->lastModified()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatAssetForGet(\Statamic\Contracts\Assets\Asset $asset): array
    {
        $data = $this->formatAssetForList($asset);
        $data['alt'] = $asset->get('alt');
        $data['data'] = $asset->data()->all();

        return $data;
    }
}
