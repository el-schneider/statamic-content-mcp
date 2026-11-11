<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Content;

use Laravel\Mcp\Response;
use Statamic\Assets\Asset;
use Statamic\Facades\Glide;
use Statamic\Imaging\ImageGenerator;
use Statamic\Imaging\ImageValidator;

final class AssetPresenter
{
    /**
     * The rendition the model sees: small enough for its context, large enough to describe.
     */
    private const array PREVIEW = ['w' => 1024, 'h' => 1024, 'fit' => 'max', 'fm' => 'jpg', 'q' => 80];

    /**
     * @return array<string, mixed>
     */
    public static function summary(Asset $asset): array
    {
        return [
            'id' => $asset->id(),
            'container' => $asset->containerHandle(),
            'path' => $asset->path(),
            'url' => $asset->absoluteUrl(),
            'alt' => $asset->get('alt'),
            'mime_type' => $asset->mimeType(),
            'size' => $asset->size(),
            'width' => $asset->isImage() ? $asset->width() : null,
            'height' => $asset->isImage() ? $asset->height() : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function meta(Asset $asset): array
    {
        return [
            ...self::summary($asset),
            'edit_url' => $asset->editUrl(),
            'last_modified' => $asset->lastModified()->toIso8601String(),
            'data' => AgentForm::ofBlueprint($asset->blueprint(), $asset->data()->all()),
        ];
    }

    /**
     * A downscaled JPEG rendered by the site's own Glide setup, for images Glide can read.
     */
    public static function preview(Asset $asset): ?Response
    {
        if (! $asset->isImage() || ! app(ImageValidator::class)->isValidImage($asset->extension(), $asset->mimeType())) {
            return null;
        }

        $path = app(ImageGenerator::class)->generateByAsset($asset, self::PREVIEW);

        return Response::image((string) Glide::cacheDisk()->get($path), 'image/jpeg');
    }
}
