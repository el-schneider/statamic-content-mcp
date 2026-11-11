<!-- Banner goes here: <img src="images/cm_banner.png" alt="Statamic Content MCP"> -->

# Statamic Content MCP

> Let your editors work on your Statamic site from Claude. They paste one URL, sign in with their Control Panel account, and Claude reads and drafts content with exactly the permissions they already have.

"Find every page that still mentions the old opening hours and fix them." "Draft a post about Saturday's event from these notes." "Shorten the intro on the rooms page." The editor asks in Claude, checks the preview link, and says when to publish.

## Features

- **Made for editors**: Entries, globals, assets and taxonomy terms. No blueprint, user, cache or system tools, so there is nothing on the other end that can break a site.
- **One URL, no tokens**: Editors add the site as a custom connector in Claude (web, desktop or mobile) and sign in through the Control Panel login. Nobody creates, copies or stores an API key.
- **Your permissions, unchanged**: Every request runs as the editor's own Statamic user and goes through Statamic's policies on the actual entry, global or asset. Custom policies, such as "authors only see their own entries", apply without extra setup.
- **Drafts first**: New entries are always drafts. Publishing is a separate tool, so Claude asks before anything goes live. There are no delete tools.
- **Preview before publish**: Every write returns a Live Preview link that renders the draft through your templates.
- **Edits one block, not the page**: Claude can change a single field inside a page builder set. Everything it did not touch is written back byte for byte, so Git diffs show only the real change.
- **Speaks your blueprints**: Bard, Replicator, Grid and Group fields at any depth, validated by the same rules as a save in the Control Panel.
- **Your editorial voice built in**: Put tone and style guidelines in the config and Claude receives them in every session.
- **Small and standard**: Twelve tools, OAuth by Laravel Passport, protocol by `laravel/mcp`. No Control Panel pages, no frontend build.
- **Works with file users**: Statamic users stay where they are. Only the OAuth tables need a database, and SQLite is enough.

## Requirements

- PHP 8.3+
- Statamic 6.31+ on Laravel 12 or 13
- A database for Passport's OAuth tables
- A public HTTPS URL, because Claude connects from Anthropic's servers

## Installation

```bash
composer require el-schneider/statamic-content-mcp
php artisan migrate
php artisan passport:keys
```

`migrate` creates Laravel Passport's tables. `passport:keys` writes the OAuth signing keys to `storage/oauth-private.key` and `storage/oauth-public.key`. Run it once per environment and keep the keys out of Git. You can set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` in the environment instead, which is easier when several servers share one site. Changing the keys disconnects every editor.

Then give the roles that should use Claude the permission **Connect Claude via Content MCP** (Users → Roles). Super users always have it.

The addon works with `php artisan config:cache` and `php artisan route:cache`.

## Connecting Claude

In Claude: **Settings → Connectors → Add custom connector**, and enter the endpoint URL:

```
https://example.com/mcp/content
```

Claude registers itself, opens the Statamic login, and shows a consent screen. The screen names the app, the address the editor is sent back to (for Claude this is `claude.ai`) and the account that approves. Any app can call itself "Claude"; the address tells you who you are really connecting.

Editors without the permission see a "No access" page instead of the consent screen.

## Configuration

```bash
php artisan vendor:publish --tag=content-mcp-config
```

```php
// config/statamic/content-mcp.php
return [
    'route' => 'mcp/content',
    'instructions' => 'We write in plain, friendly English. Headlines in sentence case. No exclamation marks.',
    'redirect_domains' => ['https://claude.ai', 'https://claude.com'],
];
```

- `route` is the path of the endpoint.
- `instructions` holds your editorial guidelines. Claude receives them at the start of every session and in `get_overview`. Field `instructions` in your blueprints reach Claude too, through `get_blueprint`.
- `redirect_domains` lists where OAuth clients may send the editor back after approval, which decides which apps can register at all. The default allows only Claude. To connect a local client such as Claude Code, add `'http://localhost'` (any port). If your app has its own `config/mcp.php` (the config of `laravel/mcp`), its `redirect_domains` is used and this setting is ignored.

## Permissions

Every request runs as the editor who approved the connection. `User::current()`, `auth()->user()` and Laravel's Gate all return that user, so your policies, event listeners and `updated_by` behave as they do in the Control Panel.

