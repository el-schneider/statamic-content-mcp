<!-- Banner goes here: <img src="images/cm_banner.png" alt="Statamic Content MCP"> -->

# Statamic Content MCP

> Let your editors work on your Statamic site from an AI assistant. They add one URL, sign in with their Control Panel account, and the assistant reads and drafts content with exactly the permissions they already have.

"Find every page that still mentions the old opening hours and fix them." "Draft a post about Saturday's event from these notes." "Shorten the intro on the rooms page." The editor asks the assistant, checks the preview link, and says when to publish.

The addon is a standard MCP server with OAuth sign-in. It works with any MCP client that supports OAuth with dynamic client registration, and it is tested with Claude.

## Features

- **Made for editors**: Entries, globals, assets, taxonomy terms and navigations, in every site of a multi-site install. No blueprint, user, cache or system tools, so there is nothing on the other end that can break a site.
- **One URL, no tokens**: Editors add the site to their assistant as a remote MCP server and sign in through the Control Panel login. Nobody creates, copies or stores an API key.
- **Your permissions, unchanged**: Every request runs as the editor's own Statamic user and goes through Statamic's policies on the actual entry, global or asset. Custom policies, such as "authors only see their own entries", apply without extra setup.
- **Drafts first**: New entries are always drafts. Publishing is a separate tool, so the assistant asks before anything goes live. Delete tools exist only if you turn them on.
- **Preview before publish**: Every write returns a Live Preview link that renders the draft through your templates.
- **Edits one block, not the page**: The assistant can change a single field inside a page builder set. Everything it did not touch is written back byte for byte, so Git diffs show only the real change.
- **Speaks your blueprints**: Bard, Replicator, Grid and Group fields at any depth, validated by the same rules as a save in the Control Panel.
- **Sees your images**: The assistant looks at a scaled-down copy of an image, for example to write its alt text, and adds files from a URL through your containers' upload rules.
- **Your editorial voice built in**: Write tone and style guidelines in the Control Panel, and the assistant receives them in every session.
- **Run from the Control Panel**: Editors find the URL and their connections under Tools → Content MCP. Owners change the settings and see or cut every connection there, without touching a file or the database.
- **Small and standard**: OAuth by Laravel Passport, protocol by `laravel/mcp`, pages built from Statamic's own components.
- **Works with file users**: Statamic users stay where they are. Only the OAuth tables need a database, and SQLite is enough.

## Requirements

- PHP 8.3+
- Statamic 6.31+ on Laravel 12 or 13
- A database for Passport's OAuth tables
- The PHP curl extension, for `upload_asset`
- A public HTTPS URL, because hosted assistants connect from their provider's servers

## Installation

```bash
composer require el-schneider/statamic-content-mcp
php artisan migrate
php artisan passport:keys
```

`migrate` creates Laravel Passport's tables. `passport:keys` writes the OAuth signing keys to `storage/oauth-private.key` and `storage/oauth-public.key`. Run it once per environment and keep the keys out of Git. You can set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` in the environment instead, which is easier when several servers share one site. Changing the keys disconnects every editor.

Statamic publishes the addon's Control Panel scripts to `public/vendor/statamic-content-mcp` when it runs `php artisan statamic:install`, which a Statamic site does after every `composer install` and `composer update`. Until they are published, the Content MCP pages cannot load; the rest of the Control Panel works as before. To publish them yourself: `php artisan vendor:publish --tag=statamic-content-mcp --force`.

Then give the roles that should use an assistant the permission **Use AI assistants through Content MCP** (Users → Roles). Super users always have it.

The addon works with `php artisan config:cache` and `php artisan route:cache`.

## Connecting

Editors open **Tools → Content MCP → Connect** in the Control Panel. The page shows the server URL with a copy button, the three steps, what the assistant may do on this site, and the apps they have connected, each with a Disconnect button.

The URL is the site's address plus the endpoint path:

```
https://example.com/mcp/content
```

In Claude, for example: **Settings → Connectors → Add custom connector**, and paste the URL. The assistant registers itself, opens the Statamic login, and shows a consent screen. The screen names the app, the address the editor is sent back to (for Claude this is `claude.ai`) and the account that approves. Any app can call itself by any name; the address tells you who you are really connecting.

Editors without the permission see a "No access" page instead of the consent screen.

## For site owners

Everything lives under **Tools → Content MCP**, in up to three pages. Each shows only to those who may open it, and the area itself needs **Use AI assistants through Content MCP**.

