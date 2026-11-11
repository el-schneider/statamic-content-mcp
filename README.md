# Content MCP for Statamic

Content MCP lets the editors of a Statamic site work on content with Claude. An editor adds the site to Claude as a custom connector, signs in with their Control Panel account and approves the connection. From then on Claude can read the site's content and draft new entries, with exactly the rights that editor has in Statamic.

It is built for editors, not developers. There are no blueprint, user or system tools, no Control Panel pages, and no local (stdio) transport.

## Requirements

- PHP 8.3+
- Statamic 6.31+ on Laravel 12 or 13
- A database for Passport's OAuth tables (SQLite is fine). Statamic users can stay in files.
- A public HTTPS URL, because Claude connects from Anthropic's servers

## Installation

```bash
composer require el-schneider/statamic-content-mcp
php artisan migrate
php artisan passport:keys
```

`migrate` creates Laravel Passport's tables and makes their `user_id` columns strings, since Statamic user ids are UUIDs.

`passport:keys` writes the OAuth signing keys to `storage/oauth-private.key` and `storage/oauth-public.key`. Do this once per environment and keep the keys out of Git. Instead of files you can set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` in the environment, which is easier when several servers share one site. Changing the keys disconnects every editor.

Then give the roles that should use Claude the permission **Connect Claude via Content MCP** (Users → Roles). Super users always have it.

Optionally publish the config to add your editorial guidelines:

```bash
php artisan vendor:publish --tag=content-mcp-config
```

```php
// config/statamic/content-mcp.php
return [
    'route' => 'mcp/content',
    'instructions' => 'We write in plain, friendly English. Headlines in sentence case. No exclamation marks.',
];
```

`instructions` is sent to Claude at the start of every session and returned by `get_overview`.

The addon works with `php artisan config:cache` and `php artisan route:cache`.

## Connecting Claude

In Claude (web, desktop or mobile): **Settings → Connectors → Add custom connector**, and enter the endpoint URL:

```
https://example.com/mcp/content
```

Claude registers itself, opens the Statamic login, and then shows a consent screen. The consent screen names the app, the address you will be sent back to (for Claude this is `claude.ai`), and the account that approves. Any app can call itself "Claude"; the address is what tells you who you are really connecting.

Editors without the permission see a "No access" page instead of the consent screen.

## How permissions work

Every request runs as the editor who approved the connection. `User::current()`, `auth()->user()` and Laravel's Gate all return that user, so your policies, event listeners and `updated_by` behave as they do in the Control Panel.

All access goes through Statamic's policies on the actual object: an entry is listed or returned only if `can('view', $entry)`, changed only if `can('edit', $entry)`, published only if `can('publish', $entry)`, and so on. If your site replaces Statamic's policies, for example to limit editors to their own entries, those rules apply here too. Content the editor may not see is reported as not found.

Field rules from the Control Panel apply as well: read-only and computed fields cannot be written, and editors without "edit other authors' entries" cannot change the author.

The permission **Connect Claude via Content MCP** is checked on every request. Removing it from a role cuts access immediately, even for connections that already exist.

## Tools

| Tool | What it does |
| --- | --- |
| `get_overview` | The site, the acting editor, the collections, taxonomies, global sets and asset containers they may access, and your guidelines |
| `get_blueprint` | The fields of an entry blueprint or global set, with the shape each field's value must have |
| `find_entries` | List a collection, or search titles, slugs and content text; returns summaries and a total |
| `get_entry` | One entry's meta and field values |
| `get_global` | The values of a global set |
| `find_assets` | Search files by filename or alt text |
| `list_terms` | The terms of a taxonomy |
| `create_entry` | Create a new entry, always as a draft |
| `update_entry` | Change field values of an entry |
| `publish_entry` | Publish an entry |
| `unpublish_entry` | Turn an entry back into a draft |
| `update_global` | Change values of a global set |

Read tools are annotated as read-only and write tools as writes, so Claude can ask the editor for approval where it matters. There are no delete, upload, navigation or taxonomy-term write tools.

## Drafts, previews and publishing

`create_entry` always creates an unpublished draft, even for editors who may publish. `update_entry` never changes whether an entry is published. When a collection has revisions enabled, changes to a published entry are saved as unpublished changes (a working copy), like in the Control Panel.

Write tools return the entry's `edit_url` in the Control Panel and a `preview_url`, a Live Preview link that shows the entry as just saved, draft or not. Anyone with the link can open that preview until its token expires (about an hour). Claude publishes only when the editor asks it to, and only if the editor has the publish permission.

Saving fires Statamic's normal events, so static caching, search indexes and Git automation work as usual.

## Data format

Values are exchanged in the form Statamic stores them in the content files. `get_blueprint` tells Claude what each field expects: entry ids for entries fields, asset paths for assets fields, term slugs for terms fields, option keys for selects, and so on.

The exception is Bard. A Bard field without sets is an HTML string. A Bard field with sets is a list of blocks: `{"type": "text", "text": "<p>…</p>"}` for text and `{"type": "<set>", "id": "…", …fields}` for sets. Links to entries use `statamic://entry::<id>` and keep working when the entry's URL changes.

`update_entry` and `update_global` accept dot paths to change one nested value, where a segment is an object key or the `id` of a set or grid row: `{"page_builder.lx3k2a.heading": "New heading"}`. Content that was not changed is written back byte for byte as it was stored, so Git diffs show only real changes.

## Security notes

- Authentication is OAuth with PKCE and dynamic client registration, provided by Laravel Passport and `laravel/mcp`. Access tokens last one hour, refresh tokens 90 days and are rotated on every use.
- Tokens are bound to the `mcp:use` scope and checked for signature, expiry, revocation and a revoked client on every request.
- The consent screen cannot be embedded in a frame.
- Client registration and the endpoint are rate limited.
- There are no delete tools.
- To disconnect everyone, revoke their tokens (`oauth_access_tokens` and `oauth_refresh_tokens`) or rotate the Passport keys. To disconnect one editor, remove the permission from their role or revoke their tokens. Schedule `php artisan passport:purge` to clean up expired tokens.
- `laravel/mcp` accepts redirect URIs on any domain by default. To allow only Claude, publish its config (`php artisan vendor:publish --tag=mcp-config`) and set `'redirect_domains' => ['https://claude.ai']`.

## Limitations

- Single site: finding and creating entries, assets, terms and globals use the default site. Tools that take an entry id work on that entry in its own site.
- No file uploads; Claude can only use assets that already exist.
- Search loads the entries of the searched collections into memory, which is slow for very large collections.
- Validation errors inside sets use Statamic's field paths, e.g. `content.2.attrs.values.quote`.
- The addon configures Laravel Passport for itself: it sets Passport's guard to the Control Panel guard, the token lifetimes, the default scope and the consent screen. A site that already uses Passport for its own API will see those settings change.
- If a site already published Passport's migrations, the addon uses the published copies. They must be Passport 13's.

## License

MIT
