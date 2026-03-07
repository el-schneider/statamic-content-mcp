<?php

declare(strict_types=1);

use ElSchneider\ContentMcp\Server\ContentMcpServer;
use Laravel\Mcp\Facades\Mcp;

// Stdio transport for local AI clients (Claude Code, Cursor, etc.)
Mcp::local('content', ContentMcpServer::class);

// HTTP transport for remote AI clients (Claude Desktop, ChatGPT Desktop, etc.)
Mcp::web('/mcp/content', ContentMcpServer::class)
    ->middleware(['auth:sanctum']);