- **Connect**: the URL and the user's own connections. Needs **Use AI assistants through Content MCP**.
- **Connections**: every user's connections, with the app, its address, the user, when it first connected, when it last got a token, and whether it is active, idle or expired. Disconnect works for any of them, also for users who were deleted since. Needs **See and disconnect every user's AI app connections**.
- **Settings**: editorial guidelines, allowed apps and whether the assistant may delete. Needs Statamic's permission **Content MCP settings** (in the Addons group of the role editor). The same form is under Addons → Content MCP → Settings.

Passport records when it issues a token, not each request, so "last token issued" tells you when the app last renewed its access, which happens about once an hour while it is in use.

A typical setup: editors get **Use AI assistants through Content MCP** next to their content permissions; the person who runs the site gets all three.

## Configuration

The config file holds the defaults. A value saved on the Settings page wins, and an emptied field falls back to the file again. Values saved in the Control Panel are stored in `resources/addons/statamic-content-mcp.yaml`, so they travel with your content in Git.

```bash
php artisan vendor:publish --tag=content-mcp-config
```

```php
// config/statamic/content-mcp.php
return [
    'route' => 'mcp/content',
    'instructions' => 'We write in plain, friendly English. Headlines in sentence case. No exclamation marks.',
    'redirect_domains' => ['https://claude.ai', 'https://claude.com'],
    'uploads' => ['max_size' => 10 * 1024 * 1024],
    'deletes' => false,
];
```

- `route` is the path of the endpoint. File only.
- `instructions` holds your editorial guidelines (Settings: Editorial guidelines). The assistant receives them at the start of every session and in `get_overview`. Field `instructions` in your blueprints reach it too, through `get_blueprint`.
- `redirect_domains` lists where OAuth clients may send the editor back after approval, which decides which apps can register at all (Settings: Allowed apps). Each entry is a scheme and host, such as `https://claude.ai`. The default allows only Claude. To connect a desktop or command-line tool on the editor's own computer, add `http://localhost` (any port). If your app has its own `config/mcp.php` (the config of `laravel/mcp`), its `redirect_domains` is used, and the Settings page shows that value read-only with a note.
- `uploads.max_size` is the largest file in bytes `upload_asset` downloads. The download stops as soon as it is exceeded. Your containers' validation rules apply as well. File only.
- `deletes` offers `delete_entry`, `delete_term` and `delete_asset` (Settings: Allow deleting). It is off by default: content the assistant reads, such as a page or an uploaded document, can contain text that tries to steer it, and a delete cannot be undone. Editors still need the delete permission on each item.

## Permissions

Every request runs as the editor who approved the connection. `User::current()`, `auth()->user()` and Laravel's Gate all return that user, so your policies, event listeners and `updated_by` behave as they do in the Control Panel.

- An entry is listed or returned only if `can('view', $entry)`, changed only if `can('update', $entry)`, published only if `can('publish', $entry)`, deleted only if `can('delete', $entry)`. The same holds for globals, assets, terms and navigations. Moving pages needs the collection's reorder permission, uploading the container's upload permission.
- On a multi-site install, the assistant works only in the sites the editor may access.
- Content the editor may not see is reported as not found.
- Read-only and computed fields cannot be changed, also inside groups, grids and sets.
- Editors without "edit other authors' entries" cannot change the author.
- `published`, `id`, `blueprint` and the last-modified attributes are never taken from field data, even if a blueprint has fields with those names.
- The permission **Use AI assistants through Content MCP** is checked on every request. Removing it from a role cuts access at once, also for existing connections.

To give the assistant less than an editor has, connect with a dedicated user and a narrower role.

## Tools

Read:

- `get_overview`: the sites, the acting editor, what they may access, and your guidelines
- `get_blueprint`: the fields of an entry, term, global set, navigation or asset blueprint, and the shape each value must have
- `find_entries`: list a collection, or search titles, slugs and content
- `get_entry`: one entry's meta and field values
- `get_global`: the values of a global set
- `find_assets`: search files by folder, filename or alt text
- `get_asset`: one file's meta and data, and for images a scaled-down copy to look at
- `list_terms`: the terms of a taxonomy, or those matching a search
- `get_term`: one term's values
- `get_navigation`: the tree of a navigation

Write:

