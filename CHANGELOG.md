# Changelog

All notable changes to `statamic-content-mcp` will be documented in this file.

## Unreleased

### Added

- Tools → Content MCP in the Control Panel. **Connect** shows editors the server URL with a copy button, the steps, what the assistant may do, and their own connections with Disconnect. **Connections** lists every user's connections (app, address, user, first connected, last token issued, state) for users with the new permission **See and disconnect every user's AI app connections**, including those of deleted users, and disconnects any of them. **Settings** edits the settings below.
- Settings in the Control Panel, stored as Statamic addon settings: editorial guidelines, allowed apps and allow deleting. The config file holds the defaults; a saved value wins.
- A frontend build (Vite+, TypeScript, Statamic's Vue components), committed in `resources/dist`.

### Changed

- The addon describes itself without naming one client: it works with any MCP client that supports OAuth with dynamic client registration, and is tested with Claude. The permission is labelled **Use AI assistants through Content MCP**; its handle `access content mcp` stays. Only Claude may register by default, as before.
- Composer scripts follow Laravel's starter kits: `lint`, `lint:check`, `types:check`, `test`, `check` (the PHP gate) and `ci:check` (frontend and PHP). `composer fix` is `composer lint`.

## v0.2.0

### Added

- Multi-site: `get_overview` lists the sites the editor may access and where collections, globals and navigations exist. `find_entries`, `create_entry`, `get_global`, `update_global` and the term and navigation tools take a `site`. `get_entry` returns `site`, `origin`, `localizations` and the fields a localization sets itself; writes to a localization stay in it, and non-localizable fields belong to the origin.
- `localize_entry`, on multi-site installs.
- `get_asset` with a scaled-down image for the model to look at, `update_asset`, and `upload_asset`, which downloads from a public URL with SSRF protection and Statamic's own upload rules. Config `uploads.max_size`.
- `get_term`, `create_term`, `update_term`.
- `get_navigation`, `update_navigation`, `move_entry`.
- `get_blueprint` for taxonomies, navigations and asset containers.
- Opt-in `delete_entry`, `delete_term`, `delete_asset` (config `deletes`).
- `find_assets` takes a `folder`; `list_terms` takes `query`, `limit` and `offset`.

### Changed

- `find_entries`, `find_assets` and `list_terms` return `has_more` instead of `total`, and read entries, files and terms in chunks instead of all at once.
- Validation errors inside sets, grids, groups and Bard use the paths the client writes (`page_builder.<id>.heading`), with the position for items sent without an id.
- Integer and float fields reject input such as `"2abc"` instead of storing `2`.
- `get_global` and `update_global` return what a localized global set resolves to.
- `tools/list` returns every tool in one page.

### Fixed

- `create_entry` and `update_entry` check the abilities the Control Panel checks when it saves (`store`, `update`), so a site policy that denies only those applies.
- An update that changes nothing no longer rewrites the file or fires save events.
- An empty `data` map is sent as a JSON object, not as an empty list.

## v0.1.0

First release. Editors connect Claude (web, desktop, mobile) to their Statamic site as a custom connector and work on content with their own Control Panel permissions.

### Added

- OAuth sign-in through Laravel Passport and `laravel/mcp`: dynamic client registration limited to Claude by default (`redirect_domains`), PKCE with S256, Statamic Control Panel login, a consent screen that shows the redirect host, rotating refresh tokens. Works with file-based users.
- Permission "Connect Claude via Content MCP", checked on every request.
- Every request runs as the approving editor; all access goes through Statamic's policies, including policies a site replaces. Read-only fields are protected at every depth, and entry attributes such as `published` are never taken from field data.
- Tools: `get_overview`, `get_blueprint`, `find_entries`, `get_entry`, `get_global`, `find_assets`, `list_terms`, `create_entry`, `update_entry`, `publish_entry`, `unpublish_entry`, `update_global`.
- New entries are always drafts, with a slug from the title when none is given. Write tools return the Control Panel edit link and a Live Preview link. Revisions are respected, and saves cancelled by a site listener are reported as errors.
- Values in Statamic's stored form; Bard as HTML or blocks with `statamic://` links preserved. Dot paths patch nested values, `null` clears a field, and unchanged content is written back byte-identical.
- Config `instructions` for the site's editorial guidelines.
