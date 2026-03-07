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

The addon registers an HTTP transport at `/mcp/content` protected by Sanctum. Generate a token for your user, then point your client at your site's MCP endpoint.

## Configuration

The published config file at `config/statamic/content-mcp.php` is fully documented with inline comments explaining each option. Key settings:

- **`tools`** — Enable or disable individual tools
- **`collections`** — Restrict which collections are exposed (`null` = all, or an array like `['pages', 'blog']`)
- **`default_published`** — Whether entries are published on create (`false` = draft-first, recommended)
- **`auth`** — Auth guard for the HTTP transport
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
