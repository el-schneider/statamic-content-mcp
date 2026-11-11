<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\AssetPresenter;
use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\Find;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Title('Update asset')]
#[Description('Changes the blueprint data of an asset, such as its alt text (see get_blueprint with `asset_container`). The file itself does not change. `data` works like in update_entry; changes are live at once.')]
#[IsDestructive]
#[IsIdempotent]
final class UpdateAsset extends ContentTool
{
    protected string $name = 'update_asset';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Asset id, container::path.')->required(),
            'data' => $schema->object()->description('Field handles or dot paths mapped to new values, e.g. {"alt": "Two people on a bench"}.')->required(),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['required', 'string'], 'data' => ['present', 'array']]);

        $asset = Find::asset($request->string('id')->toString());

        if (Gate::denies('edit', $asset)) {
            return Response::error('You may not edit this asset.');
        }

        /** @var array<string, mixed> $changes */
        $changes = $request->array('data');

        return Response::structured(AssetPresenter::meta(ContentWriter::updateAsset($asset, $changes)));
    }
}
