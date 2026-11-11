<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\NotFound;
use Generator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Statamic\Assets\Asset;
use Statamic\Assets\AssetContainer as Container;
use Statamic\Facades\AssetContainer;

#[Title('Find assets')]
#[Description('Lists or searches files (images, documents) by filename or alt text. Returns each asset\'s `id` (container::path), `path`, url, alt text, mime type, size in bytes and image dimensions, ordered by path. `has_more` tells whether another page follows. Assets fields store the `path`.')]
#[IsReadOnly]
final class FindAssets extends ContentTool
{
    protected string $name = 'find_assets';

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle, from get_overview.'),
            'folder' => $schema->string()->description('Only assets in this folder (path relative to the container) and its subfolders.'),
            'query' => $schema->string()->description('Text to match in the filename or alt text.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(20),
            'offset' => $schema->integer()->min(0)->default(0),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate([
            'container' => ['nullable', 'string'],
            'folder' => ['nullable', 'string'],
            'query' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $containers = AssetContainer::all()->filter(fn (Container $container): bool => Gate::allows('view', $container));

        if ($request->filled('container')) {
            $handle = $request->string('container')->toString();
            $containers = $containers->filter(fn (Container $container): bool => $container->handle() === $handle);

            if ($containers->isEmpty()) {
                throw new NotFound("Asset container [{$handle}] not found.");
            }
        }

        $folder = $request->filled('folder') ? trim($request->string('folder')->toString(), '/') : null;
        $like = $request->filled('query') ? '%'.addcslashes($request->string('query')->toString(), '%_\\').'%' : null;
        $limit = $request->integer('limit', 20);

        // The policy needs the asset, so assets are loaded in chunks until the page and one more are found.
        $page = LazyCollection::make(fn (): Generator => self::assets($containers->whereInstanceOf(Container::class)->values()->all(), $folder, $like))
            ->filter(fn (Asset $asset): bool => Gate::allows('view', $asset))
            ->skip($request->integer('offset'))
            ->take($limit + 1)
            ->collect();

        return Response::structured([
            'has_more' => $page->count() > $limit,
            'assets' => $page
                ->take($limit)
                ->map(fn (Asset $asset): array => [
                    'id' => $asset->id(),
                    'container' => $asset->containerHandle(),
                    'path' => $asset->path(),
                    'url' => $asset->absoluteUrl(),
                    'alt' => $asset->get('alt'),
                    'mime_type' => $asset->mimeType(),
                    'size' => $asset->size(),
                    'width' => $asset->isImage() ? $asset->width() : null,
                    'height' => $asset->isImage() ? $asset->height() : null,
                ])
                ->values()->all(),
        ]);
    }

    /**
     * @param  array<int, Container>  $containers
     * @return Generator<int, Asset>
     */
    private static function assets(array $containers, ?string $folder, ?string $like): Generator
    {
        foreach ($containers as $container) {
            yield from $container->queryAssets()
                ->when($folder !== null, fn ($query) => $query->where(fn ($query) => $query->where('folder', $folder)->orWhere('folder', 'like', addcslashes((string) $folder, '%_\\').'/%')))
                ->when($like !== null, fn ($query) => $query->where(fn ($query) => $query->where('basename', 'like', $like)->orWhere('alt', 'like', $like)))
                ->orderBy('path')
                ->lazy(200);
        }
    }
}