- An entry is listed or returned only if `can('view', $entry)`, changed only if `can('edit', $entry)`, published only if `can('publish', $entry)`. The same holds for globals, assets and terms.
- Content the editor may not see is reported as not found.
- Read-only and computed fields cannot be changed, also inside groups, grids and sets.
- Editors without "edit other authors' entries" cannot change the author.
- `published`, `id`, `blueprint` and the last-modified attributes are never taken from field data, even if a blueprint has fields with those names.
- The permission **Connect Claude via Content MCP** is checked on every request. Removing it from a role cuts access at once, also for existing connections.

To give Claude less than an editor has, connect with a dedicated user and a narrower role.

## Tools

Read:

- `get_overview`: the site, the acting editor, what they may access, and your guidelines
- `get_blueprint`: the fields of a blueprint or global set, and the shape each value must have
- `find_entries`: list a collection, or search titles, slugs and content
- `get_entry`: one entry's meta and field values
- `get_global`: the values of a global set
- `find_assets`: search files by filename or alt text
- `list_terms`: the terms of a taxonomy

Write:

- `create_entry`: create an entry, always as a draft
- `update_entry`: change field values of an entry
- `publish_entry`: publish an entry
- `unpublish_entry`: turn an entry back into a draft
- `update_global`: change values of a global set

Read tools are annotated as read-only, so an editor can allow them once and still be asked for every write.

## Drafts, previews and publishing

- `create_entry` always creates a draft, even for editors who may publish.
- `update_entry` never changes whether an entry is published. On a collection with revisions, changes to a published entry are saved as a working copy, as in the Control Panel. Without revisions, they are live on save.
- Every write returns the entry's `edit_url` and a `preview_url`. Anyone with the preview link can open it until its token expires (about an hour).
- Saving fires Statamic's normal events, so static caching, search indexes and Git automation work as usual. If one of your listeners cancels a save, the tool reports an error.
- Without a `slug`, `create_entry` builds one from the title in the site's language. Unique-slug rules on the blueprint still apply.

## Data format

Values travel in the form Statamic stores them in the content files. `get_blueprint` tells Claude what each field expects: entry ids for entries fields, asset paths for assets fields, option keys for selects.

Bard is the exception. A Bard field without sets is an HTML string. A Bard field with sets is a list of blocks:

```json
[
    { "type": "text", "text": "<p>See our <a href=\"statamic://entry::a1b2\">rooms</a>.</p>" },
    { "type": "quote", "id": "lx3k2a", "text": "…", "cite": "…" }
]
```

Links to entries use `statamic://entry::<id>` and keep working when the entry's URL changes.

`update_entry` and `update_global` accept dot paths to change one nested value. A segment is an object key or the `id` of a set or grid row:

```json
{ "page_builder.lx3k2a.heading": "New heading" }
```

`null` clears a field.

## Security

- OAuth with PKCE (S256 only) and dynamic client registration, by Laravel Passport and `laravel/mcp`.
- Access tokens last one hour. Refresh tokens last 90 days and rotate on every use.
- Each request checks the token's signature, expiry, revocation, scope and client.
- Only clients that redirect to `redirect_domains` can register.
- The consent screen cannot be embedded in a frame.
- Client registration and the endpoint are rate limited.
- To disconnect one editor, remove the permission from their role or revoke their tokens (`oauth_access_tokens`, `oauth_refresh_tokens`). To disconnect everyone, rotate the Passport keys.
- Schedule `php artisan passport:purge` to clean up expired tokens.

## Limitations

- Single site: finding and creating content uses the default site. Tools that take an entry id work on that entry in its own site.
- No file uploads, no deletes, no navigation or term editing.
- Search loads the entries of the searched collections into memory, which is slow for very large collections.
- Validation errors inside sets use Statamic's field paths, e.g. `content.2.attrs.values.quote`.
- Values are normalised by their field type before validation. An integer field sent `"2abc"` stores `2`.
- The addon configures Laravel Passport for itself: guard, token lifetimes, default scope and consent screen. A site that already uses Passport for its own API will see those settings change.
- If a site already published Passport's migrations, the addon uses the published copies. They must be Passport 13's.

## License

MIT
