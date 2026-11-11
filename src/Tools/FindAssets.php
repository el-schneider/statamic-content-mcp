<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\NotFound;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
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
#[Description('Lists or searches files (images, documents) by filename or alt text. Returns each asset\'s `id` (container::path), `path`, url, alt text, mime type, size in bytes and image dimensions. Assets fields store the `path`. Uploading files is not possible.')]
#[IsReadOnly]
final class FindAssets extends ContentTool
{
    protected string $name = 'find_assets';

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle, from get_overview.'),
            'query' => $schema->string()->description('Text to match in the filename or alt text.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(20),
            'offset' => $schema->integer()->min(0)->default(0),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate([
            'container' => ['nullable', 'string'],
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

        $search = $request->filled('query') ? $request->string('query')->toString() : null;

        // shortcut: loads every asset of the containers and filters in PHP; query the asset index for very large containers.
        $assets = $containers
            ->flatMap(fn (Container $container): array => $container->queryAssets()->get()->all())
            ->filter(fn (Asset $asset): bool => Gate::allows('view', $asset))
            ->filter(fn (Asset $asset): bool => $search === null
                || mb_stripos($asset->basename(), $search) !== false
                || mb_stripos((string) $asset->get('alt'), $search) !== false)
            ->sortBy(fn (Asset $asset): string => $asset->path())
            ->values();

        return Response::structured([
            'total' => $assets->count(),
            'assets' => $assets
                ->slice($request->integer('offset'), $request->integer('limit', 20))
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
}