- `create_entry`: create an entry, always as a draft
- `update_entry`: change field values of an entry
- `publish_entry`: publish an entry
- `unpublish_entry`: turn an entry back into a draft
- `move_entry`: move a page in a collection's page tree
- `localize_entry`: create an entry's version for another site (multi-site installs only)
- `update_global`: change values of a global set
- `create_term`, `update_term`: add or change taxonomy terms
- `update_asset`: change a file's data, such as its alt text
- `upload_asset`: add a file from a public URL
- `update_navigation`: change the tree of a navigation

Delete (only with deleting allowed):

- `delete_entry`, `delete_term`, `delete_asset`

Read tools are annotated as read-only, so an editor can allow them once and still be asked for every write.

## Drafts, previews and publishing

- `create_entry` always creates a draft, even for editors who may publish.
- `update_entry` never changes whether an entry is published. On a collection with revisions, changes to a published entry are saved as a working copy, as in the Control Panel. Without revisions, they are live on save.
- Every write returns the entry's `edit_url` and a `preview_url`. Anyone with the preview link can open it until its token expires (about an hour).
- Saving fires Statamic's normal events, so static caching, search indexes and Git automation work as usual. If one of your listeners cancels a save, the tool reports an error.
- Without a `slug`, `create_entry` builds one from the title in the site's language. Unique-slug rules on the blueprint still apply.
- Globals, terms, navigations, page moves and asset data have no drafts in Statamic. Changes to them are live on save.
- In a localization, the assistant reads what the entry shows, inherited values included, and changes only that localization. Fields that are not localizable belong to the origin entry, as in the Control Panel.
- `delete_entry` moves child pages up to the deleted page's parent, as the Control Panel does. An entry with versions in other sites needs a choice: delete them too, or keep them as independent entries.

## Data format

Values travel in the form Statamic stores them in the content files. `get_blueprint` tells the assistant what each field expects: entry ids for entries fields, asset paths for assets fields, option keys for selects.

Bard is the exception. A Bard field without sets is an HTML string. A Bard field with sets is a list of blocks:

```json
[
    {
        "type": "text",
        "text": "<p>See our <a href=\"statamic://entry::a1b2\">rooms</a>.</p>"
    },
    { "type": "quote", "id": "lx3k2a", "text": "…", "cite": "…" }
]
```

Links to entries use `statamic://entry::<id>` and keep working when the entry's URL changes.

`update_entry`, `update_global`, `update_term` and `update_asset` accept dot paths to change one nested value. A segment is an object key or the `id` of a set or grid row:

```json
{ "page_builder.lx3k2a.heading": "New heading" }
```

`null` clears a field.

Validation errors use the same paths, with the `id` of a set or row, or its position for one the request adds. A number field rejects a value such as `"2abc"` instead of storing `2`.

## Security

- OAuth with PKCE (S256 only) and dynamic client registration, by Laravel Passport and `laravel/mcp`.
- Access tokens last one hour. Refresh tokens last 90 days and rotate on every use.
- Each request checks the token's signature, expiry, revocation, scope and client.
- Only clients that redirect to an allowed app can register.
- `upload_asset` downloads only from http and https URLs whose host is a domain name or a standard IP address and resolves to public addresses. It refuses private, loopback, link-local, CGNAT, multicast and reserved ranges, IPv4 and IPv6, connects to the address it checked so DNS cannot change it in between, checks every redirect the same way (three at most), and stops a download at `uploads.max_size`. The file then goes through Statamic's own upload: allowed extensions, the container's rules, SVG sanitising, safe filenames and events. An existing file is never replaced.
- Delete tools are off unless deleting is allowed, and need the delete permission per item.
- The consent screen cannot be embedded in a frame.
- Client registration and the endpoint are rate limited.
- To disconnect one app, use Disconnect on the Connect or Connections page: its tokens are revoked and its next request fails, so it has to connect and be approved again. To cut an editor off entirely, remove the permission from their role. To disconnect everyone, rotate the Passport keys.
- Schedule `php artisan passport:purge` to clean up expired and revoked tokens.

## Limitations

- The assistant cannot upload files attached to the chat, only files the site can download from a URL. Assets cannot be moved or renamed.
- A content search reads every entry of the searched collections, a chunk at a time. On very large sites it is slow. Listing without a search stops as soon as a page is full. Lists report `has_more` instead of a total.
- No forms or users.
- Every registration attempt of an app creates an OAuth client row, also when the editor never approves. `passport:purge` removes old tokens, not these clients.
- The addon configures Laravel Passport for itself: guard, token lifetimes, default scope and consent screen. A site that already uses Passport for its own API will see those settings change.
- If a site already published Passport's migrations, the addon uses the published copies. They must be Passport 13's.

## License

MIT
