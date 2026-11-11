# Changelog

All notable changes to `statamic-content-mcp` will be documented in this file.

## Unreleased

Version 2 is a rewrite for editors who connect Claude (web, desktop, mobile) to their site as a custom connector.

### Added

- OAuth sign-in through Laravel Passport and `laravel/mcp`: dynamic client registration, PKCE, Statamic Control Panel login, a consent screen that shows the redirect host, rotating refresh tokens. Works with file-based users.
- Permission "Connect Claude via Content MCP", checked on every request.
- Every request runs as the approving editor; all access goes through Statamic's policies, including policies a site replaces.
- Tools: `get_overview`, `get_blueprint`, `find_entries`, `get_entry`, `get_global`, `find_assets`, `list_terms`, `create_entry`, `update_entry`, `publish_entry`, `unpublish_entry`, `update_global`.
- New entries are always drafts. Write tools return the Control Panel edit link and a Live Preview link. Revisions are respected.
- Values in Statamic's stored form; Bard as HTML or blocks with `statamic://` links preserved. Dot paths patch nested values, and unchanged content is written back byte-identical.
- Config `instructions` for the site's editorial guidelines.

### Removed

- Stdio transport, Sanctum and static-token authentication, the token generator command.
- Markdown conversion for Bard, action-multiplexed tools, the collection allowlist and per-tool config.
- Control Panel assets and the frontend build.
