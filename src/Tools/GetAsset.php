<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\AssetPresenter;
use ElSchneider\ContentMcp\Content\Find;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Title('Get asset')]
#[Description('Returns one asset (file): its meta and `data`, the blueprint values such as alt text in the shape update_asset accepts. For images it also returns the image itself, scaled down to at most 1024 pixels, so you can look at it, e.g. to write alt text.')]
#[IsReadOnly]
final class GetAsset extends ContentTool
{
    protected string $name = 'get_asset';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Asset id, container::path, from find_assets.')->required(),
        ];
    }

    protected function respond(Request $request): ResponseFactory
    {
        $request->validate(['id' => ['required', 'string']]);

        $asset = Find::asset($request->string('id')->toString());
        $meta = AssetPresenter::meta($asset);
        $preview = AssetPresenter::preview($asset);

        return Response::make([...Response::structured($meta)->responses()->all(), ...($preview === null ? [] : [$preview])])
            ->withStructuredContent($meta);
    }
}
