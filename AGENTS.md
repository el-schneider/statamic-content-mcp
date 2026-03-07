# AGENTS.md

## Project Structure

This is a Statamic addon. Key paths:

- `src/` — PHP source (ServiceProvider, Commands, etc.)
- `config/statamic/` — Publishable config
- `resources/views/` — Blade views
- `resources/js/` — Frontend assets (if applicable)
- `resources/dist/` — Built assets (committed)
- `routes/` — CP and action routes
- `tests/` — Pest test suites

## Development

```bash
composer test          # Run tests
composer pint          # Fix code style
composer ci            # Pint check + tests
npm run check          # Prettier + ESLint + Pint (if frontend)
npm run fix            # Auto-fix all
npm run build          # Vite build (if frontend)
```

## Conventions

- All PHP classes use `declare(strict_types=1)` and `final`
- Pint with strict Laravel preset (see pint.json)
- Pest for testing
- Frontend (when present): Vite + TypeScript + ESLint + Prettier
