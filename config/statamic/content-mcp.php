<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Route
    |--------------------------------------------------------------------------
    |
    | The path of the MCP endpoint. This is the URL editors paste into Claude
    | as a custom connector, e.g. https://example.com/mcp/content.
    |
    */

    'route' => 'mcp/content',

    /*
    |--------------------------------------------------------------------------
    | Editorial Instructions
    |--------------------------------------------------------------------------
    |
    | Your site's editorial guidelines: voice, tone, audience, conventions.
    | Claude receives them with every session and in the `get_overview` tool.
    | Plain text or Markdown. Leave null to send none.
    |
    */

    'instructions' => null,

];
