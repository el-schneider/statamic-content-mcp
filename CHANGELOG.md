# Changelog

All notable changes to `statamic-content-mcp` will be documented in this file.

## Unreleased

First release. Editors connect Claude (web, desktop, mobile) to their Statamic site as a custom connector and work on content with their own Control Panel permissions.

### Added

- OAuth sign-in through Laravel Passport and `laravel/mcp`: dynamic client registration limited to Claude by default (`redirect_domains`), PKCE with S256, Statamic Control Panel login, a consent screen that shows the redirect host, rotating refresh tokens. Works with file-based users.
- Permission "Connect Claude via Content MCP", checked on every request.
- Every request runs as the approving editor; all access goes through Statamic's policies, including policies a site replaces. Read-only fields are protected at every depth, and entry attributes such as `published` are never taken from field data.
- Tools: `get_overview`, `get_blueprint`, `find_entries`, `get_entry`, `get_global`, `find_assets`, `list_terms`, `create_entry`, `update_entry`, `publish_entry`, `unpublish_entry`, `update_global`.
- New entries are always drafts, with a slug from the title when none is given. Write tools return the Control Panel edit link and a Live Preview link. Revisions are respected, and saves cancelled by a site listener are reported as errors.
- Values in Statamic's stored form; Bard as HTML or blocks with `statamic://` links preserved. Dot paths patch nested values, `null` clears a field, and unchanged content is written back byte-identical.
- Config `instructions` for the site's editorial guidelines.
