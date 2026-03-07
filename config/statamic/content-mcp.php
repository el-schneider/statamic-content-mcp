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
    | Authentication for the HTTP MCP endpoint. Use `sanctum` when your site
    | stores users in the database. Use `static_token` as a fallback for the
    | common file-based Statamic user setup.
    |
    | `static_token` authenticates the request with a shared bearer token and
    | then resolves a real Statamic user (by id or email) so existing Statamic
    | permission checks still apply inside MCP tools.
    |
    | Generate a token with:
    | `php artisan content-mcp:generate-static-token --user=editor@example.com`
    |
    | The legacy scalar form (`'auth' => 'sanctum'`) is still supported.
    |
    */

    'auth' => [
        'driver' => env('STATAMIC_CONTENT_MCP_AUTH_DRIVER', 'sanctum'),

        'static_token' => [
            'token' => env('STATAMIC_CONTENT_MCP_STATIC_TOKEN'),
            'user' => env('STATAMIC_CONTENT_MCP_STATIC_TOKEN_USER'),
        ],
    ],

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
