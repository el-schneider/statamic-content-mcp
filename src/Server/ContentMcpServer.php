<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Server;

use Composer\InstalledVersions;
use ElSchneider\ContentMcp\Settings;
use ElSchneider\ContentMcp\Tools\CreateEntry;
use ElSchneider\ContentMcp\Tools\CreateTerm;
use ElSchneider\ContentMcp\Tools\DeleteAsset;
use ElSchneider\ContentMcp\Tools\DeleteEntry;
use ElSchneider\ContentMcp\Tools\DeleteTerm;
use ElSchneider\ContentMcp\Tools\FindAssets;
use ElSchneider\ContentMcp\Tools\FindEntries;
use ElSchneider\ContentMcp\Tools\GetAsset;
use ElSchneider\ContentMcp\Tools\GetBlueprint;
use ElSchneider\ContentMcp\Tools\GetEntry;
use ElSchneider\ContentMcp\Tools\GetGlobal;
use ElSchneider\ContentMcp\Tools\GetNavigation;
use ElSchneider\ContentMcp\Tools\GetOverview;
use ElSchneider\ContentMcp\Tools\GetTerm;
use ElSchneider\ContentMcp\Tools\ListTerms;
use ElSchneider\ContentMcp\Tools\LocalizeEntry;
use ElSchneider\ContentMcp\Tools\MoveEntry;
use ElSchneider\ContentMcp\Tools\PublishEntry;
use ElSchneider\ContentMcp\Tools\UnpublishEntry;
use ElSchneider\ContentMcp\Tools\UpdateAsset;
use ElSchneider\ContentMcp\Tools\UpdateEntry;
use ElSchneider\ContentMcp\Tools\UpdateGlobal;
use ElSchneider\ContentMcp\Tools\UpdateNavigation;
use ElSchneider\ContentMcp\Tools\UpdateTerm;
use ElSchneider\ContentMcp\Tools\UploadAsset;
use Laravel\Mcp\Server;

final class ContentMcpServer extends Server
{
    // laravel/mcp pages lists at 15; some clients do not follow the cursor, so all tools fit in one page.
    public int $defaultPaginationLength = 50;

    protected string $name = 'Statamic Content';

    protected string $instructions = <<<'MARKDOWN'
        You are editing content on a Statamic website on behalf of a signed-in editor, with exactly that editor's permissions.

        - Call `get_overview` first. It lists what this editor may see and edit, and the site's editorial guidelines.
        - Call `get_blueprint` before writing, and send values in the shape it describes.
        - `create_entry` always saves a draft. Show the editor the returned `preview_url` so they can review it.
        - Publish or unpublish only when the editor asks for it.
        - Link to other entries in rich text (Bard) with `statamic://entry::<id>` hrefs; the site turns them into URLs.
        - On a multi-site install, tools that find or create take a `site`; tools that take an id work in that item's own site.
        - `get_asset` shows you an image, e.g. to write its alt text. `upload_asset` downloads from a public URL; files attached to the chat cannot be uploaded.
        MARKDOWN;

    protected array $tools = [
        GetOverview::class,
        GetBlueprint::class,
        FindEntries::class,
        GetEntry::class,
        GetGlobal::class,
        FindAssets::class,
        GetAsset::class,
        ListTerms::class,
        GetTerm::class,
        GetNavigation::class,
        CreateEntry::class,
        UpdateEntry::class,
        PublishEntry::class,
        UnpublishEntry::class,
        UpdateGlobal::class,
        LocalizeEntry::class,
        CreateTerm::class,
        UpdateTerm::class,
        UpdateAsset::class,
        UploadAsset::class,
        UpdateNavigation::class,
        MoveEntry::class,
        DeleteEntry::class,
        DeleteTerm::class,
        DeleteAsset::class,
    ];

    protected function boot(): void
    {
        $this->version = InstalledVersions::getPrettyVersion('el-schneider/statamic-content-mcp') ?? $this->version;

        $guidelines = Settings::instructions();

        if ($guidelines !== null) {
            $this->instructions .= "\n\n## Editorial guidelines of this site\n\n".$guidelines;
        }
    }
}
