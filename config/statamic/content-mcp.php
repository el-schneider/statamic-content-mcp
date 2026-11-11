<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | The path of the MCP endpoint. This is the URL editors add to their AI
    | assistant as a remote MCP server, e.g. https://example.com/mcp/content.
    |
    */

    'route' => 'mcp/content',

    /*
    |--------------------------------------------------------------------------
    | Editorial Instructions
    |--------------------------------------------------------------------------
    |
    | Your site's editorial guidelines: voice, tone, audience, conventions.
    | The assistant receives them with every session and in `get_overview`.
    | Plain text or Markdown. Leave null to send none. A value saved in the
    | Control Panel (Tools → Content MCP → Settings) takes precedence.
    |
    */

    'instructions' => null,

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | The domains OAuth clients may send editors back to after they approve a
    | connection. Only apps on these domains can register as a client. Add
    | 'http://localhost' to also allow desktop and command-line tools on the
    | editor's own machine. A value saved in the Control Panel takes precedence.
    |
    | Ignored when your app has its own config/mcp.php: then its
    | `redirect_domains` setting is used.
    |
    */

    'redirect_domains' => ['https://claude.ai', 'https://claude.com'],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | `upload_asset` downloads files from public URLs. `max_size` is the largest
    | file in bytes it accepts; the download stops as soon as it is exceeded.
    | The container's own validation rules apply on top.
    |
    */

    'uploads' => [
        'max_size' => 10 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Delete Tools
    |--------------------------------------------------------------------------
    |
    | Offer `delete_entry`, `delete_term` and `delete_asset`. Off by default:
    | content the assistant reads (a page, an uploaded file) can contain text
    | that tries to steer it, and deletes cannot be undone. Editors still need
    | the delete permission on each item. A value saved in the Control Panel
    | takes precedence.
    |
    */

    'deletes' => false,

];
