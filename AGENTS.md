# AGENTS.md

Workflow, review and commit rules live in the repository root `AGENTS.md` when this addon sits inside a site; that file wins on conflicts.

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
- Pest 5 needs PHP 8.4; `require-dev` allows Pest 4 so CI can test PHP 8.3.
- Larastan does not resolve real-time facades (`Facades\…`); use `app(Class::class)`.
- Tests: all Stache stores and the roles file point to `tests/__fixtures__/dev-null`, wiped after each test. Create content in the test; only blueprints are fixtures. `APP_DEBUG` is on so laravel/mcp shows exception messages.
