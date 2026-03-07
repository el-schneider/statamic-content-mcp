<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Tools
    |--------------------------------------------------------------------------
    |
    | Enable or disable individual MCP tools. Disabled tools are not registered
    | with the MCP server and won't appear in the client's tool list.
    |
    */

    'tools' => [
        'site' => true,
        'blueprints' => true,
        'entries' => true,
        'globals' => true,
        'taxonomies' => true,
        'assets' => true,
        'search' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Collection Restrictions
    |--------------------------------------------------------------------------
    |
    | Limit which collections are exposed through the MCP server. When set to
    | null, all collections are accessible. When set to an array, only the
    | listed collections can be browsed, read, created in, or searched.
    |
    | This restriction applies globally across all tools — entries, blueprints,
    | search results, and the site overview will all respect it.
    |
    | Example: ['pages', 'blog']
    |
    */

    'collections' => null,

    /*
    |--------------------------------------------------------------------------
    | Default Published State
    |--------------------------------------------------------------------------
    |
    | Whether newly created entries are published immediately. When false
    | (recommended), entries are created as drafts and must be explicitly
    | published via the entries tool's `publish` action. This prevents
    | AI-generated content from going live without review.
    |
    */

    'default_published' => false,

    /*
    |--------------------------------------------------------------------------
    | HTTP Transport Auth
    |--------------------------------------------------------------------------
    |
    | The authentication guard used for the HTTP (SSE) transport endpoint at
    | /mcp/content. The stdio transport does not use this setting — it runs
    | as a local process with full access.
    |
    | Supported: 'sanctum'
    |
    */

    'auth' => 'sanctum',

    /*
    |--------------------------------------------------------------------------
    | Pagination
    |--------------------------------------------------------------------------
    |
    | Default and maximum page sizes for list endpoints (entries, terms,
    | assets). The AI client can request a custom limit up to max_limit.
    |
    */

    'pagination' => [
        'default_limit' => 20,
        'max_limit' => 100,
    ],

];
