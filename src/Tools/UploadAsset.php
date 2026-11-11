<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Tools;

use ElSchneider\ContentMcp\Content\AssetPresenter;
use ElSchneider\ContentMcp\Content\ContentWriter;
use ElSchneider\ContentMcp\Content\Find;
use ElSchneider\ContentMcp\Content\NotSaved;
use ElSchneider\ContentMcp\Content\RemoteFile;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Statamic\Assets\AssetContainer;
use Statamic\Assets\AssetUploader;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Contracts\Assets\AssetFolder;
use Statamic\Events\AssetCreated;
use Statamic\Events\AssetCreating;
use Statamic\Events\AssetUploaded;
use Statamic\Fields\Validator as FieldValidator;
use Statamic\Rules\AllowedFile;

#[Title('Upload asset from URL')]
#[Description('Adds a file to an asset container by downloading it from a public http(s) URL. You cannot upload files attached to the chat or stored on your side, only files the site can download from a URL. The container\'s file type and size rules apply. An existing file is never replaced: pick another `filename` if one exists. `data` sets blueprint values such as alt text. Returns the new asset; use its `path` in assets fields.')]
#[IsDestructive(false)]
#[IsIdempotent(false)]
#[IsOpenWorld]
final class UploadAsset extends ContentTool
{
    protected string $name = 'upload_asset';

    public function schema(JsonSchema $schema): array
    {
        return [
            'container' => $schema->string()->description('Asset container handle, from get_overview.')->required(),
            'url' => $schema->string()->description('Public http or https URL of the file.')->required(),
            'folder' => $schema->string()->description('Folder in the container, e.g. "images/events". Defaults to the top level.'),
            'filename' => $schema->string()->description('Filename to store, e.g. "summer-fair.jpg". Defaults to the last part of the URL.'),
            'data' => $schema->object()->description('Blueprint values, e.g. {"alt": "Stalls on the market square"}.'),
        ];
    }

    protected function respond(Request $request): Response|ResponseFactory
    {
        $request->validate([
            'container' => ['required', 'string'],
            'url' => ['required', 'string', 'max:2048'],
            'folder' => ['nullable', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
        ]);

        $container = Find::assetContainer($request->string('container')->toString());

        // The CP's AssetsController::store() authorizes this.
        if (Gate::denies('store', [AssetContract::class, $container])) {
            return Response::error("You may not upload to [{$container->handle()}].");
        }

        $folder = self::folder($request->string('folder')->toString());

        if ($folder !== '' && ! $container->disk()->exists($folder) && Gate::denies('create', [AssetFolder::class, $container])) {
            return Response::error("The folder [{$folder}] does not exist, and you may not create folders in [{$container->handle()}].");
        }

        /** @var array<string, mixed> $input */
        $input = $request->array('data');
        $data = ContentWriter::newAssetData($container->blueprint(), $input);

        $download = app(RemoteFile::class)->download($request->string('url')->toString(), self::maxSize());
        $file = self::named($download, $request->filled('filename') ? $request->string('filename')->toString() : $download->getClientOriginalName());

        try {
            return Response::structured(AssetPresenter::meta(self::store($container, $folder, $file, $data)));
        } finally {
            @unlink($download->getPathname());
        }
    }

    /**
     * Mirrors the CP's validation, conflict check and Asset::upload(), but checks that
     * the save went through: upload() would report a save a listener cancelled as done.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function store(AssetContainer $container, string $folder, UploadedFile $file, array $data): \Statamic\Assets\Asset
    {
        $rules = array_map(fn (mixed $rule): mixed => app(FieldValidator::class)->parse($rule), $container->validationRules());
        // Keyed by filename: its extension decides the allowed type.
        Validator::make(['filename' => $file], ['filename' => ['file', new AllowedFile, ...$rules]])->validate();

        $path = ltrim($folder.'/'.AssetUploader::getSafeFilename($file->getClientOriginalName()), '/');

        if ($existing = $container->asset($path)) {
            throw ValidationException::withMessages(['filename' => ["An asset already exists at [{$existing->id()}]. Choose another filename."]]);
        }

        $asset = $container->makeAsset($path);

        NotSaved::unless(AssetCreating::dispatch($asset) !== false);

        $asset->path(AssetUploader::asset($asset)->upload($file))->syncOriginal();

        foreach ($data as $handle => $value) {
            $asset->set($handle, $value);
        }

        if (! $asset->save()) {
            // A listener refused the asset; its file must not stay behind as an asset either.
            $asset->disk()->delete($asset->path());

            throw new NotSaved;
        }

        AssetUploaded::dispatch($asset, $file->getClientOriginalName());
        AssetCreated::dispatch($asset);

        return $asset;
    }

    /**
     * A folder path relative to the container, cleaned like the CP's upload paths.
     */
    private static function folder(string $folder): string
    {
        $segments = preg_split('/[\/\\\\]+/', trim($folder, '/\\')) ?: [];

        if (array_intersect($segments, ['.', '..']) !== []) {
            throw ValidationException::withMessages(['folder' => ['The folder must be a path inside the container, without "." or "..".']]);
        }

        return AssetUploader::getSafePath(implode('/', $segments));
    }

    /**
     * The downloaded file under the name to store, with an extension: from the name,
     * or else from the downloaded content.
     */
    private static function named(UploadedFile $download, string $name): UploadedFile
    {
        $name = basename(str_replace('\\', '/', $name));

        if (pathinfo($name, PATHINFO_EXTENSION) === '' && ($extension = $download->guessExtension()) !== null) {
            $name = ($name === '' ? 'file' : $name).'.'.$extension;
        }

        return new UploadedFile($download->getPathname(), $name, $download->getMimeType(), test: true);
    }

    private static function maxSize(): int
    {
        return (int) config('statamic.content-mcp.uploads.max_size');
    }
}
