# AGENTS.md

## Commands

```bash
composer ci:check   # everything CI checks: npm run check, npm run types:check, then composer check
composer check      # PHP gate: lint:check (pint --test), types:check (phpstan level 8), test (pest)
composer lint       # pint
npm run check:fix   # oxfmt + oxlint through Vite+
npm run build       # rebuild resources/dist; commit it (see below)
uv run scripts/oauth-e2e.py https://site.example [email password]   # walks the OAuth + MCP flow against a running site, as a Claude connector does
```

## Landmines

- Laravel's Gate guesses `Statamic\Policies\EntryPolicy` for `Statamic\Entries\Entry` before it looks at policies bound to the contract. Bind test policies to the concrete class.
- Passport 13 only publishes its migrations. The service provider loads them from vendor unless `database/migrations/*_create_oauth_clients_table.php` exists.
- `passport.guard` is set in a `booting` callback: Statamic may merge its config after our `register()`.
- The consent middleware is attached to Passport's `passport.authorizations.authorize` route in `booted` and skipped when routes are cached. `RouteCacheTest` proves the cached routes still carry it.
- laravel/mcp flattens `ValidationException` messages and drops field names. `ContentTool` catches it to keep them.
- Statamic's process step normalises stored values (`enabled: true` on sets, `textAlign` on Bard nodes). Writes only touch changed fields and run through `AgentForm::keepUnchanged()`; the byte-identity tests in `AgentFormTest` guard this.
- A date field defaulting to `now` fails Statamic's date validation unless resolved first (`ContentWriter::withNowDefaults()`).
- `Field::preProcess()` replaces `null` with the field default, nested too, and `Group::process()` drops keys the blueprint lacks. `AgentForm::keepInputKeys()` undoes both, before validation (nulls) and after processing (nulls and unknown keys).
- Row ids: stored replicator/grid rows use `id`, preprocessed ones `_id`, Bard set nodes `attrs.id`. `AgentForm::rowId()` reads all three.
- Entry data is merged over the entry's own attributes in the file (`Entry::fileData()`), and `published` in data decides publication on reload. `ContentWriter::ENTRY_ATTRIBUTES` are never taken from data.
- Read-only rules hold at every depth and only for changed values (`AgentForm::readOnlyChanges()`), so clients may resend what they read.
- `save()` returns false when a saving listener vetoes. `Entry::store()` hides that behind the revision's result and `Entry::unpublish()` ignores it without revisions, so `ContentWriter` and `UnpublishEntry` check the saves themselves.
- The CP derives the slug from the title in the browser; `create_entry` does it server-side before validation, and as a closure after the data for auto-generated titles.
- league/oauth2-server accepts PKCE `plain` and treats a missing method as plain; `EnsureEditorCanConsent` requires S256. It also handles `prompt=login` with Passport's `promptedForLogin` session flag, since Passport's own path redirects to a `login` route Statamic lacks.
- `mcp.redirect_domains` (laravel/mcp default `['*']`) is set from our config only when `config/mcp.php` does not exist. A file check, because with `config:cache` the cached value no longer shows who set it.
- Tests that veto saves with `Event::listen(…Saving…)` must create their fixtures first, or the fixture save is vetoed too.
- Statamic dispatches saving events halting: a listener that returns anything but null stops the rest. Test listeners that change the item must return nothing.
- Guzzle hands `stream => true` requests to its StreamHandler, which ignores `curl` options, so `CURLOPT_RESOLVE` (the pin against DNS rebinding) would silently do nothing. `RemoteFile` streams into a sink whose `write()` throws past the size cap; that aborts curl's transfer. Its tests inject a resolver and a Guzzle `MockHandler`, not `Http::fake()` (whose sink handling breaks on stream objects).
- `LocalizedTerm::origin()` returns the default-site term even for the default site itself. Walk origin chains until `isRoot()`, never until `origin() === null`.
- A cloned tree keeps its cached flattened pages; `Trees::assertUniqueUris()` flushes them before it reads URIs.
- Collection trees and their entry ids are Blink-cached per request. A cancelled tree save would leave the changed tree in that cache, so `Trees::save()` restores it; tests that read a tree after deleting entries flush Blink.
- In tests the Stache item cache hands out the same object, so a renamed term is still found under its old key. Assert on the files.
- `Asset::delete()` returns false when vetoed, although its docblock says `$this`.
- `statamic.assets.lowercase` is on by default: uploaded filenames are lowercased.
- The CP authorizes saving with `store`/`update`, its forms with `create`/`edit`. Write tools check the saving ability (and `create` too, for new items).
- curl reads numeric host spellings (`2130706433`, `0x7f.1`) as IP addresses and ignores `CURLOPT_RESOLVE` for them. `RemoteFile` accepts only standard IP literals and plain ASCII domain names.
- laravel/mcp pages `tools/list` at 15 by default; `ContentMcpServer::$defaultPaginationLength` keeps all tools in one page.
- An empty PHP array serialises as `[]`; maps in responses go through `AgentForm::object()`.
- Pest reports warnings that `@` suppresses.
- A killed test run (e.g. output piped into `head`) can make the next run fail at random. `TestCase::setUp()` clears the fake Stache directory and RouteCacheTest's route cache, but not everything; rerun before you chase such a failure.
- Statamic publishes an addon's build after `statamic:install` only if `$vite` (or `$scripts`, …) is set on the provider; calling `registerVite()` yourself skips that. `bootVite()` registers the build only once its manifest is published, because Laravel's Vite throws on a missing manifest on every CP page. The published copy in tests lands in the skeleton's `public/vendor`; `TestCase::setUp()` deletes it.
- The committed build in `resources/dist` embeds the export list of `@statamic/cms` (it is a symlink to `vendor/statamic/cms/resources/dist-package`), which changes between Statamic versions; rolldown keeps every destructured name. Build with the lowest dependencies, as CI does: `composer update --prefer-lowest && npm run build`, then `composer update`.
- Owner settings: read through `Settings`, which takes the saved value from `raw()`. Statamic's `Addons\Settings` parses every value and default as Antlers when it is constructed (also on save, also for `raw()` readers), so the provider binds `SettingsFile` for our addon when Statamic's file repository is in use. The settings blueprint is a closure so its labels follow the CP locale and its defaults the config file (`Settings::defaults()`). `SettingsFile::fileData()` stores only values that differ from those defaults; a missing key means "use the config file", which is also what the form shows. The blueprint binding is scoped, so in a test that changes config the form keeps the old defaults; compare against `Settings::defaults()`, not the blueprint.
- `mcp.redirect_domains` is applied per request by `ApplyAllowedApps` on the OAuth routes, not at boot, because the effective value can come from the settings file.
- Settings saved in tests land in the Testbench skeleton's `resources/addons`; `TestCase::setUp()` deletes the file.
- In tests, a second `connectClaude()` must reset Passport's authorize route controller (it keeps the guard, with the first user), and every `mcp()` call restores the default guard that `AuthenticateBearerToken` switches. Separate PHP requests in production start fresh.
- `Nav::build()` is mocked by AddonTestCase after the addon extended the real nav; test the nav by extending a fresh `Statamic\CP\Navigation\Nav` with `ContentMcpServiceProvider::navigation()`.
- Statamic answers an authorization failure on a CP page load with a redirect and an error toast, a JSON request with 403. Tests use `getJson()` to see the 403.
- Statamic's entry query contract is an empty interface; `whereIn()` on `Entry::query()` needs a phpstan ignore.
- Pest 5 needs PHP 8.4; `require-dev` allows Pest 4 so CI can test PHP 8.3.
- Larastan does not resolve real-time facades (`Facades\…`); use `app(Class::class)`.
- Tests: all Stache stores and the roles file point to `tests/__fixtures__/dev-null`, wiped after each test. Create content in the test; only blueprints are fixtures. `APP_DEBUG` is on so laravel/mcp shows exception messages.
