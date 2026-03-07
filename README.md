# Statamic Content MCP

An MCP server for Statamic that lets AI assistants discover, read, create, update, and publish content. Works with Claude Code, Cursor, Windsurf, Claude Desktop, and any other MCP-compatible client.

## Features

- **Site Discovery:** Overview of collections, taxonomies, globals, asset containers, and navigations
- **Blueprint Inspection:** Describe field structures for any collection, taxonomy, global, or asset container
- **Entry Management:** Full CRUD with draft-first publishing, scheduling, filtering, sorting, and pagination
- **Globals:** Read and update site-wide settings
- **Taxonomies:** List, create, update, and delete taxonomy terms
- **Assets:** Browse containers and inspect asset metadata (read-only)
- **Search:** Find entries by title across collections with status filtering
- **Collection Restrictions:** Limit which collections are exposed to AI clients
- **Bard Support:** Accepts markdown for Bard fields, returns markdown on read

## Requirements

- PHP 8.3+
- Statamic 6
- Laravel 12+

## Installation

```bash
composer require el-schneider/statamic-content-mcp
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="statamic-content-mcp-config"
```

## Connecting an AI Client

### Stdio (Claude Code, Cursor, etc.)

Add to your project's `.mcp.json`:

```json
{
  "mcpServers": {
    "statamic-content": {
      "type": "stdio",
      "command": "php",
      "args": ["artisan", "mcp:start", "content"]
    }
  }
}
```

If you use Laravel Sail:

```json
{
  "mcpServers": {
    "statamic-content": {
      "type": "stdio",
      "command": "./vendor/bin/sail",
      "args": ["artisan", "mcp:start", "content"]
    }
  }
}
```

### HTTP (Claude Desktop, ChatGPT Desktop, etc.)

The addon registers an HTTP transport at `/mcp/content`.

Two auth modes are supported:

- **`sanctum`** — recommended when your site stores users in the database
- **`static_token`** — recommended fallback for file-based Statamic users

#### Sanctum

Use Sanctum when your site uses Eloquent users and can issue personal access tokens.

#### Static token

Use a shared bearer token plus a configured Statamic user when your site uses file-based users.

Generate a strong token with a required Statamic user binding:

```bash
php artisan content-mcp:generate-static-token --user=agent@agent.md
```

Use `--raw` if you only want the token value for scripting:

```bash
php artisan content-mcp:generate-static-token --user=agent@agent.md --raw
```

Use `--length=` to control token length. Supported range: `32-128` characters.

```php
'auth' => [
    'driver' => 'static_token',
    'static_token' => [
        'token' => env('STATAMIC_CONTENT_MCP_STATIC_TOKEN'),
        'user' => env('STATAMIC_CONTENT_MCP_STATIC_TOKEN_USER'),
    ],
],
```

Example `.env` values:

```env
STATAMIC_CONTENT_MCP_AUTH_DRIVER=static_token
STATAMIC_CONTENT_MCP_STATIC_TOKEN=replace-with-a-long-random-token
STATAMIC_CONTENT_MCP_STATIC_TOKEN_USER=agent@agent.md
```

If you previously published an older config that used the legacy scalar form (`'auth' => 'sanctum'`), update it to the new array form before switching drivers.

The request authenticates with the bearer token, then the addon resolves the configured Statamic user and runs MCP tools under that user's normal Statamic permissions.

### Example remote MCP client config (`static_token`)

For Claude Desktop or any other client that supports remote MCP over HTTP, use a server entry like this:

```json
{
  "mcpServers": {
    "statamic-content": {
      "url": "https://example.com/mcp/content",
      "headers": {
        "Authorization": "Bearer <your-static-token>"
      }
    }
  }
}
```

### Example remote MCP client config (`sanctum`)

```json
{
  "mcpServers": {
    "statamic-content": {
      "url": "https://example.com/mcp/content",
      "headers": {
        "Authorization": "Bearer <your-sanctum-token>"
      }
    }
  }
}
```

### Quick HTTP verification

Before wiring a desktop client, verify the endpoint directly:

```bash
curl -X POST "https://example.com/mcp/content" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer <your-token>" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "initialize",
    "params": {
      "protocolVersion": "2024-11-05",
      "capabilities": {},
      "clientInfo": {
        "name": "curl",
        "version": "1.0.0"
      }
    }
  }'
```

A successful response includes:

- `"jsonrpc": "2.0"`
- `"result.serverInfo.name": "statamic-content"`
- `"result.serverInfo.version": "1.0.0"`

## Configuration

The published config file at `config/statamic/content-mcp.php` is fully documented with inline comments explaining each option. Key settings:

- **`tools`** — Enable or disable individual tools
- **`collections`** — Restrict which collections are exposed (`null` = all, or an array like `['pages', 'blog']`)
- **`default_published`** — Whether entries are published on create (`false` = draft-first, recommended)
- **`auth`** — HTTP auth driver config (`sanctum` or `static_token`)
- **`pagination`** — Default and maximum page sizes for list endpoints

## Tools

The addon exposes 7 MCP tools. Each tool uses an `action` parameter to keep the tool surface compact.

### `site`

Orientation tool. Start here to discover what's available.

| Action        | Description                                                                        |
| ------------- | ---------------------------------------------------------------------------------- |
| `overview`    | Site name, collections, taxonomies, globals, asset containers, navigations         |
| `collections` | Detailed collection info (title, blueprints, route, entry count, structure, dates) |

### `blueprints`

Describe the field structure of any blueprint. Always inspect a blueprint before creating or updating content.

| Parameter         | Description                                                  |
| ----------------- | ------------------------------------------------------------ |
| `collection`      | Collection handle                                            |
| `taxonomy`        | Taxonomy handle                                              |
| `global`          | Global set handle                                            |
| `asset_container` | Asset container handle                                       |
| `blueprint`       | Blueprint handle (when a collection has multiple blueprints) |

### `entries`

Full entry lifecycle management.

| Action      | Description                                                          |
| ----------- | -------------------------------------------------------------------- |
| `list`      | List entries with optional status filter, sort, and pagination       |
| `get`       | Get a single entry by ID or by slug + collection                     |
| `create`    | Create an entry (draft by default). Accepts markdown for Bard fields |
| `update`    | Partial update — merges with existing data                           |
| `delete`    | Delete an entry                                                      |
| `publish`   | Publish a draft entry                                                |
| `unpublish` | Unpublish a live entry                                               |

### `globals`

| Action   | Description                                  |
| -------- | -------------------------------------------- |
| `list`   | List all global sets                         |
| `get`    | Get a global set with its current values     |
| `update` | Partial update — merges with existing values |

### `taxonomies`

| Action        | Description                                    |
| ------------- | ---------------------------------------------- |
| `list`        | List all taxonomies with term counts           |
| `get_terms`   | List terms in a taxonomy with pagination       |
| `create_term` | Create a term (auto-generates slug from title) |
| `update_term` | Update a term's data                           |
| `delete_term` | Delete a term                                  |

### `assets`

Read-only asset browsing.

| Action            | Description                                                           |
| ----------------- | --------------------------------------------------------------------- |
| `list_containers` | List all asset containers                                             |
| `list`            | List assets in a container with optional folder filter and pagination |
| `get`             | Get asset metadata (path, url, extension, size, alt text)             |

### `search`

Search entries by title across collections. Supports collection filter, status filter, and result limit.

## Testing

```bash
composer test
```

## License

MIT — see [LICENSE.md](LICENSE.md) for details.
