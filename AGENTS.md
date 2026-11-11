# AGENTS.md

## Commands

```bash
composer check   # pint --test, phpstan (level 8), pest
composer fix     # pint
composer test    # pest
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
- Pest 5 needs PHP 8.4; `require-dev` allows Pest 4 so CI can test PHP 8.3.
- Larastan does not resolve real-time facades (`Facades\…`); use `app(Class::class)`.
- Tests: all Stache stores and the roles file point to `tests/__fixtures__/dev-null`, wiped after each test. Create content in the test; only blueprints are fixtures. `APP_DEBUG` is on so laravel/mcp shows exception messages.
