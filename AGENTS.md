# AGENTS.md

This file adds **addon-local** guidance only.

- Workflow, review cadence, retry rules, verification policy, and task tracking live in the repository root `AGENTS.md`.
- If process guidance here conflicts with the root file, follow the **root `AGENTS.md`**.

## Project Structure

This is a Statamic addon. Key paths:

- `src/` — PHP source (service provider, tools, support classes)
- `config/` — publishable addon config
- `resources/views/` — Blade views
- `resources/js/` — frontend assets, if added later
- `resources/dist/` — built assets, if frontend is added and committed
- `routes/` — CP and action routes
- `tests/` — Pest test suites

## Canonical Commands

Run these from `addons/statamic-content-mcp/`:

```bash
composer ci
composer test
composer pint
```

Use direct binaries only when a task needs exact flags not covered by the Composer scripts.

## Conventions

- All PHP classes use `declare(strict_types=1)`.
- Prefer `final` classes unless the framework requires extension.
- Pest is the default test framework.
- Match surrounding Laravel/Statamic style and keep changes minimal.
- Frontend tooling only matters if frontend assets are introduced. Do not invent a frontend build pipeline preemptively.
