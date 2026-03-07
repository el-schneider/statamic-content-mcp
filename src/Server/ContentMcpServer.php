<?php

declare(strict_types=1);

namespace ElSchneider\ContentMcp\Server;

use ElSchneider\ContentMcp\Tools\AssetTool;
use ElSchneider\ContentMcp\Tools\BlueprintTool;
use ElSchneider\ContentMcp\Tools\EntryTool;
use ElSchneider\ContentMcp\Tools\GlobalsTool;
use ElSchneider\ContentMcp\Tools\SearchTool;
use ElSchneider\ContentMcp\Tools\SiteTool;
use ElSchneider\ContentMcp\Tools\TaxonomyTool;
use Laravel\Mcp\Server;

final class ContentMcpServer extends Server
{
    protected string $name = 'statamic-content';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Statamic Content MCP server. Provides tools for discovering, reading, creating,
        updating, and publishing content in a Statamic CMS site.

        Start with the `site` tool to discover available collections, taxonomies,
        globals, and asset containers. Then use `blueprints` to understand field
        structures before creating or updating content.
    MARKDOWN;

    protected array $tools = [
        SiteTool::class,
        BlueprintTool::class,
        EntryTool::class,
        GlobalsTool::class,
        TaxonomyTool::class,
        AssetTool::class,
        SearchTool::class,
    ];
}
